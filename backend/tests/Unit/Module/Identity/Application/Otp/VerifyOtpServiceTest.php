<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\OtpVerificationPayload;
use App\Module\Identity\Application\Otp\VerifyOtpService;
use App\Module\Identity\Application\Session\SessionIssuer;
use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Tests\Double\Crypto\RecordingHmacHasher;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryDeviceRepository;
use App\Tests\Double\Identity\InMemoryOtpChallengeRepository;
use App\Tests\Double\Identity\InMemorySessionRepository;
use App\Tests\Double\Identity\InMemoryUserRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Notification\RecordingMailSender;
use App\Tests\Double\Random\SequenceRandomness;
use App\Tests\Double\RateLimit\RecordingRateLimiter;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Double\Timing\RecordingTimeEqualizer;
use App\Tests\Double\Token\RecordingAccessTokenSigner;
use App\Tests\Double\Transaction\RecordingTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ⚠️ 本文件里最重要的一条是
 * {@see testEveryRejectionShapeDoesTheSameAmountOfWork()} ——
 * §3.8 的防枚举在 verify 这一侧的承重墙。它红了就是安全回归。
 *
 * 它原本还有一个搭档 `testADecoyIsRejectedEvenWhenTheCodeHashMatches()`，
 * 钉的是「`isDecoy()` 必须排在 `hash_equals` 之后」。T-113 摘掉了那次判断
 * （ADR-0014 之后哑挑战不再存在，理由见 VerifyOtpService 布尔链上的注释），
 * 于是那条用例连同它要保护的次序一起退休了 —— 现在布尔链里**没有任何一项**
 * 与「这个邮箱注册过吗」相关，这比配平次序更强。
 *
 * 其余的用例分成三组：拒绝路径的判据（过期 / 已用 / 次数）、
 * 成功路径的三张表写入（注册 / 设备 / 会话），以及副作用（令牌 claim、提醒信）。
 */
#[CoversClass(VerifyOtpService::class)]
// T-106：签发那一半搬去了 SessionIssuer，但从 verify 这条入口看到的行为
// 一个字都没变 —— 本文件仍然是它最厚的一层覆盖（注册、设备四分支、提醒信）。
#[CoversClass(SessionIssuer::class)]
final class VerifyOtpServiceTest extends TestCase
{
    private const CODE = '418396';

    private const WRONG_CODE = '000000';

    private const CLIENT_IP = '203.0.113.9';

    private const MAX_ATTEMPTS = 5;

    private const ACCESS_TTL = 900;

    private const REFRESH_TTL = 7776000;

    private const BUDGET_MS = 120;

    private const BASE_URL = 'https://app.test.invalid';

    /** 32 字节，喂给 `RandomnessInterface::bytes(32)` 生成 refresh token。 */
    private const REFRESH_BYTES = '0123456789abcdef0123456789abcdef';

    private RecordingHmacHasher $hasher;

    private InMemoryUserRepository $users;

    private InMemoryOtpChallengeRepository $challenges;

    private InMemoryDeviceRepository $devices;

    private InMemorySessionRepository $sessions;

    private RecordingMailSender $mail;

    private RecordingRateLimiter $limiter;

    private RecordingTimeEqualizer $equalizer;

    private RecordingMetrics $metrics;

    private RecordingAccessTokenSigner $signer;

    private RecordingTransactionRunner $transactions;

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->hasher = new RecordingHmacHasher();
        $this->users = new InMemoryUserRepository();
        $this->challenges = new InMemoryOtpChallengeRepository();
        $this->devices = new InMemoryDeviceRepository();
        $this->sessions = new InMemorySessionRepository();
        $this->mail = new RecordingMailSender();
        $this->limiter = new RecordingRateLimiter();
        $this->equalizer = new RecordingTimeEqualizer();
        $this->metrics = new RecordingMetrics();
        $this->signer = new RecordingAccessTokenSigner();
        $this->transactions = new RecordingTransactionRunner();
        $this->clock = new FrozenClock((new \DateTimeImmutable('2026-09-06T12:00:00+00:00'))->getTimestamp() * 1000);
    }

    // ========================================================================
    // §3.8：拒绝路径不可区分 —— 本文件的重点
    // ========================================================================

    /**
     * ⚠️ **红了就是安全回归。**.
     *
     * 要挡住的是这一对比较：「未注册邮箱的 challenge + 错码 → 401」与
     * 「已注册邮箱的 challenge + 错码 → 401」。攻击者能对任意邮箱走完 request
     * （ADR-0014 之后恒 202、恒发信，而信进的是受害者的收件箱），
     * 拿到一个真实的 challenge_id 再随便编一个码 —— 两次 401 的做功若不等，
     * 「这个邮箱注册过吗」就从这一侧漏出去了。
     *
     * 五种拒绝形状一起测，是因为它们彼此之间也必须不可区分：
     * 「试太多次了」一旦可辨认，攻击者就能免费探测某条挑战被别人试过几次。
     */
    #[DataProvider('rejectionShapes')]
    public function testEveryRejectionShapeDoesTheSameAmountOfWork(string $shape): void
    {
        $bill = $this->measureRejection($shape);

        self::assertSame(
            [
                // hmac(code) 恰好一次 —— 无条件执行，不在任何分支里。
                'vault' => 1,
                'user_lookups' => 0,
                'mails' => 0,
                'sessions' => 0,
                'transactions' => 0,
                'settles' => 1,
            ],
            $bill,
            \sprintf('Rejection shape "%s" must cost exactly what every other rejection costs.', $shape),
        );
    }

    /**
     * 同一条断言的另一半：五种形状的账单**彼此**也要相等。
     *
     * 上面那条把绝对值钉死了，这条防的是「五种一起变」——
     * 那种改动会让上面那条一起改，而这条会留下来。
     */
    public function testAllRejectionShapesCostTheSameAsEachOther(): void
    {
        $bills = [];

        foreach (self::rejectionShapes() as $name => [$shape]) {
            $bills[$name] = $this->measureRejection($shape);
        }

        self::assertCount(1, array_unique(array_map(json_encode(...), $bills)), 'All rejection shapes must be indistinguishable: '.json_encode($bills));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectionShapes(): iterable
    {
        yield 'unknown challenge id' => ['missing'];
        yield 'wrong code' => ['wrong-code'];
        yield 'expired' => ['expired'];
        yield 'already consumed' => ['consumed'];
        yield 'attempts exhausted' => ['exhausted'];
    }

    /**
     * 全部拒绝共用同一条 401 与同一句文案 —— 细分等于把判据送给攻击者。
     */
    #[DataProvider('rejectionShapes')]
    public function testEveryRejectionLooksIdenticalToTheClient(string $shape): void
    {
        $exception = $this->expectRejection(fn () => $this->runRejection($shape));

        self::assertSame(ErrorCode::TokenInvalid, $exception->errorCode());
        self::assertSame('The verification code is not valid.', $exception->detail());
        // 文案里不能出现验证码 —— 它是一次性凭据，而 detail 会进日志与 Sentry。
        self::assertStringNotContainsString(self::CODE, $exception->detail());
        self::assertStringNotContainsString(self::WRONG_CODE, $exception->detail());
    }

    // ========================================================================
    // 拒绝路径的各条判据
    // ========================================================================

    /**
     * §7.1「attempts > 5 → 401」。`ncards.otp.max_attempts` 从 T-103 起
     * 配着没有消费者，这条用例是它第一次真的挡住东西。
     */
    public function testRefusesTheSixthAttemptEvenWithTheRightCode(): void
    {
        $challenge = $this->activeChallenge();
        $this->challenges->save($challenge);

        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $this->expectRejection(fn () => $this->service()->verify($this->payload($challenge->id(), self::WRONG_CODE), self::CLIENT_IP));
        }

        self::assertSame(self::MAX_ATTEMPTS, $challenge->attempts());

        // 第 6 次即使码是对的也不行 —— 上限作废的是**整条挑战**。
        $this->expectRejection(fn () => $this->service()->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP));

        self::assertSame(0, $this->sessions->count());
    }

    /**
     * ⚠️ `recordAttempt()` 必须**落盘**，而且不能在事务里 ——
     * 包进事务再抛 401 会把计数一起回滚，于是「5 次上限」永远数不到 5，
     * 暴力猜码的成本从 10^6/5 掉回 10^6。
     */
    public function testAFailedAttemptIsPersistedNotRolledBack(): void
    {
        $challenge = $this->activeChallenge();
        $this->challenges->save($challenge);

        $this->expectRejection(fn () => $this->service()->verify($this->payload($challenge->id(), self::WRONG_CODE), self::CLIENT_IP));

        self::assertSame(1, $challenge->attempts());
        // 两次 save：一次是夹具塞进去的，一次是失败尝试写回的。
        self::assertSame(['save', 'save'], $this->challenges->operations());
        self::assertSame(0, $this->transactions->runs(), 'A rejection must not open a transaction.');
    }

    /**
     * 成功也要先记一次尝试再比对（{@see OtpChallenge::recordAttempt()} 的注释）：
     * 否则「码对了」与「码错了」在 attempts 这一列上留下的痕迹不同。
     */
    public function testASuccessfulAttemptIsCountedToo(): void
    {
        $challenge = $this->activeChallenge();
        $this->challenges->save($challenge);

        $this->service()->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP);

        self::assertSame(1, $challenge->attempts());
    }

    public function testConsumesTheChallengeSoItCannotBeReplayed(): void
    {
        $challenge = $this->activeChallenge();
        $this->challenges->save($challenge);

        $service = $this->service();
        $service->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP);

        self::assertTrue($challenge->isConsumed());

        $this->expectRejection(fn () => $service->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP));
    }

    // ========================================================================
    // 注册（首次验证即建 users 行）
    // ========================================================================

    /**
     * §5.2 / §6.3.1 的核心：**首次验证成功即注册**，且 `username` 为 null。
     *
     * 那个 null 是 §5.2 三个候选方案里被显式选中的中间态，不是遗漏 ——
     * 用户此刻处于 `onboarding_incomplete`，客户端据 `onboarding_complete`
     * 路由到 username 设定页（T-107）。
     */
    public function testAFirstSuccessfulVerificationRegistersTheUser(): void
    {
        $challenge = $this->activeChallenge(locale: Locale::English);
        $this->challenges->save($challenge);

        $issued = $this->service()->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP);

        $user = $this->users->findByEmailHash($challenge->emailHash());

        self::assertInstanceOf(User::class, $user);
        self::assertNull($user->username(), 'A freshly registered user must not have a username yet (§5.2).');
        self::assertFalse($issued->onboardingComplete);
        self::assertNull($issued->username);
        // locale 取自挑战，也就是取自当初请求验证码时选的那个 ——
        // 收到英文码信却拿到 locale=de 的账号是用户能看见的 bug。
        self::assertSame(Locale::English, $user->locale());
        self::assertSame('en', $issued->locale);
        // 邮箱密文原样从挑战搬过来，全程不解密。
        self::assertSame($challenge->emailEncrypted()?->toString(), $user->emailEncrypted()->toString());
    }

    public function testAReturningUserIsNotRegisteredAgain(): void
    {
        $existing = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'), locale: Locale::German);
        $this->users->save($existing);

        $challenge = $this->activeChallenge(emailHash: IdentityEntities::digest('anna'));
        $this->challenges->save($challenge);

        $issued = $this->service()->verify($this->payload($challenge->id(), self::CODE), self::CLIENT_IP);

        self::assertTrue($issued->userId->equals($existing->id()));
        self::assertSame([['result' => 'success']], $this->metrics->labelsFor('login_total'));
    }

    public function testRegistrationIsCountedSeparatelyFromALogin(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertSame([['result' => 'registered']], $this->metrics->labelsFor('login_total'));
    }

    /**
     * 本次迁移之前建的挑战没有 `email_encrypted` —— 建不出 `users` 行
     * （没有收件人密文的账号是坏的）。那些行寿命只有 10 分钟，部署窗口一过自然消失。
     *
     * ⚠️ 走的是与其它拒绝**完全相同**的 401：这条路径同样能被外部触发。
     *
     * ⚠️ 用 {@see OtpChallenge::decoy()} 造夹具，是因为它是**唯一**一个能造出
     * `email_encrypted IS NULL` 的工厂（{@see OtpChallenge::issue()} 要求收件人密文）。
     * T-113 摘掉 `isDecoy()` 判断之后这条用例才真正验到它名字说的那件事 ——
     * 在那之前它被 decoy 这一位先拦下了，null 收件人那条分支其实没走到。
     */
    public function testALegacyChallengeWithoutARecipientCannotRegister(): void
    {
        $legacy = OtpChallenge::decoy(
            IdentityEntities::id(42),
            IdentityEntities::digest('legacy'),
            HashDigest::fromRaw($this->hasher->hash(self::CODE)),
            OtpPurpose::Login,
            $this->clock->now()->modify('+10 minutes'),
            null,
            $this->clock->now(),
        );

        $this->challenges->save($legacy);
        $this->hasher->reset();

        $this->expectRejection(fn () => $this->service()->verify($this->payload($legacy->id(), self::CODE), self::CLIENT_IP));

        self::assertSame(0, $this->users->count());
    }

    // ========================================================================
    // 设备
    // ========================================================================

    public function testRegistersAnUnseenDevice(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        $device = $this->devices->all()[0];

        self::assertTrue($device->id()->equals(self::deviceId()));
        self::assertSame('Pixel 8', $device->model());
        self::assertSame('15', $device->osVersion());
        self::assertSame('1.4.0', $device->appVersion());
    }

    /**
     * 同一台设备再次登录：刷新展示信息与 last_seen，**不算新设备**。
     */
    public function testRefreshesAKnownDeviceWithoutTreatingItAsNew(): void
    {
        $user = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'));
        $this->users->save($user);
        $this->devices->save(IdentityEntities::device($user, self::deviceId(), $this->clock->now()->modify('-1 day')));
        // 让这个账号已经有第二台设备 —— 否则「第一台不发信」那条规则会盖过本用例。
        $this->devices->save(IdentityEntities::device($user, IdentityEntities::id(77), $this->clock->now()->modify('-1 day')));

        $this->challenges->save($this->activeChallenge(emailHash: IdentityEntities::digest('anna')));

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        $device = $this->devices->findById(self::deviceId());

        self::assertNotNull($device);
        self::assertSame('Pixel 8', $device->model(), 'A returning device must have its display info refreshed.');
        self::assertEquals($this->clock->now(), $device->lastSeenAt());
        self::assertSame(0, $this->mail->count(), 'A known device must not trigger the new-device notice.');
    }

    /**
     * `devices.id` 是客户端生成的，所以撞上别人的安装只有两种可能：
     * 客户端的 UUID 生成坏了，或者有人在拿别人的设备 id 试探。
     *
     * ⚠️ 静默改绑是最坏的处理 —— 那会把受害者的设备行从他的设备管理页上挪走。
     */
    public function testRefusesADeviceIdThatBelongsToSomeoneElse(): void
    {
        $stranger = IdentityEntities::user(id: IdentityEntities::id(9), emailHash: IdentityEntities::digest('stranger'));
        $this->users->save($stranger);
        $this->devices->save(IdentityEntities::device($stranger, self::deviceId(), $this->clock->now()));

        $this->challenges->save($this->activeChallenge());

        try {
            $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);
            self::fail('Expected a 409.');
        } catch (DomainException $exception) {
            self::assertSame(ErrorCode::IdConflict, $exception->errorCode());
        }

        self::assertSame(0, $this->sessions->count());
        // 冲突的设备仍然属于原主人 —— 绝不静默改绑。
        self::assertTrue($this->devices->findById(self::deviceId())?->user()->id()->equals($stranger->id()) ?? false);
    }

    /**
     * 被远程登出过的设备又完成了一次 OTP 登录 → 复活，且**按新设备处理**。
     *
     * 后半句不是可选的：如果远程登出真的是因为设备被盗，那么攻击者拿着那台
     * 设备重新登录时，受害者必须再收到一封信。
     */
    public function testRevivesARevokedDeviceAndTreatsItAsNew(): void
    {
        $user = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'));
        $this->users->save($user);

        $revoked = IdentityEntities::device($user, self::deviceId(), $this->clock->now()->modify('-1 day'));
        $revoked->revoke($this->clock->now()->modify('-1 hour'));
        $this->devices->save($revoked);
        // 账号上还有另一台活着的设备，所以提醒信该发。
        $this->devices->save(IdentityEntities::device($user, IdentityEntities::id(78), $this->clock->now()->modify('-1 day')));

        $this->challenges->save($this->activeChallenge(emailHash: IdentityEntities::digest('anna')));

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertFalse($revoked->isRevoked(), 'A fresh OTP login must un-brick a remotely logged-out device.');
        self::assertSame(1, $this->mail->count(), 'A revived device must count as a new device.');
    }

    // ========================================================================
    // 会话与令牌
    // ========================================================================

    /**
     * §7.1：refresh 是 32 字节随机的 base64url，**库里只存 SHA-256**。
     *
     * ⚠️ 这里的摘要**不走 Vault HMAC**（§17.1 的 DDL 注释）：原像是 32 字节
     * CSPRNG，没有可枚举的字典，pepper 买不到任何东西 ——
     * 而代价是每次登录与每次刷新都多一次 Vault 往返。
     */
    public function testStoresOnlyTheSha256OfTheRefreshToken(): void
    {
        $this->challenges->save($this->activeChallenge());

        $issued = $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        $session = $this->sessions->only();

        self::assertTrue(
            $session->refreshTokenHash()->equals(HashDigest::fromRaw(hash('sha256', $issued->refreshToken, true))),
        );
        // base64url：没有 `+` `/` `=`。
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $issued->refreshToken);
        // 明文绝不落库。
        self::assertStringNotContainsString($issued->refreshToken, $session->refreshTokenHash()->toRaw());
        // §7.1：90 天滑动。
        self::assertEquals($this->clock->now()->modify('+'.self::REFRESH_TTL.' seconds'), $session->expiresAt());
    }

    /**
     * ⚠️ 三个 id 极易接错（都是 UUID，编译器分不出），而接错的症状是
     * 「T-105 的远程登出撤销了错误的会话」—— 单测之外几乎发现不了。
     */
    public function testTheAccessTokenCarriesTheSpecClaimsFromTheRightObjects(): void
    {
        $this->challenges->save($this->activeChallenge());

        $issued = $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        $claims = $this->signer->only();

        self::assertTrue($claims->subject->equals($issued->userId), 'sub is the user id.');
        self::assertTrue($claims->sessionId->equals($this->sessions->only()->id()), 'sid is the session id.');
        self::assertTrue($claims->deviceId->equals(self::deviceId()), 'did is the client-generated device id.');
        // jti 是这枚 token 自己的 id，不复用上面任何一个。
        self::assertFalse($claims->tokenId->equals($claims->sessionId));
        self::assertFalse($claims->tokenId->equals($issued->userId));
        // §7.1：15 分钟。expires_in 由 exp - iat 算，与配置同源。
        self::assertEquals($this->clock->now(), $claims->issuedAt);
        self::assertSame(self::ACCESS_TTL, $claims->expiresInSeconds());
        self::assertSame(self::ACCESS_TTL, $issued->expiresInSeconds);
    }

    /**
     * 三张表同生共死（§12.2「Application 层负责事务边界」）。
     *
     * 没有这条断言，谁把 run() 拆掉让三个仓储各自 flush，所有功能用例仍然全绿 ——
     * 而生产上会开始出现「建了用户但没有会话」这种要人工修的中间态。
     */
    public function testTheThreeWritesHappenInsideOneTransaction(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertSame(1, $this->transactions->runs());
    }

    /**
     * ⚠️ 成功路径**不填充耗时**。
     *
     * 它必然越过 120 ms 预算（建三张表 + 签一次 JWT + 入队一封信），硬填只会让
     * MonotonicTimeBudget 在每一次正常登录上打一行 "Constant-time budget overrun"，
     * 而那行是 §3.8 的安全告警，被正常流量淹掉之后就再也没有信号价值了。
     *
     * 「200 与 401 耗时不同」本身不是泄漏：走到 200 需要先拿到正确的 6 位码。
     */
    public function testASuccessfulLoginDoesNotPadItsTiming(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertSame([self::BUDGET_MS], $this->equalizer->budgets(), 'The budget is still opened...');
        self::assertSame(0, $this->equalizer->settleCount(), '...but never settled on the success path.');
    }

    // ========================================================================
    // 新设备提醒信
    // ========================================================================

    /**
     * 刚注册的账号第一次登录必然是「新设备」。给它发一封「检测到新设备登录」
     * 是纯噪声，而噪声会训练用户忽略这封信 —— 那正好毁掉它唯一的作用
     * （§7.2 T02：邮箱被接管时，这封信是用户能察觉的唯一信号）。
     */
    public function testTheVeryFirstDeviceOfANewAccountGetsNoNotice(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertSame(0, $this->mail->count());
    }

    public function testASecondDeviceTriggersTheNoticeWithTheTemplateContract(): void
    {
        $user = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'), locale: Locale::German);
        $this->users->save($user);
        $this->devices->save(IdentityEntities::device($user, IdentityEntities::id(79), $this->clock->now()->modify('-1 day')));

        $this->challenges->save($this->activeChallenge(emailHash: IdentityEntities::digest('anna')));

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        $mail = $this->mail->only();

        self::assertSame(MailTemplate::NewDeviceLogin, $mail->template);
        self::assertSame(MailLocale::German, $mail->locale);
        // 收件人是**密文**，明文邮箱不出 Identity（MailRequest 的类注释）。
        self::assertSame($user->emailEncrypted()->toString(), $mail->recipient->toString());
        // 变量集必须恰好等于模板要的那些，多一个少一个 MailRequest 当场抛。
        self::assertSame(MailTemplate::NewDeviceLogin->requiredVariables(), array_keys($mail->variables));
        self::assertSame('Pixel 8', $mail->variables['device_model']);
        self::assertSame('2026-09-06T12:00:00Z', $mail->variables['occurred_at']);
        self::assertSame(self::BASE_URL.'/l/devices', $mail->variables['revoke_url']);
    }

    /**
     * ⚠️ 没有 GeoIP，所以**不编一个地区**。模板正文写着「Region 是从网络连接
     * 粗略估计的」—— 填一个假地区会把那句话变成谎话，而这封信的全部价值在于可信。
     */
    #[DataProvider('regionPlaceholders')]
    public function testTheRegionIsAnHonestPlaceholderNotAGuess(Locale $locale, string $expected): void
    {
        $user = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'), locale: $locale);
        $this->users->save($user);
        $this->devices->save(IdentityEntities::device($user, IdentityEntities::id(80), $this->clock->now()->modify('-1 day')));

        $this->challenges->save($this->activeChallenge(emailHash: IdentityEntities::digest('anna')));

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertSame($expected, $this->mail->only()->variables['approximate_region']);
    }

    /**
     * @return iterable<string, array{Locale, string}>
     */
    public static function regionPlaceholders(): iterable
    {
        yield 'de' => [Locale::German, 'Unbekannt'];
        yield 'en' => [Locale::English, 'Unknown'];
    }

    /**
     * 机型没上报时回落到平台名 —— 信里那一行写着「Gerät: {{ device_model }}」，
     * 空着比「Android」更没用。
     */
    public function testFallsBackToThePlatformWhenTheModelIsUnknown(): void
    {
        $user = IdentityEntities::user(emailHash: IdentityEntities::digest('anna'));
        $this->users->save($user);
        $this->devices->save(IdentityEntities::device($user, IdentityEntities::id(81), $this->clock->now()->modify('-1 day')));

        $this->challenges->save($this->activeChallenge(emailHash: IdentityEntities::digest('anna')));

        $body = self::body($this->challenges->lastSaved()->id(), self::CODE);
        unset($body['device']['model']);

        $this->service()->verify(OtpVerificationPayload::fromArray($body), self::CLIENT_IP);

        self::assertSame('android', $this->mail->only()->variables['device_model']);
    }

    // ========================================================================
    // 限流
    // ========================================================================

    /**
     * ⚠️ 限流必须在**查库之前**：放到之后的话，429 的触发时刻会因为
     * 「challenge_id 存不存在」而不同。
     */
    public function testAThrottledRequestTouchesNothing(): void
    {
        $this->challenges->save($this->activeChallenge());
        // 夹具自己算过一次码摘要，那不算被测代码花的。
        $this->hasher->reset();
        $this->limiter->denyWith(new RateLimitExceeded(37, 0, 'otp_verify_ip'));

        try {
            $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);
            self::fail('Expected a 429.');
        } catch (RateLimitExceeded $exception) {
            self::assertSame(37, $exception->retryAfterSeconds());
        }

        self::assertSame(0, $this->hasher->callCount(), 'A throttled request must not spend a Vault round trip.');
        self::assertSame(['save'], $this->challenges->operations(), 'Only the fixture write.');
        self::assertSame(0, $this->sessions->count());
        // 早于分支点抛出的异常不携带存在性信息，所以不填充。
        self::assertSame(0, $this->equalizer->settleCount());
    }

    public function testChecksTheIpDimensionWithAPrefixedSubject(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), self::CLIENT_IP);

        self::assertCount(1, $this->limiter->batches());
        self::assertSame(['otp_verify_ip' => 'ip:'.self::CLIENT_IP], $this->limiter->lastBatch());
    }

    /**
     * IP 取不到时回落到固定串而**不是跳过限流** —— 跳过等于给任何能造出
     * 这种请求的调用方开一个绕过 §7.5 的后门。口径同 request 侧。
     */
    public function testFallsBackToAPlaceholderSubjectWhenTheIpIsUnknown(): void
    {
        $this->challenges->save($this->activeChallenge());

        $this->service()->verify($this->payload($this->challenges->lastSaved()->id(), self::CODE), null);

        self::assertSame('ip:unknown', $this->limiter->lastBatch()['otp_verify_ip']);
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private static function deviceId(): Uuid
    {
        return IdentityEntities::id(55);
    }

    /**
     * 一次拒绝的「Vault / DB / 副作用账单」。所有拒绝形状的账单必须逐项相等。
     *
     * @return array{vault: int, user_lookups: int, mails: int, sessions: int, transactions: int, settles: int}
     */
    private function measureRejection(string $shape): array
    {
        $this->setUp();

        $this->expectRejection(fn () => $this->runRejection($shape));

        return [
            'vault' => $this->hasher->callCount(),
            'user_lookups' => $this->users->findByEmailHashCalls(),
            'mails' => $this->mail->count(),
            'sessions' => $this->sessions->count(),
            'transactions' => $this->transactions->runs(),
            'settles' => $this->equalizer->settleCount(),
        ];
    }

    /**
     * 造出一种拒绝形状并执行它。夹具的准备**不能**计入账单，
     * 所以每种形状都在 `$this->hasher->reset()` 之后才发起请求。
     */
    private function runRejection(string $shape): void
    {
        $now = $this->clock->now();

        $challenge = match ($shape) {
            'missing' => null,
            'expired' => $this->activeChallenge(expiresAt: $now->modify('-1 second')),
            default => $this->activeChallenge(),
        };

        if (null !== $challenge) {
            $this->challenges->save($challenge);
        }

        if ('consumed' === $shape) {
            \assert(null !== $challenge);
            $challenge->consume($now);
        }

        if ('exhausted' === $shape) {
            \assert(null !== $challenge);

            for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
                $challenge->recordAttempt(self::MAX_ATTEMPTS);
            }
        }

        // ⚠️ 夹具准备完之后才清账：上面那些 hash() 是测试自己花的，不是被测代码花的。
        $this->hasher->reset();

        $code = 'wrong-code' === $shape ? self::WRONG_CODE : self::CODE;
        $id = $challenge?->id() ?? IdentityEntities::id(999);

        $this->service()->verify($this->payload($id, $code), self::CLIENT_IP);
    }

    private function activeChallenge(
        ?HashDigest $emailHash = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?Locale $locale = null,
    ): OtpChallenge {
        return IdentityEntities::challenge(
            emailHash: $emailHash ?? IdentityEntities::digest('anna'),
            codeHash: HashDigest::fromRaw($this->hasher->hash(self::CODE)),
            expiresAt: $expiresAt,
            now: $this->clock->now(),
            locale: $locale,
        );
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectRejection(callable $call): DomainException
    {
        try {
            $call();
        } catch (DomainException $exception) {
            self::assertSame(ErrorCode::TokenInvalid, $exception->errorCode());

            return $exception;
        }

        self::fail('Expected a 401 token_invalid.');
    }

    private function payload(Uuid $challengeId, string $code): OtpVerificationPayload
    {
        return OtpVerificationPayload::fromArray(self::body($challengeId, $code));
    }

    /**
     * @return array{challenge_id: string, code: string, device: array{id: string, platform: string, model: string, os_version: string, app_version: string}}
     */
    private static function body(Uuid $challengeId, string $code): array
    {
        return [
            'challenge_id' => $challengeId->toString(),
            'code' => $code,
            'device' => [
                'id' => self::deviceId()->toString(),
                'platform' => 'android',
                'model' => 'Pixel 8',
                'os_version' => '15',
                'app_version' => '1.4.0',
            ],
        ];
    }

    private function service(): VerifyOtpService
    {
        return new VerifyOtpService(
            $this->challenges,
            $this->hasher,
            $this->clock,
            $this->limiter,
            $this->equalizer,
            $this->metrics,
            $this->issuer(),
            maxAttempts: self::MAX_ATTEMPTS,
            verifyBudgetMillis: self::BUDGET_MS,
        );
    }

    /**
     * T-106：签发那一半搬去了 {@see SessionIssuer}，两个端点共用。
     *
     * ⚠️ 本文件仍然测它 —— 从 verify 这条入口看到的行为一个字都没变，
     * 而「搬家没搬坏」正是这些用例现在的价值。magic consume 那条入口
     * 由 tests/Api/MagicConsumeEndpointTest 覆盖。
     */
    private function issuer(): SessionIssuer
    {
        return new SessionIssuer(
            $this->users,
            $this->challenges,
            $this->devices,
            $this->sessions,
            // 每次登录恰好取一次 32 字节。给足 8 次，够任何一条用例连发。
            new SequenceRandomness(bytes: array_fill(0, 8, self::REFRESH_BYTES)),
            self::uuids(),
            $this->metrics,
            $this->mail,
            $this->signer,
            $this->transactions,
            accessTtlSeconds: self::ACCESS_TTL,
            refreshTtlSeconds: self::REFRESH_TTL,
            appBaseUrl: self::BASE_URL,
        );
    }

    private static function uuids(): UuidGeneratorInterface
    {
        return new class implements UuidGeneratorInterface {
            private int $nth = 0;

            public function generate(): Uuid
            {
                return IdentityEntities::id(++$this->nth + 200);
            }
        };
    }
}
