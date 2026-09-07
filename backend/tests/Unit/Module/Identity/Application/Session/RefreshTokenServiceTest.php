<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Session;

use App\Module\Identity\Application\Session\RefreshTokenPayload;
use App\Module\Identity\Application\Session\RefreshTokenService;
use App\Module\Identity\Application\Session\SessionIssued;
use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryDeviceRepository;
use App\Tests\Double\Identity\InMemorySessionRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Notification\RecordingMailSender;
use App\Tests\Double\Random\SequenceRandomness;
use App\Tests\Double\RateLimit\RecordingRateLimiter;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Double\Token\RecordingAccessTokenSigner;
use App\Tests\Double\Transaction\RecordingTransactionRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * §7.1 的轮换与重放检测（T-105）。
 *
 * ============================================================================
 * ⚠️ 本文件要钉死的那条不变量
 * ============================================================================
 * **「按当前摘要查不到」不等于「令牌无效」。**
 *
 * 一个刚被轮换掉的令牌在 `refresh_token_hash` 上查不到，但它躺在某一行的
 * `previous_token_hash` 里 —— 而那意味着令牌被窃。朴素实现（查不到就 401）
 * 会让整个重放检测消失，**且没有任何症状**：正常客户端从不重放，
 * 于是这条路径在生产里也一样安静。
 *
 * {@see testReplayingTheRotatedTokenRevokesTheSessionAndSendsTheAlert()} 是
 * 本卡验收标准的第一条，也是这个文件存在的理由。
 *
 * 与它成对的是
 * {@see testAReplayedTokenIsIndistinguishableFromAnUnknownOne()}：
 * 检测到被窃**不能**体现在响应里，否则攻击者就有了一个「我这枚令牌是不是
 * 刚被用过」的免费预言机。
 */
#[CoversClass(RefreshTokenService::class)]
final class RefreshTokenServiceTest extends TestCase
{
    private const NOW = '2026-09-06T12:00:00+00:00';

    /** 90 天，`ncards.jwt.refresh_ttl_seconds`。 */
    private const REFRESH_TTL = 7776000;

    private const APP_BASE_URL = 'https://app.staging.n-cards.de';

    private InMemorySessionRepository $sessions;

    private InMemoryDeviceRepository $devices;

    private RecordingRateLimiter $limiter;

    private RecordingMetrics $metrics;

    private RecordingMailSender $mail;

    private RecordingAccessTokenSigner $signer;

    private RecordingTransactionRunner $transactions;

    private FrozenClock $clock;

    private User $user;

    private Device $device;

    private Session $session;

    private RefreshTokenService $service;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(IdentityEntities::now(self::NOW)->getTimestamp() * 1000);

        $this->user = IdentityEntities::user(now: IdentityEntities::now(self::NOW));
        $this->device = IdentityEntities::device($this->user, now: IdentityEntities::now(self::NOW));
        $this->session = IdentityEntities::session(
            $this->user,
            $this->device,
            refreshTokenHash: self::hashOf('the-current-token'),
            now: IdentityEntities::now(self::NOW),
        );

        $this->sessions = new InMemorySessionRepository();
        $this->sessions->save($this->session);

        $this->devices = new InMemoryDeviceRepository($this->device);
        $this->limiter = new RecordingRateLimiter();
        $this->metrics = new RecordingMetrics();
        $this->mail = new RecordingMailSender();
        $this->signer = new RecordingAccessTokenSigner();
        $this->transactions = new RecordingTransactionRunner();

        // ⚠️ **一个**实例贯穿整条用例，不是每次 refresh() 现造一个。
        // 现造的话每次都会拿到一条崭新的 SequenceRandomness，于是两次轮换
        // 签出**同一个**令牌 —— 而「新旧令牌不同」正是这里最核心的断言之一，
        // 它会在一个假的前提下变绿。
        $this->service = new RefreshTokenService(
            $this->sessions,
            $this->devices,
            new SequenceRandomness([
                str_repeat("\x11", 32),
                str_repeat("\x22", 32),
                str_repeat("\x33", 32),
                str_repeat("\x44", 32),
            ]),
            self::uuids(),
            $this->clock,
            $this->limiter,
            $this->metrics,
            $this->mail,
            $this->signer,
            $this->transactions,
            // 告警日志的内容由 Api 层的用例覆盖（那里能看到真的 handler）。
            new NullLogger(),
            900,
            self::REFRESH_TTL,
            self::APP_BASE_URL,
        );
    }

    // ========================================================================
    // 轮换
    // ========================================================================

    /**
     * 旧摘要挪进 `previous_token_hash`，新摘要接位。那一步**就是**重放检测的
     * 全部机制 —— 没有它，下面所有用例都无从谈起。
     */
    public function testRotationMovesTheOldDigestIntoPreviousAndIssuesANewPair(): void
    {
        $before = $this->session->refreshTokenHash();

        $issued = $this->refresh('the-current-token');

        self::assertTrue($before->equals($this->session->previousTokenHash() ?? self::hashOf('nope')));
        self::assertFalse($before->equals($this->session->refreshTokenHash()));

        // 返回给客户端的明文与库里的新摘要必须对得上，否则「刷新一次就掉线」。
        self::assertTrue(
            HashDigest::fromRaw(hash('sha256', $issued->refreshToken, true))->equals($this->session->refreshTokenHash()),
        );

        self::assertNotSame('', $issued->accessToken);
    }

    /**
     * §7.1「90 天**滑动**」的落点：每次轮换把 `expires_at` 顺延同样的时长。
     *
     * T-104 只负责首次设定；不顺延的话，一个每天都在用的客户端会在登录后
     * 第 90 天被踢下线，而它明明一直活跃。
     */
    public function testRotationSlidesTheExpiryWindowForward(): void
    {
        $this->clock->advance(30 * 86400 * 1000);

        $this->refresh('the-current-token');

        self::assertSame(
            $this->clock->now()->modify('+'.self::REFRESH_TTL.' seconds')->getTimestamp(),
            $this->session->expiresAt()->getTimestamp(),
        );
    }

    /**
     * ⚠️ `sid` 与 `did` 在轮换后**不变**。
     *
     * 换掉 `sid` 会同时打断两件事：用户在设备管理页上撤销的是他看到的那条会话，
     * 而 §7.5 的 session 维度限流会每次刷新都换一个桶（等于没有限流）。
     */
    public function testTheSessionAndDeviceIdsSurviveRotation(): void
    {
        $this->refresh('the-current-token');

        $claims = $this->signer->only();

        self::assertTrue($this->session->id()->equals($claims->sessionId));
        self::assertTrue($this->device->id()->equals($claims->deviceId));
        self::assertTrue($this->user->id()->equals($claims->subject));
    }

    /**
     * 刷新是「这台设备还在用」最密的信号（每 15 分钟一次，而登录可能几个月一次）。
     * 设备管理页的「最后在线」靠它。
     */
    public function testRotationTouchesTheDeviceLastSeenAt(): void
    {
        $this->clock->advance(3600 * 1000);

        $this->refresh('the-current-token');

        self::assertSame($this->clock->now()->getTimestamp(), $this->device->lastSeenAt()->getTimestamp());
    }

    /**
     * ⚠️ 读-改-写必须在一个事务里 —— 仓储对那一行加了 `FOR UPDATE`，
     * 而 Doctrine 的悲观锁在事务外会抛 `TransactionRequiredException`。
     */
    public function testTheRotationRunsInsideATransaction(): void
    {
        $this->refresh('the-current-token');

        self::assertGreaterThanOrEqual(1, $this->transactions->runs());
    }

    // ========================================================================
    // ⚠️ 验收标准第一条：重放
    // ========================================================================

    /**
     * 轮换一次，然后拿**旧**令牌再刷一次 → 判定令牌被窃。
     *
     * 后果四件：整条会话被撤销（reason = reuse_detected）、指标、告警日志、
     * 安全提醒邮件。这里断言其中三件（日志走 NullLogger，由 Api 层覆盖）。
     */
    public function testReplayingTheRotatedTokenRevokesTheSessionAndSendsTheAlert(): void
    {
        $this->refresh('the-current-token');

        $this->mail->reset();

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        self::assertTrue($this->session->isRevoked());
        self::assertSame(SessionRevokedReason::ReuseDetected, $this->session->revokedReason());

        self::assertSame(1, $this->mail->count());
        self::assertSame(MailTemplate::RefreshReplay, $this->mail->only()->template);

        self::assertContains(
            ['result' => 'reuse_detected'],
            $this->metrics->labelsFor('token_refresh_total'),
        );
    }

    /**
     * 「会话家族全部令牌」—— 撤销之后，**刚刚换到的那枚新令牌也不能用了**。
     *
     * 这条是「家族」二字的全部含义：`sessions.id` 在轮换链上不变，
     * current 与 previous 两个摘要随那一行一起死。
     */
    public function testTheWholeFamilyDiesIncludingTheTokenTheThiefHasNotUsedYet(): void
    {
        $fresh = $this->refresh('the-current-token')->refreshToken;

        // 真正的用户（或小偷）重放旧令牌。
        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        // 刚刚合法签发的那一枚现在也失效了。
        $this->expectRejection(fn () => $this->refresh($fresh));
    }

    /**
     * ⚠️ 与上一条成对：检测到被窃**不能**从响应里看出来。
     *
     * 能看出来的话，攻击者就有了一个免费的预言机：拿一枚令牌打一下，
     * 从响应差异读出「它是不是刚被换掉的」。
     */
    public function testAReplayedTokenIsIndistinguishableFromAnUnknownOne(): void
    {
        $this->refresh('the-current-token');

        $replay = $this->rejectionOf(fn () => $this->refresh('the-current-token'));
        $unknown = $this->rejectionOf(fn () => $this->refresh('never-existed-at-all'));

        self::assertSame($unknown->errorCode(), $replay->errorCode());
        self::assertSame($unknown->getMessage(), $replay->getMessage());
        self::assertSame($unknown->fieldErrors(), $replay->fieldErrors());
    }

    /**
     * 发信在事务**之外**。放进去的话，「信已入队但撤销回滚」与
     * 「已撤销但信没入队」必有其一，而两者都是静默的。
     */
    public function testTheAlertMailIsSentOutsideTheTransaction(): void
    {
        $this->refresh('the-current-token');
        $this->mail->reset();

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        self::assertFalse($this->transactions->isInTransaction());
        self::assertSame(1, $this->mail->count());
    }

    /**
     * 提醒信的两个变量按契约（`MailTemplate::RefreshReplay::requiredVariables()`）
     * 齐全，且 `support_url` 指向客户端域的落地页 —— GET 它不改变任何状态，
     * 因为企业邮件安全网关会自动 GET 邮件里的每个链接（§7.1）。
     */
    public function testTheAlertMailCarriesTheContractedVariables(): void
    {
        $this->refresh('the-current-token');
        $this->mail->reset();

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        $request = $this->mail->only();

        self::assertSame(['occurred_at', 'support_url'], array_keys($request->variables));
        self::assertSame(self::APP_BASE_URL.'/l/security', $request->variables['support_url']);
        // §6.1：时间一律 RFC 3339 UTC。
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $request->variables['occurred_at']);
    }

    /**
     * ⚠️ 只留**一代** previous（见 `Session::rotate()`）。隔了两代的旧令牌
     * 因此查不到，得到普通 401 而**不**触发告警。
     *
     * 这是可接受的：客户端手里任意时刻只有一个 refresh token，
     * 能拿出上一代的攻击者必然是刚偷到的。
     */
    public function testATwoGenerationsOldTokenIsJustRejectedWithoutAnAlert(): void
    {
        $second = $this->refresh('the-current-token')->refreshToken;
        $this->refresh($second);

        $this->mail->reset();

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        self::assertSame(0, $this->mail->count(), 'A two-generations-old token must not raise a theft alert.');
        self::assertFalse($this->session->isRevoked());
    }

    // ========================================================================
    // 拒绝路径
    // ========================================================================

    public function testAnUnknownTokenIsRejectedWithoutTouchingAnything(): void
    {
        $this->expectRejection(fn () => $this->refresh('never-existed-at-all'));

        self::assertFalse($this->session->isRevoked());
        self::assertSame(0, $this->mail->count());
        self::assertSame([['result' => 'rejected']], $this->metrics->labelsFor('token_refresh_total'));
    }

    /**
     * 已登出的会话不能再刷新 —— 「会话撤销后 refresh 立即失效」（§7.1）。
     *
     * ⚠️ 而且**不**触发重放处置：这条会话早就死了，再发一封安全提醒信只是噪声。
     */
    public function testARevokedSessionCannotBeRefreshedAndRaisesNoAlert(): void
    {
        $this->session->revoke(SessionRevokedReason::Logout, $this->clock->now());

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        self::assertSame(SessionRevokedReason::Logout, $this->session->revokedReason());
        self::assertSame(0, $this->mail->count());
    }

    public function testAnExpiredSessionCannotBeRefreshed(): void
    {
        $this->clock->advance((self::REFRESH_TTL + 1) * 1000);

        $this->expectRejection(fn () => $this->refresh('the-current-token'));
    }

    // ========================================================================
    // 限流 —— §7.5：session 60/h
    // ========================================================================

    /**
     * ⚠️ 主体是 `session:<sid>`，而且**只能在查到 session 之后**才算得出来 ——
     * 这个端点没有 Bearer，sid 只能从令牌摘要反查。
     *
     * 这是与 `VerifyOtpService`「限流在最前」的刻意偏离，写在被测类的注释里。
     */
    public function testTheRateLimitIsConsumedPerSession(): void
    {
        $this->refresh('the-current-token');

        self::assertSame(
            ['token_refresh' => 'session:'.$this->session->id()->toString()],
            $this->limiter->lastBatch(),
        );
    }

    /**
     * 未知令牌不消费任何配额 —— 它算不出主体。
     *
     * 兜底靠 `RateLimitListener` 的 `write_endpoints`（300/min，未认证时按 IP），
     * 而 32 字节 CSPRNG 令牌本来也没有可枚举面。
     */
    public function testAnUnknownTokenConsumesNoQuota(): void
    {
        $this->expectRejection(fn () => $this->refresh('never-existed-at-all'));

        self::assertSame([], $this->limiter->batches());
    }

    /**
     * ⚠️ 已撤销的会话**照样**消费配额。不然「拿一个已登出的令牌无限重试」
     * 是免费的。
     */
    public function testARevokedSessionStillConsumesQuota(): void
    {
        $this->session->revoke(SessionRevokedReason::Logout, $this->clock->now());

        $this->expectRejection(fn () => $this->refresh('the-current-token'));

        self::assertCount(1, $this->limiter->batches());
    }

    /**
     * 429 原样冒泡 —— 由 `ApiProblemExceptionListener` 渲染成
     * `Retry-After` + `X-RateLimit-Remaining`。轮换不该发生。
     */
    public function testAThrottledRefreshDoesNotRotate(): void
    {
        $before = $this->session->refreshTokenHash();
        $this->limiter->denyWith(new RateLimitExceeded(60, 0));

        $this->expectException(RateLimitExceeded::class);

        try {
            $this->refresh('the-current-token');
        } finally {
            self::assertTrue($before->equals($this->session->refreshTokenHash()));
        }
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private function refresh(string $token): SessionIssued
    {
        return $this->service->refresh(
            RefreshTokenPayload::fromArray(['refresh_token' => str_pad($token, 32, '-')]),
            '198.51.100.7',
        );
    }

    private function expectRejection(callable $call): void
    {
        $rejection = $this->rejectionOf($call);

        self::assertSame(ErrorCode::TokenInvalid, $rejection->errorCode());
        self::assertSame('The refresh token is not valid.', $rejection->getMessage());
    }

    private function rejectionOf(callable $call): DomainException
    {
        try {
            $call();
        } catch (DomainException $e) {
            return $e;
        }

        self::fail('The refresh must have been rejected.');
    }

    /**
     * ⚠️ 不用真的 `Uuid7Generator` —— 它要注入 ClockInterface + RandomnessInterface，
     * 而后者正是上面那条 SequenceRandomness：生成一个 jti 会吃掉一份排队的随机数，
     * 于是「第二次轮换拿到第二个令牌」这条前提会静默地错位。
     * 这里给一个自己数数的生成器，随机数序列因此只服务于 refresh token 本身。
     */
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

    /**
     * 与生产侧逐字相同的摘要算法（本地 SHA-256，不走 Vault）。
     */
    private static function hashOf(string $token): HashDigest
    {
        return HashDigest::fromRaw(hash('sha256', str_pad($token, 32, '-'), true));
    }
}
