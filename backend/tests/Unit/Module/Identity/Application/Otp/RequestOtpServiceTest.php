<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\OtpRequestPayload;
use App\Module\Identity\Application\Otp\RequestOtpService;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Tests\Double\Crypto\InMemoryCryptoService;
use App\Tests\Double\Crypto\RecordingHmacHasher;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryOtpChallengeRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Notification\RecordingMailSender;
use App\Tests\Double\Random\SequenceRandomness;
use App\Tests\Double\RateLimit\RecordingRateLimiter;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Double\Timing\RecordingTimeEqualizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ⚠️ 本文件里最重要的不是「码发出去了没有」，而是
 * {@see testTheEndpointCannotTellWhetherTheAddressIsRegistered()} ——
 * §3.8 的防枚举承重墙。那条红了就是安全回归，不是测试脆弱。
 *
 * ============================================================================
 * ADR-0014 之后这组用例的形状变了
 * ============================================================================
 * T-103 交付时这里有「真实路径」与「decoy 路径」两组用例，外加一条
 * `testBothPathsDoTheSameAmountOfWork()` 断言两者的 Vault / DB 往返次数相等。
 *
 * ADR-0014 把两条路径合并成一条（**无论邮箱是否注册都真发码**，否则新用户
 * 永远收不到码、永远无法注册），于是那种「配平两边」的断言失去了对象。
 * 取而代之的断言更强也更简单：**被测类根本没有 `UserRepositoryInterface`**，
 * 它在结构上就无法根据存在性分支。
 */
#[CoversClass(RequestOtpService::class)]
final class RequestOtpServiceTest extends TestCase
{
    /**
     * 两个邮箱在**被测类眼里没有任何区别** —— 它不查 users。
     * 保留两个常量只是为了让「同一个邮箱两次」与「两个不同邮箱」可分。
     */
    private const REGISTERED = 'anna@example.de';

    private const UNKNOWN = 'niemand@example.de';

    private const TTL_SECONDS = 600;

    private const RESEND_AFTER = 60;

    private const BUDGET_MS = 150;

    private const CLIENT_IP = '203.0.113.7';

    private RecordingHmacHasher $hasher;

    private InMemoryCryptoService $crypto;

    private InMemoryOtpChallengeRepository $challenges;

    private RecordingMailSender $mail;

    private RecordingRateLimiter $limiter;

    private RecordingTimeEqualizer $equalizer;

    private RecordingMetrics $metrics;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->hasher = new RecordingHmacHasher();
        $this->crypto = new InMemoryCryptoService();
        $this->challenges = new InMemoryOtpChallengeRepository();
        $this->mail = new RecordingMailSender();
        $this->limiter = new RecordingRateLimiter();
        $this->equalizer = new RecordingTimeEqualizer();
        $this->metrics = new RecordingMetrics();
        $this->clock = new FrozenClock((new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))->getTimestamp() * 1000);
    }

    // ========================================================================
    // 发信
    // ========================================================================

    /**
     * ⚠️ 用例名里的 "any" 是重点：**未注册的邮箱也会收到码**（ADR-0014）。
     * 这正是注册路径成立的前提 —— 收不到码就永远走不到
     * `POST /auth/otp/verify`，而契约里没有第二个注册端点。
     */
    #[DataProvider('bothAddresses')]
    public function testSendsExactlyOneMailToAnyAddress(string $email): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        $mail = $this->mail->only();

        self::assertSame(MailTemplate::OtpCode, $mail->template);
        self::assertSame(MailLocale::German, $mail->locale);

        // 收件人是**当场加密的那份密文**，不是从 users 里读出来的。
        // 读 users 会同时打开两个洞：本类又知道了存在性，且已注册路径会少一次
        // Vault 往返（见 RequestOtpService 类注释里那一整节）。
        self::assertStringContainsString(base64_encode($email), $mail->recipient->toString());
    }

    /**
     * MailRequest 的构造函数要求变量集**恰好**等于 requiredVariables()，
     * 多一个少一个都当场抛。这条用例把「恰好」钉成断言，
     * 免得将来改模板时只改了 Twig 没改这里（症状是入队时 500）。
     */
    public function testMailVariablesMatchTheTemplateContractExactly(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        $variables = $this->mail->only()->variables;

        self::assertSame(MailTemplate::OtpCode->requiredVariables(), array_keys($variables));
        self::assertMatchesRegularExpression('/^\d{6}$/', $variables['code']);
        self::assertSame('10', $variables['expires_in_minutes']);
    }

    #[DataProvider('locales')]
    public function testMapsEachLocaleOntoItsMailCounterpart(string $requested, MailLocale $expected): void
    {
        $this->service()->request(self::payload(self::REGISTERED, $requested), self::CLIENT_IP);

        self::assertSame($expected, $this->mail->only()->locale);
    }

    /**
     * @return iterable<string, array{string, MailLocale}>
     */
    public static function locales(): iterable
    {
        yield 'de' => ['de', MailLocale::German];
        yield 'en' => ['en', MailLocale::English];
    }

    public function testStoresAnIssuedChallengeWithTheSpecParameters(): void
    {
        $issued = $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        $challenge = $this->challenges->lastSaved();

        self::assertFalse($challenge->isDecoy());
        // `purpose` 现在只有 Login 一个取值，断言它是恒真的（PHPStan 会直接报出来）。
        self::assertSame(0, $challenge->attempts());
        self::assertFalse($challenge->isConsumed());
        // §7.1：有效期 10 分钟。
        self::assertEquals($this->clock->now()->modify('+600 seconds'), $challenge->expiresAt());
        // 返回给客户端的 challenge_id 就是这条挑战的主键（§5.2）。
        self::assertTrue($challenge->id()->equals($issued->challengeId));
        self::assertSame(self::RESEND_AFTER, $issued->resendAfterSeconds);
        // Magic Link 归 T-106，本端点不签发。
        self::assertNull($challenge->magicTokenHash());
    }

    /**
     * §7.1「存 HMAC-SHA256(code, pepper)，**不存明文**」。
     */
    public function testStoresOnlyTheHashOfTheCode(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        $code = $this->mail->only()->variables['code'];
        $stored = $this->challenges->lastSaved()->codeHash();

        self::assertTrue($stored->equals(HashDigest::fromRaw($this->hasher->hash($code))));
        self::assertStringNotContainsString($code, $stored->toRaw());
    }

    // ========================================================================
    // 注册路径的两个输入（T-104 / ADR-0014）
    // ========================================================================

    /**
     * 挑战必须带上收件人密文与语言，否则 `VerifyOtpService` 在首次验证成功时
     * **建不出 users 行** —— 那时明文邮箱早已不在系统里，它只在本端点的
     * 请求体里活过一次。
     *
     * 这条红了的症状是「新用户能收到码、能验过，但注册失败」。
     */
    #[DataProvider('bothAddresses')]
    public function testTheChallengeCarriesWhatRegistrationWillNeed(string $email): void
    {
        $this->service()->request(self::payload($email, 'en'), self::CLIENT_IP);

        $challenge = $this->challenges->lastSaved();

        self::assertNotNull($challenge->emailEncrypted());
        self::assertStringContainsString(base64_encode($email), $challenge->emailEncrypted()->toString());
        // 语言取自请求，不是默认值 —— 收到英文码信却拿到一个 locale=de 的账号
        // 是用户能看见的 bug。
        self::assertSame(Locale::English, $challenge->locale());
    }

    /**
     * 存进挑战的密文与发信用的收件人是**同一个对象** —— 不是加密两次。
     *
     * 加密两次不会有功能症状（Transit 是非确定性的，两份密文都能解开），
     * 但会凭空多一次 Vault 往返，而这个端点的每一次往返都要付在登录延迟上。
     */
    public function testTheStoredCiphertextIsTheOneTheMailWasSentTo(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        self::assertSame(
            $this->challenges->lastSaved()->emailEncrypted()?->toString(),
            $this->mail->only()->recipient->toString(),
        );
    }

    /**
     * 码摘要必须是**真实的随机摘要**，不能是常量或全零。
     *
     * 这条继承自 T-103 对哑挑战的要求（可预测的值会让某一类挑战在库层可辨认，
     * 而 §8.4 的数据导出与运维查询都可能把那个差别泄露出去）。
     * ADR-0014 之后已经没有「另一类挑战」了，但这条性质本身仍然要成立。
     */
    public function testTheCodeHashIsRandomNotAConstant(): void
    {
        $first = $this->requestWith(new SequenceRandomness(ints: [111111]), self::UNKNOWN);
        $second = $this->requestWith(new SequenceRandomness(ints: [222222]), self::UNKNOWN);

        self::assertFalse($first->equals($second), 'Two challenges must not share a code hash.');
        self::assertNotSame(str_repeat("\x00", 32), $first->toRaw());
    }

    // ========================================================================
    // §3.8：两条路径不可区分 —— 本文件的重点
    // ========================================================================

    /**
     * ⚠️ **本文件最重要的一条。** 红了就是安全回归。
     *
     * ADR-0014 之前这条断言的形式是「两条路径的往返次数相等」——
     * 一个必须靠人肉维护、且配错了没有任何症状的账。现在它变成了一条**结构性质**：
     * 被测类的构造签名里根本没有任何能查用户的东西，所以它无法分支。
     *
     * 用反射而不是「读一遍代码」：反射会在有人重新注入 `UserRepositoryInterface`
     * （或任何别的用户查询出口）的那一刻当场红，哪怕他只是想「顺手做个优化」。
     */
    public function testTheEndpointCannotTellWhetherTheAddressIsRegistered(): void
    {
        $parameters = (new \ReflectionClass(RequestOtpService::class))->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn (\ReflectionParameter $p): string => (string) $p->getType(),
            $parameters,
        );

        self::assertNotContains(
            UserRepositoryInterface::class,
            $types,
            'RequestOtpService must not be able to look users up (§3.8 / ADR-0014).',
        );
    }

    /**
     * 做功对齐的**残余**断言：两个邮箱的账单仍然要逐项相等。
     *
     * 今天它几乎是恒真的（没有分支就没有可分的两边），留着是因为它把绝对值
     * 也钉住了 —— 谁往请求路径上加一次 Vault 或 DB 往返，这条会红，
     * 而那正是该去重新读一遍 ncards.otp.request_budget_ms 那段校准注释的时刻。
     */
    public function testEveryRequestCostsTheSameWork(): void
    {
        $registered = $this->measure(self::REGISTERED);
        $unknown = $this->measure(self::UNKNOWN);

        self::assertSame($registered, $unknown, 'Every address must cost the same work.');

        // Vault 5 次 = hmac(email) + hmac(code) + hmac(ip) + encrypt(收件人)
        //            + EncryptedMailSerializer 入队时的那一次。
        self::assertSame(
            ['vault' => 5, 'challenge_ops' => ['invalidate', 'save']],
            $registered,
        );
    }

    /**
     * 形状对齐：响应对象的字段与类型逐字相同，且**没有**任何能区分两者的字段。
     */
    public function testBothAddressesReturnTheSameShape(): void
    {
        // 同一个 service 实例连发两次 —— 换成两个实例的话，各自的 uuid 生成器
        // 都从头开始，「每次请求拿到自己的 id」那条断言会因为夹具而假绿。
        $service = $this->service();

        $registered = $service->request(self::payload(self::REGISTERED), self::CLIENT_IP);
        $unknown = $service->request(self::payload(self::UNKNOWN), self::CLIENT_IP);

        self::assertSame(
            array_keys(get_object_vars($registered)),
            array_keys(get_object_vars($unknown)),
        );
        self::assertSame($registered->resendAfterSeconds, $unknown->resendAfterSeconds);
        self::assertEquals($registered->expiresAt, $unknown->expiresAt);
        self::assertFalse($registered->challengeId->equals($unknown->challengeId), 'Each request gets its own id.');
    }

    /**
     * 耗时对齐：每次请求都必须领一个预算并结算它。
     * 漏调 = 填充没发生（防线失效）；多调 = 预算翻倍（反而制造出新的可分耗时）。
     */
    #[DataProvider('bothAddresses')]
    public function testEveryRequestSettlesExactlyOneBudget(string $email): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        self::assertSame([self::BUDGET_MS], $this->equalizer->budgets());
        self::assertSame(1, $this->equalizer->settleCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothAddresses(): iterable
    {
        yield 'registered' => [self::REGISTERED];
        yield 'unknown' => [self::UNKNOWN];
    }

    // ========================================================================
    // 限流
    // ========================================================================

    /**
     * ADR-0005 的「全过才扣」：必须是**一次** consumeAll，不是两次 consume。
     * 分开扣的话，一个烧光某出口 IP 配额的攻击者会顺带扣掉他试过的每个邮箱的
     * 1/min —— 受害者登不进去，而他自己什么也没多得到。
     */
    public function testChecksBothDimensionsInASingleAllOrNothingBatch(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        self::assertCount(1, $this->limiter->batches(), 'Both dimensions must go through one consumeAll().');

        $subjects = $this->limiter->lastBatch();
        self::assertSame(['otp_request_email', 'otp_request_ip'], array_keys($subjects));

        // 前缀不能省：不带 `email:` / `ip:` 的话两个维度会共用计数桶。
        self::assertStringStartsWith('email:', $subjects['otp_request_email']);
        self::assertSame('ip:'.self::CLIENT_IP, $subjects['otp_request_ip']);
        // 主体里放的是摘要的 hex，不是明文邮箱 —— 限流键不该携带个人数据。
        self::assertStringNotContainsString(self::REGISTERED, $subjects['otp_request_email']);
    }

    /**
     * ⚠️ 限流必须在**任何写库与发信之前**。
     *
     * ADR-0014 让本端点对任意邮箱都真发信，于是 §7.5 的 1/min、5/h、10/day
     * 从「重要」变成了**唯一**的滥用闸门 —— 它挡的是「用我们的域名给别人的
     * 收件箱发信」（§7.2 T11）。被限住的请求必须一封信都不发、一行库都不写。
     */
    public function testAThrottledRequestNeitherWritesNorSends(): void
    {
        $this->limiter->denyWith(new RateLimitExceeded(42, 0, 'otp_request_email'));

        try {
            $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);
            self::fail('Expected a 429.');
        } catch (RateLimitExceeded $exception) {
            self::assertSame(42, $exception->retryAfterSeconds());
        }

        self::assertSame([], $this->challenges->operations());
        self::assertSame(0, $this->mail->count());
        // 早于分支点抛出的异常不携带存在性信息，所以不填充 ——
        // 填充只会白白占住 PHP-FPM 的 worker。
        self::assertSame(0, $this->equalizer->settleCount());
    }

    /**
     * IP 取不到时回落到固定串而**不是跳过限流** —— 跳过等于给任何能造出
     * 这种请求的调用方开一个绕过 §7.5 的后门。
     */
    public function testFallsBackToAPlaceholderSubjectWhenTheIpIsUnknown(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), null);

        self::assertSame('ip:unknown', $this->limiter->lastBatch()['otp_request_ip']);
    }

    // ========================================================================
    // 其它
    // ========================================================================

    /**
     * §7.1「新建时作废该 email 的旧 challenge」，且顺序必须是先作废后落盘 ——
     * 反过来会把刚建的那条一起作废，用户拿到的码当场失效。
     */
    #[DataProvider('bothAddresses')]
    public function testInvalidatesPreviousChallengesBeforeSavingTheNewOne(string $email): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        self::assertSame(['invalidate', 'save'], $this->challenges->operations());
    }

    /**
     * `random_int(0, 999999)` 有百万分之一的概率给出 7，而用户要输的是 `000007`。
     * 少了 str_pad，那些码在客户端侧永远对不上。
     */
    public function testPadsShortCodesWithLeadingZeros(): void
    {
        $this->service(new SequenceRandomness(ints: [7]))->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        self::assertSame('000007', $this->mail->only()->variables['code']);
    }

    public function testAsksTheRandomSourceForTheFullSixDigitRange(): void
    {
        $random = new class implements \App\Shared\Domain\Random\RandomnessInterface {
            /** @var list<array{int, int}> */
            public array $ranges = [];

            public function bytes(int $length): string
            {
                return str_repeat("\x00", $length);
            }

            public function int(int $min, int $max): int
            {
                $this->ranges[] = [$min, $max];

                return $min;
            }
        };

        $this->service($random)->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        self::assertSame([[0, 999999]], $random->ranges);
    }

    /**
     * §14.4 的「OTP 转化率骤降」告警需要请求侧的计数。
     *
     * ⚠️ 标签值对两个邮箱**必须相同**。ADR-0014 之前它是 `issued` / `decoy`，
     * 而那个区分现在既做不到（本类不知道答案）也不该做 ——
     * 一个按存在性切分的计数器，等于把防住的信息导出到了指标后端。
     */
    #[DataProvider('bothAddresses')]
    public function testCountsEveryRequestWithTheSameBoundedLabel(string $email): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        self::assertSame([['result' => 'issued']], $this->metrics->labelsFor('otp_request_total'));
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * 一次请求的「Vault 与 DB 往返账单」。
     *
     * ⚠️ `vault` 一项是 hmac + encrypt 的**总和**，不是分开两项：入队时
     * `EncryptedMailSerializer` 的那次加密不经过这里的替身（单测用的是
     * RecordingMailSender），所以按「发出去了几封信」补账。
     *
     * @return array{vault: int, challenge_ops: list<string>}
     */
    private function measure(string $email): array
    {
        $this->setUp();

        $this->service()->request(self::payload($email), self::CLIENT_IP);

        return [
            'vault' => $this->hasher->callCount()
                + $this->crypto->encryptCalls()
                // 每封入队的信在 EncryptedMailSerializer 里恰好被加密一次
                // （那是为了 messenger_messages.body 里不出现明文邮箱与明文码）。
                + $this->mail->count(),
            'challenge_ops' => $this->challenges->operations(),
        ];
    }

    private function requestWith(SequenceRandomness $random, string $email): HashDigest
    {
        $this->setUp();
        $this->service($random)->request(self::payload($email), self::CLIENT_IP);

        return $this->challenges->lastSaved()->codeHash();
    }

    private static function payload(string $email, string $locale = 'de'): OtpRequestPayload
    {
        return OtpRequestPayload::fromArray(['email' => $email, 'locale' => $locale]);
    }

    private function service(?\App\Shared\Domain\Random\RandomnessInterface $random = null): RequestOtpService
    {
        return new RequestOtpService(
            $this->challenges,
            $this->hasher,
            $this->crypto,
            $random ?? new SequenceRandomness(ints: array_fill(0, 8, 418396)),
            self::uuids(),
            $this->clock,
            $this->limiter,
            $this->equalizer,
            $this->metrics,
            $this->mail,
            codeDigits: 6,
            ttlSeconds: self::TTL_SECONDS,
            resendAfterSeconds: self::RESEND_AFTER,
            requestBudgetMillis: self::BUDGET_MS,
        );
    }

    private static function uuids(): UuidGeneratorInterface
    {
        return new class implements UuidGeneratorInterface {
            private int $nth = 0;

            public function generate(): Uuid
            {
                return IdentityEntities::id(++$this->nth + 100);
            }
        };
    }
}
