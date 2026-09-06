<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Application\Otp\RequestOtpService;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * **T-103 的验收标准那一条**：已注册与未注册的邮箱，其响应体结构、状态码与
 * 耗时分布都不可区分（§3.8）。
 *
 * ============================================================================
 * 断言分三层，强度递减
 * ============================================================================
 * 1. **响应形状**（本文件）—— 两次响应除了 id 与时间戳之外逐字节相同。
 * 2. **持久化足迹**（本文件）—— 两条路径在 `otp_challenges` 上留下的行数与列
 *    完全一样，只差 `is_decoy` 一个布尔（而那一列客户端永远看不到）。
 * 3. **耗时**（本文件的最后一条 + 单测）—— 墙钟只做**兜底**：它在共享 runner
 *    上抖动很大，容差必须开得很宽，于是它只能抓住「填充压根没生效」这一类回归。
 *    真正确定性的那条断言是
 *    `RequestOtpServiceTest::testBothPathsDoTheSameAmountOfWork()` ——
 *    它数的是 Vault 与 DB 的往返次数，不受调度抖动影响。
 *
 * 三层都要：形状挡「多返回一个字段」，足迹挡「decoy 少写了一列」，
 * 耗时挡「某条路径悄悄多了一次网络往返」。
 */
#[CoversClass(RequestOtpService::class)]
final class OtpEnumerationResistanceTest extends WebTestCase
{
    use RequiresOtpStack;

    private const PATH = '/v1/auth/otp/request';

    /**
     * 墙钟采样次数。取小是因为每次请求都要睡满耗时预算（默认 150 ms），
     * 2 × 12 次已经是 ~4 秒 —— 再多就该拆成一个单独的性能作业了。
     */
    private const SAMPLES = 12;

    private string $registeredEmail;

    protected function setUp(): void
    {
        $this->bootOtpStack();

        $this->registeredEmail = self::uniqueEmail();
        $this->registerUser($this->registeredEmail);
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    /**
     * 第一层：客户端能看到的一切都一样。
     *
     * 这条如果红了，通常是有人「顺手」在响应里加了个字段（`is_new_user`、
     * `email_sent`……）。那种字段看起来无害，但它就是账号枚举接口本身。
     */
    public function testTheTwoResponsesAreIndistinguishableToTheClient(): void
    {
        $registered = $this->requestFor($this->registeredEmail);
        $unknown = $this->requestFor(self::uniqueEmail());

        self::assertSame(Response::HTTP_ACCEPTED, $registered->getStatusCode());
        self::assertSame($registered->getStatusCode(), $unknown->getStatusCode());

        self::assertSame(
            $registered->headers->get('Content-Type'),
            $unknown->headers->get('Content-Type'),
        );

        $a = self::decode($registered);
        $b = self::decode($unknown);

        self::assertSame(array_keys($a), array_keys($b), '两条路径的字段集必须逐字相同');
        self::assertSame($a['resend_after_seconds'], $b['resend_after_seconds']);

        // 只有 challenge_id 与 expires_at 允许不同，且必须是同一种形状。
        self::assertNotSame($a['challenge_id'], $b['challenge_id']);
        self::assertSame(\strlen($a['challenge_id']), \strlen($b['challenge_id']));
        self::assertSame(\strlen($a['expires_at']), \strlen($b['expires_at']));

        // 把两处可变值抹掉之后，剩下的应该完全一致 —— 包括字段顺序
        // （JSON 的键序是稳定的，一个按分支拼装的响应体很容易在这里露馅）。
        self::assertSame(self::redact($a), self::redact($b));
    }

    /**
     * 第二层：库里也一样。
     *
     * §3.8 的哑挑战「响应体与耗时不可区分」是对外的；这条盯的是对内的一半 ——
     * decoy 必须是一条**完整**的挑战行，而不是一条填了空值的占位符。
     * 少填 `code_hash` 或 `request_ip_hash` 都会让 decoy 在库层可辨认，
     * 而 §8.4 的数据导出可能把这个差别泄露出去。
     */
    public function testBothPathsLeaveTheSameKindOfRowBehind(): void
    {
        $this->requestFor($this->registeredEmail);
        $issued = $this->lastChallengeRow();

        $this->requestFor(self::uniqueEmail());
        $decoy = $this->lastChallengeRow();

        self::assertFalse($issued['is_decoy']);
        self::assertTrue($decoy['is_decoy']);

        // is_decoy 之外的每一列都必须「同样地被填上」。
        foreach (['code_hash', 'request_ip_hash', 'expires_at', 'created_at', 'purpose'] as $column) {
            self::assertNotNull($issued[$column], $column.' 在真实路径上不该为空');
            self::assertNotNull($decoy[$column], $column.' 在 decoy 路径上不该为空 —— 空值让 decoy 在库层可辨认');
        }

        self::assertSame($issued['purpose'], $decoy['purpose']);
        self::assertSame(0, $issued['attempts']);
        self::assertSame($issued['attempts'], $decoy['attempts']);
        self::assertNull($issued['consumed_at']);
        self::assertNull($decoy['consumed_at']);

        // 摘要长度相同（都是 32 字节的 BYTEA），且**不是**同一个值 ——
        // 后者会说明 decoy 用了常量码。
        self::assertSame(\strlen($issued['code_hash']), \strlen($decoy['code_hash']));
        self::assertNotSame($issued['code_hash'], $decoy['code_hash']);

        // Magic Link 归 T-106：两条路径现在都不签发，所以这一列都得是 NULL。
        // 一旦 T-106 只给真实路径签发，这条会红 —— 那正是它该红的时候。
        self::assertNull($issued['magic_token_hash']);
        self::assertNull($decoy['magic_token_hash']);
    }

    /**
     * 第三层（兜底）：墙钟中位数落在同一个量级。
     *
     * ⚠️ **容差刻意开得很宽**。这条抓的是「填充压根没生效」——
     * 比如有人把 `settle()` 挪到了 `if` 里、或者把预算配成了 0。
     * 几毫秒量级的真实差异它抓不住，那是上面两层与单测的活。
     *
     * 用中位数而不是均值：CI 上偶发的一次 GC 或调度停顿会把均值拉到没法断言。
     */
    public function testTheTwoPathsTakeAboutTheSameTime(): void
    {
        // ⚠️ 每次采样都要用一个**没用过**的已注册邮箱。
        // `otp_request_email` 是 1/min，对同一个地址连发 12 次的话，
        // 第 2 次开始全是 429 —— 而那看起来会像是「响应形状不对」。
        // 建号本身也要打 Vault，所以必须在计时开始**之前**全部建好。
        $registeredEmails = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            $registeredEmails[] = $this->registerUser(self::uniqueEmail());
        }

        $registered = [];
        $unknown = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            // 交替发，让两组均匀地摊到同一段时间里 —— 先发完一组再发另一组的话，
            // 机器负载的自然漂移会被读成两条路径的差异。
            $registered[] = self::timeOf(fn () => $this->requestFor($registeredEmails[$i]));
            $unknown[] = self::timeOf(fn () => $this->requestFor(self::uniqueEmail()));
        }

        $budget = self::budgetMillis();
        $delta = abs(self::median($registered) - self::median($unknown));

        self::assertLessThan(
            $budget * 0.5,
            $delta,
            \sprintf(
                "两条路径的耗时中位数差了 %.1f ms（预算 %d ms）。\n".
                "这通常意味着恒定耗时填充没生效 —— 检查 RequestOtpService 里的 settle()，\n".
                '以及日志里有没有 "Constant-time budget overrun"（那说明预算需要调高）。',
                $delta,
                $budget,
            ),
        );

        // 顺带确认填充确实在起作用：两组都应该 ≥ 预算。都远低于预算的话，
        // 说明均衡器根本没被调用，而上面那条差值断言会因为「两边都很快」而假绿。
        self::assertGreaterThanOrEqual($budget * 0.8, min(self::median($registered), self::median($unknown)));
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * 用应用**自己的** HMAC 与加密门面建一个用户 —— email_hash 必须与端点
     * 稍后算出来的完全一致，否则「已注册」那条路径根本走不到。
     */
    private function registerUser(string $email): string
    {
        $container = static::getContainer();

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var UuidGeneratorInterface $uuids */
        $uuids = $container->get(UuidGeneratorInterface::class);

        $repository = new DoctrineUserRepository($this->entityManager);

        $repository->save(User::register(
            $uuids->generate(),
            HashDigest::fromRaw($hasher->hash($email)),
            $crypto->encrypt(CryptoKey::Pii, $email),
            Locale::German,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $email;
    }

    private function requestFor(string $email): Response
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                'REMOTE_ADDR' => self::uniqueIp(),
            ],
            content: json_encode(['email' => $email, 'locale' => 'de'], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // 限流把用例打成 429 时，失败信息应该说清是限流而不是「形状不对」。
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $response->getStatusCode(),
            'Expected 202, got '.$response->getStatusCode().': '.(string) $response->getContent(),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastChallengeRow(): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT is_decoy, code_hash, magic_token_hash, request_ip_hash, purpose, attempts,'
            .' expires_at, consumed_at, created_at'
            .' FROM otp_challenges ORDER BY created_at DESC, id DESC LIMIT 1',
        );

        self::assertIsArray($row, 'No challenge was stored.');

        // pdo_pgsql 把 BYTEA 读成 stream，BOOLEAN 读成 bool ——
        // 断言之前统一成标量，免得比较的是两个 resource。
        foreach (['code_hash', 'magic_token_hash', 'request_ip_hash'] as $column) {
            if (\is_resource($row[$column])) {
                $row[$column] = stream_get_contents($row[$column]);
            }
        }

        $row['is_decoy'] = (bool) $row['is_decoy'];
        $row['attempts'] = (int) $row['attempts'];

        return $row;
    }

    /**
     * 抹掉两个允许不同的字段，剩下的必须完全一致。
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function redact(array $body): array
    {
        return [...$body, 'challenge_id' => '<redacted>', 'expires_at' => '<redacted>'];
    }

    private static function budgetMillis(): int
    {
        /** @var int $budget */
        $budget = static::getContainer()->getParameter('ncards.otp.request_budget_ms');

        return $budget;
    }

    /**
     * @return float 毫秒
     */
    private static function timeOf(callable $work): float
    {
        $startedAt = hrtime(true);
        $work();

        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    /**
     * @param list<float> $samples
     */
    private static function median(array $samples): float
    {
        sort($samples);

        $middle = intdiv(\count($samples), 2);

        return 0 === \count($samples) % 2
            ? ($samples[$middle - 1] + $samples[$middle]) / 2
            : $samples[$middle];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
