<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Me;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Username;
use App\Module\Identity\Domain\ValueObject\UsernameRules;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Shared\Domain\Limit\SystemLimit;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/me/username` —— **一次性**设定 username（§3.8、§5.2、§6.2，T-107）。
 *
 * ============================================================================
 * 这是 onboarding 中间态唯一的出口
 * ============================================================================
 * `POST /auth/otp/verify` 成功即建行，而那一行的 `username` 恒为 NULL（§5.2）。
 * T-108 的 Kernel 监听器会把这种用户拦在除三个端点之外的所有 `/v1` 之外，
 * 本端点是其中之一，也是唯一能让它离开那个状态的那个。
 *
 * 换句话说：本服务挂了，全站没有一个新用户能进钱包。
 *
 * ============================================================================
 * ⚠️ 不可变性**不由本类保证**
 * ============================================================================
 * 保证它的是两件结构性的事实：
 *   1. 契约里**没有** `PATCH` / `PUT /v1/me/username`（§6.2 逐字写了这句）；
 *   2. {@see User::assignUsername()} 在 `username` 已非空时抛 `username_immutable`，
 *      所以经由本服务的每一条路径都会撞上同一条不变量。
 * 本类下面第 2 步的显式检查是为了**不白白消耗**一次尝试次数，不是防线本身。
 *
 * ⚠️ 第 2 条**不覆盖** `PATCH /v1/me`（T-108）。那个端点收到 `username` 字段时
 * 在 {@see ProfileUpdatePayload::fromArray()} 的第一行就抛了，**没有**走到实体 ——
 * 因为 `assignUsername()` 对一个尚未设过 username 的调用者会真的把值写进去，
 * 绕过校验、查重与本服务的 10 次计数。换句话说：那条不变量只保护
 * **已经设过 username 的人**，靠它来兜住一个新的写入路径是不成立的 ——
 * 正确的做法是根本不新增写入路径。完整论证见 ADR-0018 决定五。
 *
 * ============================================================================
 * ⚠️ 顺序不是随便排的：哪些失败消耗 10 次预算
 * ============================================================================
 * §7.5 给「按 user 10 次总计」的理由是「用于试探占用情况」。据此：
 *
 *   - `409 username_immutable`（已经设过了）**不消耗**：探不到任何东西，
 *     而且这条路径一旦达成就永远是它 —— 消耗的话计数会被无意义地打满。
 *   - `422 username_invalid`（格式 / 保留词）**不消耗**：一个过不了
 *     `^[a-z0-9_]{3,20}$` 的字符串在库里不可能存在，问它「被占了吗」得不到信息。
 *     更要紧的是反面：把它计数意味着客户端本地预校验（T-010/T-151）的一个 bug
 *     能在 10 次之内把用户**永久**钉死在 onboarding —— username 不可变、
 *     而 T-108 的拦截器把注销路径也挡在外面，于是那个账号再也没有出路。
 *   - `409 username_taken` **消耗**：它是唯一真的探到了占用情况的那条路径。
 *   - 成功也消耗一次（无所谓：此后端点永久 409）。
 *
 * 用尽时返回 **422 `limit_exceeded`** 而不是 429 —— 契约强制 429 同时带
 * `Retry-After`，而这个计数永不恢复，只能编一个假秒数让客户端永远退避重试。
 * 完整论证见 ADR-0017。
 *
 * ============================================================================
 * ⚠️ 两次 flush 是有意的，别合并
 * ============================================================================
 * 第 5 步（记次数）必须先落库，然后才是第 6/7 步（查重与写入）。
 * Doctrine 在 flush 失败时会**关闭 EntityManager**：两者挤在同一次 flush 里的话，
 * 撞上 `uq_users_username` 的那一路会把次数一起丢掉 ——
 * 于是这条限流对**唯一真正在试探的人**恰好失效，而所有测试照常绿。
 *
 * 这与 T-104 把 `otp_challenges.attempts` 放在事务外保存是同一条论证。
 * 代价是一次成功的设定要两次 UPDATE；在一个每个账号一生只走一次的路径上，
 * 这笔开销不值一提。
 */
final readonly class AssignUsernameService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private UsernameRules $rules,
        private LimitEnforcer $limits,
        private ClockInterface $clock,
        private MetricsInterface $metrics,
    ) {
    }

    /**
     * @throws DomainException `username_immutable`（409）/ `username_invalid`（422）
     *                         / `limit_exceeded`（422）/ `username_taken`（409）
     */
    public function assign(AuthContext $auth, UsernamePayload $payload): UserProfile
    {
        $user = $this->users->findById($auth->userId);

        if (!$user instanceof User) {
            // 令牌验过签（AuthenticationListener），所以这一行**必然**存在 ——
            // 除非它在本次请求的窗口里被 T-113 的清理任务删掉了。
            // 那不是客户端能修的东西，也不该是 404：对调用方来说，
            // 它拿着一个有效令牌却被告知自己不存在。
            throw new DomainException(ErrorCode::InternalError, 'An unexpected error occurred.');
        }

        // ① 已经设过了 —— 不消耗次数（见类注释）。
        if ($user->hasUsername()) {
            $this->metrics->counter('username_set_total', ['result' => 'immutable']);

            throw new DomainException(ErrorCode::UsernameImmutable, User::USERNAME_IMMUTABLE_DETAIL);
        }

        // ② 归一化 + 校验 —— 不消耗次数。抛 422 username_invalid。
        try {
            $username = Username::fromInput($payload->username, $this->rules);
        } catch (DomainException $e) {
            $this->metrics->counter('username_set_total', ['result' => 'invalid']);

            throw $e;
        }

        // ③ 预算。enforceCanAdd 传的是**新增之前**的存量：已用 9 次时放行
        // （这是第 10 次），已用 10 次时抛 422 limit_exceeded。
        $max = $this->limits->max(SystemLimit::UsernameAttemptsPerUser);

        try {
            $this->limits->enforceCanAdd(SystemLimit::UsernameAttemptsPerUser, $user->usernameAttempts());
        } catch (DomainException $e) {
            $this->metrics->counter('username_set_total', ['result' => 'exhausted']);

            throw $e;
        }

        // ④ 记一次，**并立刻落库**。见类注释「两次 flush」。
        $user->recordUsernameAttempt($max);
        $this->users->save($user);

        $normalized = $username->toString();

        // ⑤ 预查只是为了给用户一个干净的 409；它有 TOCTOU，真防线是下面
        // saveNewUsername() 背后的 uq_users_username。两者都要有：
        // 只留预查会漏并发，只留唯一索引则每次重名都要走一次失败的 INSERT
        // 并让 Doctrine 关掉 EntityManager。
        if ($this->users->findByUsername($normalized) instanceof User) {
            $this->metrics->counter('username_set_total', ['result' => 'taken']);

            throw new DomainException(ErrorCode::UsernameTaken, 'The username is already taken.');
        }

        $user->assignUsername($normalized, $this->clock->now());

        try {
            $this->users->saveNewUsername($user);
        } catch (DomainException $e) {
            // 只可能是 username_taken（并发撞车）—— saveNewUsername 只翻译那一种。
            $this->metrics->counter('username_set_total', ['result' => 'taken']);

            throw $e;
        }

        $this->metrics->counter('username_set_total', ['result' => 'set']);

        return UserProfile::of($user);
    }
}
