<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Session\SessionIssued;
use App\Module\Identity\Application\Session\SessionIssuer;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\Timing\TimeEqualizerInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/auth/otp/verify` 的编排（§6.3.1、§7.1）—— 登录，且首次即注册。
 *
 * ============================================================================
 * 拒绝路径与成功路径是两种完全不同的东西，别把它们看成一条流程的两个分支
 * ============================================================================
 * **拒绝路径**（401）是安全敏感的：它要对「码错了」「过期了」「已经用过了」
 * 「试太多次了」「这是上个版本留下的哑挑战」五种情形返回**逐字相同**的响应，
 * 且做功与耗时也要相同（见下一节）。
 *
 * **成功路径**（200）不是：走到那里的前提是拿到了正确的 6 位码，
 * 也就是攻击者已经能读那个邮箱了 —— 此后再隐藏什么都没有意义。
 * 所以成功路径只关心正确性（三张表同生共死）与副作用（提醒信）。
 *
 * 两条路径的这个不对称贯穿全类，包括 `settle()` 只在拒绝路径调用。
 *
 * 而正因为成功路径不再有任何要隐藏的东西，它整段住在
 * {@see SessionIssuer} 里 —— T-106 的 Magic Link 鉴别方式不同，
 * 鉴别通过之后要做的事逐字相同。**本类只剩鉴别**。
 *
 * ============================================================================
 * 要挡住的那一对比较
 * ============================================================================
 * §3.8 在这个端点上要挡的**不是**「401 与 200 有什么差别」，而是这一对：
 *
 *     「**未注册**邮箱的 challenge + 错码 → 401」
 *     「**已注册**邮箱的 challenge + 错码 → 401」
 *
 * 攻击者能对任意邮箱走完 request（ADR-0014 之后恒 202、恒发信，而信进的是
 * 受害者的收件箱，他看不到），拿到一个真实的 `challenge_id`，
 * 再用一个随便编的码来 verify。这两次 401 若有稳定的耗时或形状差异，
 * 「这个邮箱注册过吗」就从这一侧漏出去了 —— request 侧刚焊死的门会从这里重新打开。
 *
 * 好消息是这两条在本类里**根本不是两条**：拒绝路径不查 `users`、不加密、不发信，
 * 它做的事只有 hmac(code) ×1 + findById + attempts 的 UPDATE，
 * 与挑战背后有没有用户完全无关。这不是靠配平得来的，是结构上就没有分叉。
 *
 * 剩下的余数由 {@see TimeEqualizerInterface} 兜（`ncards.otp.verify_budget_ms`）。
 *
 * ============================================================================
 * ⚠️ 三条顺序约束，每一条都能悄悄打开一个洞
 * ============================================================================
 *   1. **限流在最前**。放到查库之后的话，429 的触发时刻会因为
 *      「challenge_id 存不存在」而不同。
 *   2. **`hash_equals` 永远执行，`isDecoy()` 永远排在它后面**。
 *      反过来写（先判 decoy 就短路返回）会让哑挑战少一次 Vault HMAC 往返，
 *      而哑挑战恰好等价于「这个邮箱在上个版本里没注册过」—— 那是一条现成的
 *      枚举信道。所以 {@see verify()} 里那条布尔链的**次序是安全约束，不是风格**。
 *   3. **`recordAttempt()` 必须落盘，且不在事务里**。它记的是失败，
 *      而失败路径要抛 401；包进事务再抛会把计数一起回滚，
 *      §7.1 的「5 次上限」于是永远数不到 5，暴力猜码的成本从 10^6/5 掉回 10^6。
 */
final readonly class VerifyOtpService
{
    /** `config/packages/rate_limiter.yaml` 里的策略名（§7.5：IP 60/h）。 */
    private const POLICY_IP = 'otp_verify_ip';

    /** 取不到客户端 IP 时的占位主体，口径同 {@see RequestOtpService}。 */
    private const IP_FALLBACK = 'unknown';

    /**
     * 全部拒绝情形共用的文案。
     *
     * ⚠️ **绝不**细分成「码错了」/「过期了」/「试太多次了」。
     * 前两者的区别对客户端毫无用处（两种的处置都是「重新请求一个码」），
     * 而第三种一旦可辨认，攻击者就能用它免费探测「这个 challenge 被别人试过几次」。
     * 契约里 verify 的 401 也只有一种 `token_invalid`。
     */
    private const REJECTED = 'The verification code is not valid.';

    /**
     * @param int<1, max> $maxAttempts        §7.1：5 次。`ncards.otp.max_attempts`
     * @param int<1, max> $verifyBudgetMillis 拒绝路径的恒定耗时预算，见 ncards_otp.yaml
     */
    public function __construct(
        private OtpChallengeRepositoryInterface $challenges,
        private HmacHasherInterface $hasher,
        private ClockInterface $clock,
        private RateLimiterInterface $limiter,
        private TimeEqualizerInterface $equalizer,
        private MetricsInterface $metrics,
        private SessionIssuer $issuer,
        private int $maxAttempts,
        private int $verifyBudgetMillis,
    ) {
    }

    /**
     * @param string|null $clientIp 由 Http 层从 `Request::getClientIp()` 取出后以裸字符串传入 ——
     *                              deptrac 里 `Identity.Application` 不得出现 `Request`
     *
     * @throws DomainException                             401 `token_invalid`（码错/过期/已用/次数耗尽/哑挑战）、
     *                                                     409 `id_conflict`（设备 id 属于别人）、
     *                                                     503（Redis 不可用时限流 fail-closed）
     * @throws \App\Shared\Domain\Error\RateLimitExceeded  429，带 Retry-After
     * @throws \App\Shared\Domain\Crypto\CryptoUnavailable 503（Vault 不可达）
     */
    public function verify(OtpVerificationPayload $payload, ?string $clientIp): SessionIssued
    {
        $budget = $this->equalizer->begin($this->verifyBudgetMillis);

        // ⚠️ 在查库之前。§7.5 的 IP 60/h 是本端点唯一的暴力破解闸门 ——
        // 「challenge_id 5 次总计」只挡住对**同一条**挑战的猜测，
        // 挡不住「反复请求新挑战、每条各试 5 次」。
        $this->limiter->consumeAll([
            new RateLimitCheck(self::POLICY_IP, 'ip:'.($clientIp ?? self::IP_FALLBACK)),
        ]);

        $now = $this->clock->now();
        $challenge = $this->challenges->findById($payload->challengeId);

        // ⚠️ 无条件算，即使 $challenge 是 null。这一次 Vault 往返是拒绝路径上
        // 最贵的一步，把它放进任何分支里都会让那个分支变得可测量地更快。
        $codeHash = HashDigest::fromRaw($this->hasher->hash($payload->code));

        // ⚠️ 次序是安全约束（见类注释第 2 条）。`&&` 会短路，但前四项都是纯内存判断，
        // 唯一有代价的一步已经在上面执行完了 —— 短路在这里不产生可测量的差异。
        //
        // isDecoy() 排在最后：它等价于「这个邮箱在上个版本里没注册过」，
        // 是全链条里唯一一个真正泄露存在性的判据。
        $accepted = null !== $challenge
            && !$challenge->isExpiredAt($now)
            && !$challenge->isConsumed()
            && $challenge->hasAttemptsLeft($this->maxAttempts)
            && $challenge->codeHash()->equals($codeHash)
            && !$challenge->isDecoy();

        if (null !== $challenge) {
            // 成功也记一次（OtpChallenge::recordAttempt() 的注释点名了这条）：
            // 否则「码对了」与「码错了」在 attempts 这一列上留下的痕迹不同。
            //
            // ⚠️ 上限用完之后**仍然要调**，且仍然要写库 —— 「次数耗尽」这种拒绝
            // 必须与「码错了」做同样多的功。计数本身在实体里饱和，理由见那边的注释。
            $challenge->recordAttempt($this->maxAttempts);
            $this->challenges->save($challenge);
        }

        if (!$accepted) {
            $this->metrics->counter('login_total', ['result' => 'rejected']);
            $budget->settle();

            throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
        }

        // ⚠️ 从这里往下**不再填充耗时**，也不再隐藏任何东西。
        // 走到这一行需要正确的 6 位码，也就是调用方已经能读那个邮箱了。
        // 硬把成功路径也填到预算里，只会让每一次正常登录都打一行
        // "Constant-time budget overrun" —— 把 §3.8 的告警淹掉。
        return $this->issuer->issue($challenge, $payload->device, $now);
    }
}
