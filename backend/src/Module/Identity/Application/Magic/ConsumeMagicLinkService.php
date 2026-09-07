<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Magic;

use App\Module\Identity\Application\Session\SessionIssued;
use App\Module\Identity\Application\Session\SessionIssuer;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/auth/magic/consume` 的编排（§6.2、§7.1）—— 免输码的登录。
 *
 * ============================================================================
 * 它落在与 6 位码**同一行**挑战上
 * ============================================================================
 * `otp_challenges` 的一行同时挂着 `code_hash` 与 `magic_token_hash`，
 * 共用一个 `expires_at` 与一个 `consumed_at`。于是：
 *
 *   - 一次 `POST /auth/otp/request` 发出去的那封信里，码与链接是**同一次登录**
 *     的两种入口，不是两次登录机会；
 *   - 谁先被消费，另一个立刻失效（`OtpChallenge::consume()` 在事务里判空）；
 *   - §7.1 的「单次登录只允许一个活跃 challenge」因此对两条入口同时成立，
 *     不需要第二套作废逻辑。
 *
 * ============================================================================
 * ⚠️ 为什么这里**没有** TimeEqualizer，而 verify 有
 * ============================================================================
 * `VerifyOtpService` 的恒定耗时预算挡的是一条具体的信道：ADR-0014 之后攻击者能对
 * **任意**邮箱拿到一个真实的 `challenge_id`（信进了受害者的收件箱，他看不到），
 * 再用一个随便编的 6 位码去问「这个邮箱注册过吗」。码只有 10^6 种，
 * `challenge_id` 是白送的，所以那里的每一条拒绝路径都必须做同样多的功。
 *
 * 这里没有对应的东西：
 *
 *   - **令牌不可枚举。** 32 字节 CSPRNG。攻击者拿不到一个「随便编的」magic token，
 *     也就无从构造那一对要比较的请求。
 *   - **没有可问的问题。** 唯一能从时序里读出的是「我手上这个令牌还有效吗」，
 *     而他直接 POST 一次就有答案 —— 那不是侧信道，那是端点的功能。
 *   - **拒绝路径本来就不分叉。** 三种拒绝（查不到 / 过期 / 已消费）做的事
 *     都是一次 SHA-256 + 一次索引查询，且都不查 `users`、不加密、不发信。
 *
 * ⚠️ 所以 {@see \App\Shared\Application\Timing\TimeEqualizerInterface} 的类注释里
 * 那句「将来 T-106 的 magic consume」是**预言错了**，已在那边改掉。
 * 给一条登录关键路径白加 120 ms，换不到任何东西。
 *
 * ============================================================================
 * ⚠️ 也没有 attempts
 * ============================================================================
 * `otp_challenges.attempts` 的 5 次上限是给**可猜的** 6 位码准备的：把 10^6 的
 * 搜索空间压到 5 次，那是 §7.1 那条「猜中概率 < 5/10⁶」的全部来源。
 *
 * 令牌有 2^256 种，计数器在这里没有任何东西可挡。而且它连**记**都记不下来：
 * verify 是拿 `challenge_id` 找行、再比码，所以错码也能定位到一行去 `attempts++`；
 * 这里是拿令牌的摘要**本身**找行，猜错的令牌根本找不到任何行 ——
 * 没有行可以加一。挡暴力破解的是那 256 位，以及 §7.5 的 IP 60/h。
 */
final readonly class ConsumeMagicLinkService
{
    /** `config/packages/rate_limiter.yaml` 里的策略名（§7.5：IP 60/h）。 */
    private const POLICY_IP = 'magic_consume_ip';

    /** 取不到客户端 IP 时的占位主体，口径同 {@see \App\Module\Identity\Application\Otp\VerifyOtpService}。 */
    private const IP_FALLBACK = 'unknown';

    /**
     * 全部拒绝情形共用的文案。
     *
     * ⚠️ **绝不**细分成「没这个令牌」/「过期了」/「已经用过了」。
     * 三者的区别对客户端毫无用处（处置都是「回 App 重新请求一次登录」），
     * 而「已经用过了」一旦可辨认，就等于告诉持有一个**窃得的**令牌的人
     * 「你来晚了，但这个链接确实是真的」—— 那是一条免费的确认信道。
     * 契约里本端点的 401 也只有一种 `token_invalid`。
     */
    private const REJECTED = 'The sign-in link is not valid.';

    public function __construct(
        private OtpChallengeRepositoryInterface $challenges,
        private ClockInterface $clock,
        private RateLimiterInterface $limiter,
        private MetricsInterface $metrics,
        private TransactionRunnerInterface $transactions,
        private SessionIssuer $issuer,
    ) {
    }

    /**
     * @param string|null $clientIp 由 Http 层从 `Request::getClientIp()` 取出后以裸字符串传入 ——
     *                              deptrac 里 `Identity.Application` 不得出现 `Request`
     *
     * @throws DomainException                            401 `token_invalid`（查不到 / 过期 / 已消费）、
     *                                                    409 `id_conflict`（设备 id 属于别人）
     * @throws \App\Shared\Domain\Error\RateLimitExceeded 429，带 Retry-After
     */
    public function consume(MagicLinkConsumptionPayload $payload, ?string $clientIp): SessionIssued
    {
        // ⚠️ 在查库之前，与 verify 同序。放到查库之后的话，429 的触发时刻会因为
        // 「这个令牌存不存在」而不同 —— 那把上面刚论证过「没有侧信道」重新变成有。
        $this->limiter->consumeAll([
            new RateLimitCheck(self::POLICY_IP, 'ip:'.($clientIp ?? self::IP_FALLBACK)),
        ]);

        $now = $this->clock->now();

        // ⚠️ 本地 SHA-256，**不**走 Vault HMAC。与 `Session::refreshTokenHash`
        // 同一条论证（{@see SessionIssuer::issue()} 里写着）：32 字节 CSPRNG
        // 没有可枚举的字典，pepper 买不到任何东西，而代价是在一条登录关键路径上
        // 多一次 Vault 往返。
        //
        // ⚠️ 于是 `otp_challenges` 一张表上两列哈希用了两种口径：`code_hash` 走
        // Vault HMAC（6 位数字从一份库备份里几秒钟就能全枚举，pepper 是唯一的防线），
        // `magic_token_hash` 走本地摘要。这个不对称是**刻意的**，不要顺手「统一」。
        $tokenHash = HashDigest::fromRaw(hash('sha256', $payload->token, true));

        // ============================================================
        // ⚠️ 查询与消费必须在**同一个**事务里
        // ============================================================
        // 消费是读-改-写：查到挑战 → 判 `consumed_at IS NULL` → 写 `consumed_at`。
        // `findByMagicTokenHash()` 因此带 `FOR UPDATE`，而 `FOR UPDATE` 要求
        // 已经在事务里（否则 Doctrine 抛 TransactionRequiredException ——
        // 那是接线错误，应该当场炸而不是悄悄退化成无锁）。
        //
        // 不加这一层的后果不是「偶尔慢一点」，是**一个令牌换到两个会话**：
        // 两个并发 POST 各自在事务外读到 consumed_at IS NULL，各自通过
        // `OtpChallenge::consume()` 的判空，各自 UPDATE 同一行，后写覆盖先写，
        // 而库里只留下一条看起来完全正常的记录。
        //
        // `SessionIssuer::issue()` 内部那个 run() 于是变成一个 SAVEPOINT
        // （`doctrine.yaml` 的 `use_savepoints: true`，见 TransactionRunnerInterface
        // 的「嵌套语义」一节）。签 JWT 落在事务里 —— 与 T-105 的
        // `RefreshTokenService::rotate()` 同一个形状，签名密钥是缓存的。
        return $this->transactions->run(function () use ($tokenHash, $payload, $now): SessionIssued {
            $challenge = $this->challenges->findByMagicTokenHash($tokenHash);

            $accepted = null !== $challenge
                && !$challenge->isExpiredAt($now)
                && !$challenge->isConsumed();

            if (!$accepted) {
                // 计数走 Redis，不在事务里，所以这条回滚不掉 —— 正是我们要的。
                $this->metrics->counter('login_total', ['result' => 'rejected']);

                throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
            }

            // 走到这里的前提是持有一个 256 位的令牌，也就是调用方已经能读那个邮箱了
            // —— 此后不再隐藏任何东西（与 VerifyOtpService 成功路径同一条论证）。
            return $this->issuer->issue($challenge, $payload->device, $now);
        });
    }
}
