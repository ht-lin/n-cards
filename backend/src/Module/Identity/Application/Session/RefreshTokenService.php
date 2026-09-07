<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailSenderInterface;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\Token\AccessTokenSignerInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Domain\Token\AccessTokenClaims;
use Psr\Log\LoggerInterface;

/**
 * `POST /v1/auth/token/refresh` 的编排（§7.1，T-105）—— 轮换，以及重放检测。
 *
 * ============================================================================
 * ⚠️⚠️ 「查不到」不是 401 的终点，这是本类存在的全部理由
 * ============================================================================
 * 朴素的写法是：按摘要查 `sessions`，查不到就 401。那样写的话，§7.1 的重放检测
 * **整个不存在**，而且没有任何测试会红 —— 正常客户端永远不会重放，
 * 于是这条路径在生产里也一样安静。
 *
 * 真实的分支有三条：
 *
 *   1. 命中 `refresh_token_hash`  → 这是当前令牌，轮换（{@see rotate()}）
 *   2. 命中 `previous_token_hash` → 这是**刚被换掉的**令牌 = **令牌被窃**
 *                                   （{@see handleReuse()}：撤销 + 告警 + 发信）
 *   3. 都没命中                    → 未知令牌，401
 *
 * 第 2 条与第 3 条返回**逐字相同**的 401。攻击者不该从响应里读出
 * 「我手里这个令牌是不是刚被用过」。
 *
 * ============================================================================
 * ⚠️ 与 VerifyOtpService 的三处**刻意不同**
 * ============================================================================
 * 那个类是本仓库处理「敏感拒绝路径」的范本，但它的三条纪律在这里各有例外，
 * 每一条都会被 reviewer 当成不一致顺手「修」掉，所以逐条写在这里：
 *
 * 1. **限流不在最前，只能在查到 session 之后。**
 *    §7.5 给这个端点的维度是 `session` 60/h，而本端点没有 Bearer ——
 *    sid 只能从令牌摘要反查出来。未知令牌因此不消费任何配额。
 *    这不是洞：refresh token 是 32 字节 CSPRNG，没有可枚举面，
 *    而「反复打这个端点」由 `RateLimitListener` 的 `write_endpoints`
 *    （300/min，未认证时按 IP）兜住。
 *
 * 2. **不用 `TimeEqualizerInterface`。**
 *    §3.8 在 OTP 上要挡的是「这个邮箱注册过吗」。本端点没有那个面：
 *    能走到重放分支的人手里已经有过一枚真令牌，他早就知道这个账号存在。
 *    硬填耗时只会让每次正常刷新都打一行 `Constant-time budget overrun`，
 *    把 OTP 那边真正的告警淹掉。
 *
 * 3. **成功路径也要小心**，不像 verify 那样「过了码就不再隐藏什么」。
 *    这里没有「过了码」这一步 —— 令牌本身就是凭据，所以三条分支的响应形状
 *    必须由结构保证一致，而不是靠事后配平。
 *
 * ============================================================================
 * ⚠️ 合法客户端的重试**不能**被判成被窃 —— 靠幂等，不靠宽容
 * ============================================================================
 * 服务端轮换完、响应在路上丢了，客户端拿旧令牌重试 → 命中 previous →
 * 整条会话被撤销 + 一封安全警报，而实际上什么都没发生。
 *
 * 唯一的缓解是契约给这个端点挂的 `Idempotency-Key`（`docs/api/openapi.yaml`）：
 * `IdempotencyMiddleware` 回放存下的 200，连同**同一对令牌**。
 * 客户端侧的对应约束是 T-150 的「并发 5 个 401 只触发一次刷新」（Mutex 串行化）。
 *
 * ⚠️ **不要**试图在服务端「宽容一点」（比如「previous 命中且在 N 秒内就当成功」）。
 * 那等于给被窃令牌开一个 N 秒的可用窗口，而攻击者完全可以在那个窗口里刷新。
 */
final readonly class RefreshTokenService
{
    /** `config/packages/rate_limiter.yaml` 里的策略名（§7.5：session 60/h）。 */
    private const POLICY_SESSION = 'token_refresh';

    /**
     * 全部拒绝情形共用的文案。
     *
     * ⚠️ **绝不**细分成「令牌不存在」/「已经用过了」/「会话已撤销」/「过期了」。
     * 契约里 refresh 的 401 也只有一种 `token_invalid`，且客户端对四者的处置
     * 完全相同（清空本地会话、跳登录、**不要重试**）。
     * 可辨认的差异只会告诉攻击者他手里这枚令牌处于哪种状态。
     */
    private const REJECTED = 'The refresh token is not valid.';

    /**
     * 安全提醒信里那个「联系支持」链接的路径。
     *
     * 与 T-104 的 `REVOKE_PATH` 同一个套路：指向客户端域下的一个落地页
     * （`APP_PUBLIC_BASE_URL`），GET 它不改变任何状态 ——
     * 企业邮件安全网关会自动 GET 邮件里的每个链接（§7.1）。
     */
    private const SUPPORT_PATH = '/l/security';

    /**
     * @param int<1, max> $accessTtlSeconds  §7.1：900。`ncards.jwt.access_ttl_seconds`
     * @param int<1, max> $refreshTtlSeconds §7.1：90 天。每次轮换顺延同样的时长 ——
     *                                       这就是「90 天**滑动**」的落点
     * @param string      $appBaseUrl        `APP_PUBLIC_BASE_URL`，结尾不带斜杠
     */
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private DeviceRepositoryInterface $devices,
        private RandomnessInterface $random,
        private UuidGeneratorInterface $uuids,
        private ClockInterface $clock,
        private RateLimiterInterface $limiter,
        private MetricsInterface $metrics,
        private MailSenderInterface $mail,
        private AccessTokenSignerInterface $tokens,
        private TransactionRunnerInterface $transactions,
        private LoggerInterface $logger,
        private int $accessTtlSeconds,
        private int $refreshTtlSeconds,
        private string $appBaseUrl,
    ) {
    }

    /**
     * @throws DomainException                             401 `token_invalid`（未知 / 已撤销 / 已过期 / **重放**）
     * @throws \App\Shared\Domain\Error\RateLimitExceeded  429，带 Retry-After
     * @throws \App\Shared\Domain\Crypto\CryptoUnavailable 503（Vault 读不到签名密钥）
     */
    public function refresh(RefreshTokenPayload $payload, ?string $clientIp): SessionIssued
    {
        // 本地 SHA-256，**不**走 Vault HMAC —— 与 T-104 签发时的算法必须逐字相同，
        // 否则查不到任何行。论证（原像熵足够，pepper 买不到东西，而每次刷新
        // 多一次 Vault 往返是实打实的成本）见 VerifyOtpService::issueSession()。
        $presentedHash = HashDigest::fromRaw(hash('sha256', $payload->refreshToken, true));

        $now = $this->clock->now();

        // ============================================================
        // 分支 1：这是当前令牌
        // ============================================================
        // ⚠️ 整段读-改-写在一个事务里，且仓储对这一行加了 PESSIMISTIC_WRITE。
        // 不加锁的话两个并发刷新会各自 rotate()，后提交的覆盖先提交的，
        // 而先提交的那枚新令牌已经发回给客户端了 —— 它从此在库里不存在。
        $issued = $this->transactions->run(
            fn (): ?SessionIssued => $this->rotate($presentedHash, $now),
        );

        if (null !== $issued) {
            return $issued;
        }

        // ============================================================
        // 分支 2：这是**上一代**令牌 —— 令牌被窃
        // ============================================================
        $replayed = $this->sessions->findByPreviousTokenHash($presentedHash);

        if (null !== $replayed) {
            $this->handleReuse($replayed, $now, $clientIp);
        } else {
            // 分支 3：未知令牌。可能是伪造，也可能是一个隔了两代的旧令牌
            // （Session::rotate() 只留一代 previous）。两者都只值一条计数。
            $this->metrics->counter('token_refresh_total', ['result' => 'rejected']);
        }

        // ⚠️ 分支 2 与分支 3 在这里汇合，**逐字相同**的异常。
        // 把它写成两处 throw 就迟早会有人在其中一处「顺便说清楚一点」。
        throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
    }

    /**
     * 分支 1 的全部工作。找不到当前令牌则返回 null，让调用方去查 previous。
     *
     * ⚠️ 必须在事务里跑（仓储加了 `FOR UPDATE`）。
     */
    private function rotate(HashDigest $presentedHash, \DateTimeImmutable $now): ?SessionIssued
    {
        $session = $this->sessions->findByRefreshTokenHash($presentedHash);

        if (null === $session) {
            return null;
        }

        // ⚠️ 限流在这里，不在方法最前面（见类注释第 1 条）。
        // 放在 isActiveAt() 之前：一条已撤销的会话被反复打也该消耗配额，
        // 否则「拿一个已登出的令牌无限重试」是免费的。
        $this->limiter->consume(self::POLICY_SESSION, 'session:'.$session->id()->toString());

        if (!$session->isActiveAt($now)) {
            // 已撤销（logout / 远程登出 / 此前的 reuse_detected）或已过期。
            // ⚠️ 这里**不**触发重放处置：这条会话早就死了，撤销它没有意义，
            // 而给用户再发一封安全提醒信只会是噪声。
            $this->metrics->counter('token_refresh_total', ['result' => 'rejected']);

            throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
        }

        // §7.1：32 字节随机、Base64url、不透明。明文只在这条直线上存在。
        $refreshToken = self::base64UrlEncode($this->random->bytes(32));

        // rotate() 把当前摘要挪进 previous_token_hash —— 那一步就是重放检测的
        // 全部机制。expires_at 顺延 = §7.1 的「90 天滑动」。
        $session->rotate(
            HashDigest::fromRaw(hash('sha256', $refreshToken, true)),
            $now->modify(\sprintf('+%d seconds', $this->refreshTtlSeconds)),
        );

        $this->sessions->save($session);

        // 设备管理页的「最后在线」靠这一笔更新。刷新是最能代表「这台设备还在用」
        // 的信号 —— 它每 15 分钟发生一次，而登录可能几个月才一次。
        $device = $session->device();
        $device->touch($now);
        $this->devices->save($device);

        // ⚠️ `sid` 与 `did` 都**不变**：轮换的是 refresh token，不是会话。
        // 换一个 sid 会让远程登出失效（用户撤销的是他在设备管理页上看到的那条），
        // 也会让 §7.5 的 session 维度限流每次刷新都换一个桶。
        $accessToken = $this->tokens->sign(new AccessTokenClaims(
            $session->user()->id(),
            $session->id(),
            $device->id(),
            $this->uuids->generate(),
            $now,
            $now->modify(\sprintf('+%d seconds', $this->accessTtlSeconds)),
        ));

        $this->metrics->counter('token_refresh_total', ['result' => 'rotated']);

        $user = $session->user();

        return new SessionIssued(
            $accessToken->token,
            $accessToken->expiresInSeconds,
            $refreshToken,
            $user->id(),
            $user->username(),
            $user->locale()->value,
            $user->hasUsername(),
            $user->createdAt(),
        );
    }

    /**
     * §7.1 的重放处置 —— 判定令牌被窃。
     *
     * 四步，顺序有意义：先让令牌失效，再告警，最后才发信。
     */
    private function handleReuse(Session $session, \DateTimeImmutable $now, ?string $clientIp): void
    {
        // 1. 撤销。「会话家族全部令牌」= 这一行的 current + previous 两个摘要，
        //    随行一起死（论证见 SessionRepositoryInterface::findByPreviousTokenHash()）。
        //
        //    Session::revoke() 幂等且**首个 reason 胜出** —— 两个并发重放
        //    各撤一次的结果与撤一次相同，所以这里不需要锁。
        $this->transactions->run(function () use ($session, $now): void {
            $session->revoke(SessionRevokedReason::ReuseDetected, $now);
            $this->sessions->save($session);
        });

        // 2. 指标 + 告警。
        $this->metrics->counter('token_refresh_total', ['result' => 'reuse_detected']);
        $this->metrics->counter('session_revoked_total', ['reason' => SessionRevokedReason::ReuseDetected->value]);

        // ⚠️ `critical` 而不是 `warning`：这是 §7.1 里唯一一个被明确称为
        // 「令牌被窃」的事件，也是 SessionRevokedReason::isSecurityIncident()
        // 唯一返回 true 的那个 case。§14.4 的告警规则按级别筛。
        //
        // ⚠️⚠️ 上下文里**绝不**放令牌、令牌摘要或邮箱。放 session/user/device id
        // 就够定位了，而 PiiRedactionProcessor 认得的是键名、不是值 ——
        // 一个被塞进 `detail` 的令牌片段它认不出来。
        $this->logger->critical('Refresh token reuse detected; the session family has been revoked.', [
            'session_id' => $session->id()->toString(),
            'user_id' => $session->user()->id()->toString(),
            'device_id' => $session->device()->id()->toString(),
            'client_ip' => $clientIp,
        ]);

        // 3. `audit_log(reuse_detected)` **没写**。那张表归 T-401（M4），
        //    本任务不提前建 —— 与 T-104 对 `audit_log(login_success)` 的处理
        //    是同一个决定（docs/tasks/M1.md 的 T-104 回填块）。
        //    上面那条 critical 日志是它落地之前的替代品。

        // 4. 发信，**在事务之外**。MailSenderInterface::send() 只入队，
        //    但入队走 doctrine transport，也就是同一个连接上的一条 INSERT。
        //    放进事务里，「信已入队但撤销回滚」与「已撤销但信没入队」必有其一，
        //    而两者都是静默的。逐字同 VerifyOtpService::issueSession()。
        $this->notifyReuse($session, $now);
    }

    private function notifyReuse(Session $session, \DateTimeImmutable $now): void
    {
        $user = $session->user();

        $this->mail->send(new MailRequest(
            MailTemplate::RefreshReplay,
            // ⚠️ 这行 match 不能省成「共用一个 enum」，理由见 ADR-0012 的 Consequences。
            match ($user->locale()) {
                Locale::German => MailLocale::German,
                Locale::English => MailLocale::English,
            },
            $user->emailEncrypted(),
            [
                // §6.1：时间一律 RFC 3339 UTC。本地化格式化归客户端 ——
                // 服务端不猜用户的时区（我们没存过它）。
                'occurred_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'support_url' => $this->appBaseUrl.self::SUPPORT_PATH,
            ],
        ));
    }

    /**
     * RFC 4648 §5 的 base64url。与 T-104 签发时逐字相同的编码 ——
     * 两边不一致的话，轮换出来的令牌下一次会查不到，而症状是「刷新一次就掉线」。
     */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
