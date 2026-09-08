<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * T-109 的性能验收标准：**200 张卡一页**。
 *
 * ============================================================================
 * 这个文件测的是什么，不测什么
 * ============================================================================
 * §9.1 的预算里有两条相关的：
 *
 *   - `GET /v1/sync/bootstrap`（200 张卡）P95 ≤ 700 ms —— 那是 T-203 的端点
 *   - Vault batch decrypt（200 条）P95 ≤ 80 ms
 *
 * T-109 交付的 `GET /v1/cards?limit=200` 走的是同一条解密路径，所以它是
 * 那条预算的**先行指标**。这里断言的是**单次**耗时而不是 P95：
 * 在一台开发机的 compose 栈上跑 20 次取分位数，得到的是这台机器的噪声，
 * 不是服务的性能。真正的 P95 要在 §9.2 的负载测试里量。
 *
 * 所以这条用例的定位是**回归护栏**，不是基准测试：它挡的是
 * 「有人把批量解密改回了循环」这一类**数量级**的退化 ——
 * 200 次 Vault 往返在任何机器上都会撞穿下面这个上限，
 * 而一次往返在任何机器上都撞不穿。
 *
 * ⚠️ 上限取 §9.1 的 700 ms 而不是更紧的值：紧了就会在负载高的 CI runner 上
 * 随机红，而一条随机红的用例最终会被人加 `markTestSkipped`。
 *
 * ⚠️ 「只解一次」这条**结构性**保证由
 * `CardQueryServiceTest::testListingTwoHundredCardsIssuesExactlyOneDecryptCall()`
 * 用替身精确断言 —— 那里不受机器速度影响。两条一起才完整。
 */
#[CoversNothing]
final class CardListPerformanceTest extends WebTestCase
{
    use RequiresOtpStack;

    /** §9.1：`GET /v1/sync/bootstrap`（200 张卡）的预算。 */
    private const BUDGET_MS = 700;

    private const CARDS = 200;

    protected function setUp(): void
    {
        $this->bootOtpStack();
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    public function testTwoHundredCardsComeBackInOnePageWithinTheBudget(): void
    {
        $session = $this->registerOnboardedUser();

        $this->seedCards(Uuid::fromString($session['user_id']));

        $started = microtime(true);

        $this->client->request('GET', '/v1/cards?limit='.self::CARDS, server: [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            'REMOTE_ADDR' => self::uniqueIp(),
            'HTTP_AUTHORIZATION' => 'Bearer '.$session['access_token'],
        ]);

        $elapsedMs = (microtime(true) - $started) * 1000;
        $response = $this->client->getResponse();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($body));

        self::assertCount(self::CARDS, $body['items']);
        // 全部解开了 —— 少一张就是一次静默的数据丢失，而
        // BatchDecryptorInterface 刻意选了「整批抛」而不是返回部分结果。
        foreach ($body['items'] as $i => $item) {
            self::assertSame('code-'.$i, $item['barcode_value']);
        }

        self::assertLessThan(
            self::BUDGET_MS,
            $elapsedMs,
            \sprintf(
                "200 张卡的列表用了 %.0f ms，超过 §9.1 的 %d ms 预算。\n"
                ."最可能的原因是**批量解密被改回了循环** —— 那是 200 次 Vault 往返，\n"
                ."§5.3 明令禁止（见 BatchDecryptorInterface 的类注释）。\n"
                .'先看 CardViewAssembler 有没有多出一个 foreach。',
                $elapsedMs,
                self::BUDGET_MS,
            ),
        );
    }

    /**
     * 直接经仓储种 200 张卡。
     *
     * ⚠️ **不**走 200 次 `POST /v1/cards`：那会花掉 600 次 Vault 往返
     * （每张卡两次加密 + 一次 HMAC），把用例本身变成几十秒 ——
     * 而被测的是**读**路径。加密仍然走真实的 `CryptoServiceInterface`，
     * 所以库里躺的是真密文，读路径要真的解开它们。
     */
    private function seedCards(Uuid $owner): void
    {
        $container = static::getContainer();

        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CardRepositoryInterface $cards */
        $cards = $container->get(CardRepositoryInterface::class);

        $now = new \DateTimeImmutable('2026-09-08T12:00:00+00:00');

        for ($i = 0; $i < self::CARDS; ++$i) {
            $plaintext = 'code-'.$i;

            $cards->save(Card::create(
                // 递增的 id → 列表按 id 升序返回，于是上面可以逐条比对明文。
                Uuid::fromString(\sprintf('0192f3a1-b2c3-7d4e-8f01-%012x', $i)),
                $owner,
                'Karte '.$i,
                'REWE',
                'blue_600',
                BarcodeFormat::Ean13,
                $crypto->encrypt(CryptoKey::Card, $plaintext),
                HashDigest::fromRaw($hasher->hash($plaintext)),
                // 一半带备注 —— 让两个键前缀在批次里交错，错位会被上面的比对抓住。
                0 === $i % 2 ? $crypto->encrypt(CryptoKey::Card, 'Notiz '.$i) : null,
                null,
                $now,
            ));
        }
    }
}
