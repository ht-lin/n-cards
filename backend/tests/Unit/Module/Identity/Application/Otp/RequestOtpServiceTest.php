<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\OtpRequestPayload;
use App\Module\Identity\Application\Otp\RequestOtpService;
use App\Module\Identity\Domain\Entity\OtpChallenge;
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
use App\Tests\Double\Identity\InMemoryUserRepository;
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
 * {@see testBothPathsDoTheSameAmountOfWork()} —— §3.8 的防枚举承重墙。
 * 那条红了就是安全回归，不是测试脆弱。
 */
#[CoversClass(RequestOtpService::class)]
final class RequestOtpServiceTest extends TestCase
{
    private const REGISTERED = 'anna@example.de';

    private const UNKNOWN = 'niemand@example.de';

    private const TTL_SECONDS = 600;

    private const RESEND_AFTER = 60;

    private const BUDGET_MS = 150;

    private const CLIENT_IP = '203.0.113.7';

    private RecordingHmacHasher $hasher;

    private InMemoryCryptoService $crypto;

    private InMemoryUserRepository $users;

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

        // 注册用户的 email_hash 必须与被测代码算出来的一致 —— 用同一个替身算。
        $this->users = new InMemoryUserRepository(
            IdentityEntities::user(emailHash: HashDigest::fromRaw($this->hasher->hash(self::REGISTERED))),
        );
        $this->hasher->reset();
    }

    // ========================================================================
    // 真实路径
    // ========================================================================

    public function testSendsExactlyOneMailToARegisteredAddress(): void
    {
        $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);

        $mail = $this->mail->only();

        self::assertSame(MailTemplate::OtpCode, $mail->template);
        self::assertSame(MailLocale::German, $mail->locale);
        // 收件人用的是**库里已有的密文**，不是新加密的一份 ——
        // 那既省一次 Vault 往返，也保证同一个用户的密文只有一个来源。
        self::assertSame(IdentityEntities::ciphertext()->toString(), $mail->recipient->toString());
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
    // decoy 路径
    // ========================================================================

    public function testSendsNothingForAnUnknownAddress(): void
    {
        $this->service()->request(self::payload(self::UNKNOWN), self::CLIENT_IP);

        self::assertSame(0, $this->mail->count(), 'An unregistered address must never receive mail (§3.8).');
    }

    public function testStillStoresADecoyChallenge(): void
    {
        $issued = $this->service()->request(self::payload(self::UNKNOWN), self::CLIENT_IP);

        $challenge = $this->challenges->lastSaved();

        self::assertTrue($challenge->isDecoy());
        self::assertTrue($challenge->id()->equals($issued->challengeId));
    }

    /**
     * OtpChallenge::decoy() 的类注释点名的要求：哑挑战也要一个**真实的随机码摘要**。
     * 常量或全零会让 decoy 在库层可辨认，而 §8.4 的数据导出可能把这个差别泄露出去。
     */
    public function testTheDecoyCodeHashIsRandomNotAConstant(): void
    {
        $first = $this->requestWith(new SequenceRandomness(ints: [111111]), self::UNKNOWN);
        $second = $this->requestWith(new SequenceRandomness(ints: [222222]), self::UNKNOWN);

        self::assertFalse($first->equals($second), 'Two decoys must not share a code hash.');
        self::assertNotSame(str_repeat("\x00", 32), $first->toRaw());
    }

    // ========================================================================
    // §3.8：两条路径不可区分 —— 本文件的重点
    // ========================================================================

    /**
     * 做功对齐。**这条红了就是安全回归**：两条路径的往返次数一旦不等，
     * 差值就是一个稳定可测的耗时差，而攻击者对同一个邮箱重复采样取中位数
     * 就能读出「这个邮箱注册过吗」。
     *
     * 特别注意 Vault 的 4 : 4 是怎么配平的（见 RequestOtpService 里那段注释）：
     * decoy 分支那次**返回值被丢弃**的 encrypt()，配的是真实路径在
     * EncryptedMailSerializer 里入队时的那次加密。有人「顺手删掉没用的变量」
     * 就会让这条红 —— 那正是它存在的意义。
     */
    public function testBothPathsDoTheSameAmountOfWork(): void
    {
        $registered = $this->measure(self::REGISTERED);
        $unknown = $this->measure(self::UNKNOWN);

        self::assertSame($registered, $unknown, 'Registered and unregistered addresses must cost the same work.');

        // 顺带把绝对值也钉住，免得两边**同时**变化时这条断言变成恒真。
        // Vault 4 次 = hmac(email) + hmac(code) + hmac(ip) + 一次加密
        // （真实路径在序列化器里，decoy 路径是那次被丢弃的 encrypt）。
        self::assertSame(
            ['vault' => 4, 'user_lookups' => 1, 'challenge_ops' => ['invalidate', 'save']],
            $registered,
        );
    }

    /**
     * 形状对齐：响应对象的字段与类型逐字相同，且**没有**任何能区分两者的字段。
     */
    public function testBothPathsReturnTheSameShape(): void
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
    #[DataProvider('bothPaths')]
    public function testEveryRequestSettlesExactlyOneBudget(string $email): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        self::assertSame([self::BUDGET_MS], $this->equalizer->budgets());
        self::assertSame(1, $this->equalizer->settleCount());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bothPaths(): iterable
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
     * ⚠️ 限流必须在**查库之前**。放到之后的话，429 的触发时刻会因为
     * 「查到了 / 没查到」而不同 —— 刚防住的信息又从限流这条路上漏出去。
     */
    public function testRateLimitingHappensBeforeTheUserIsLookedUp(): void
    {
        $this->limiter->denyWith(new RateLimitExceeded(42, 0, 'otp_request_email'));

        try {
            $this->service()->request(self::payload(self::REGISTERED), self::CLIENT_IP);
            self::fail('Expected a 429.');
        } catch (RateLimitExceeded $exception) {
            self::assertSame(42, $exception->retryAfterSeconds());
        }

        self::assertSame(0, $this->users->findByEmailHashCalls(), 'A throttled request must not touch the user table.');
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
    #[DataProvider('bothPaths')]
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
     * §14.4 的「OTP 转化率骤降」告警需要请求侧的计数（worker 侧的
     * email_send_total 看不到 decoy —— decoy 压根不入队）。
     */
    #[DataProvider('metricLabels')]
    public function testCountsEveryRequestWithABoundedLabel(string $email, string $expected): void
    {
        $this->service()->request(self::payload($email), self::CLIENT_IP);

        self::assertSame([['result' => $expected]], $this->metrics->labelsFor('otp_request_total'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function metricLabels(): iterable
    {
        yield 'registered' => [self::REGISTERED, 'issued'];
        yield 'unknown' => [self::UNKNOWN, 'decoy'];
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * 一次请求的「Vault 与 DB 往返账单」。两条路径的账单必须逐项相等。
     *
     * ⚠️ `vault` 一项是 hmac + encrypt 的**总和**，不是分开两项 —— 这是刻意的。
     * 真实路径与 decoy 路径的构成本来就不同（前者的第 4 次加密发生在
     * EncryptedMailSerializer 里、不经过这里的替身），能对齐的只有总数。
     * 单测看不到序列化器那一次，所以这里把它按「发出去了几封信」补上。
     *
     * @return array{vault: int, user_lookups: int, challenge_ops: list<string>}
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
                // 单测用的是 RecordingMailSender，走不到序列化器，所以在这儿补账。
                + $this->mail->count(),
            'user_lookups' => $this->users->findByEmailHashCalls(),
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
            $this->users,
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
