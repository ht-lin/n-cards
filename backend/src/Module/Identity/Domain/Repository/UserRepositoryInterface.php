<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Repository;

use App\Module\Identity\Domain\Entity\User;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * `users` 的持久化出口。
 *
 * ============================================================================
 * 为什么在 `Domain/Repository/` 而不是 `Application/Port/`
 * ============================================================================
 * `deptrac.yaml` 里 `Identity.Port: [Shared.Domain, Identity.Dto]` ——
 * **刻意不含本模块的 Domain**，注释写着「Port 是给别的模块用的契约，
 * 一旦签名里出现自家实体，调用方就等于间接拿到了本模块的 Domain」。
 * 本接口的签名里全是 {@see User}，放进 Port 会当场 violation。
 *
 * 给别的模块用的那个是另一回事：§12.2 画的 `Application/Port/UserDirectoryInterface`
 * （`findByUsername()` / `findById()`，返回 Dto 而不是实体），归 T-2xx 的 Social 模块。
 *
 * ============================================================================
 * 方法集刻意窄
 * ============================================================================
 * 只放后续任务**已确证**需要的四个。T-101 不知道 T-103 的限流要怎么查、
 * 也不知道 T-113 的清理任务要按什么条件删 —— 那些方法由那些任务自己加。
 * 现在替它们猜，猜错了就是一批没人用的死代码，而 §13.3 的覆盖率门禁会逼着
 * 有人给死代码补测试。
 */
interface UserRepositoryInterface
{
    /**
     * 落盘一个用户（新建或更新）。
     *
     * ⚠️ 实现会 `flush()`。跨多个仓储的写操作要自己包一层
     * `Shared\Application\Transaction\TransactionRunnerInterface::run()` ——
     * 典型场景是 T-104 的「建 user + 建 device + 建 session」，
     * 那三步必须同生共死。
     */
    public function save(User $user): void;

    /**
     * 落盘一个**刚设定了 username** 的用户，并把 `uq_users_username` 的唯一冲突
     * 翻译成 `409 username_taken`（T-107）。
     *
     * ============================================================================
     * 为什么是独立的方法，而不是在 save() 里翻译
     * ============================================================================
     * `save()` 抛裸 `UniqueConstraintViolationException` 是它**现有的契约**，
     * `DoctrineUserRepositoryTest::testRejectsADuplicateEmailHash()` 与
     * `testRejectsADuplicateNormalisedUsername()` 都钉着它。在 `save()` 里翻译
     * 会顺手改掉 `uq_users_email_hash` 那条路径的语义 —— 而 email_hash 撞车
     * 是 T-104 的并发注册问题，与本卡无关，静默变成一个 409 只会让它更难查。
     *
     * ============================================================================
     * 为什么翻译必须发生在仓储里
     * ============================================================================
     * deptrac 只允许 `Identity.Infrastructure` 看见 Doctrine，
     * 所以 Application 层**接不住** `UniqueConstraintViolationException`。
     * 这个限制是对的：唯一约束的名字是持久层的细节。
     *
     * ⚠️ 调用方必须先把别的改动（尤其是尝试计数）flush 掉再调这里 ——
     * Doctrine 在 flush 失败时会**关闭 EntityManager**，挤在同一次 flush 里的
     * 改动会一起丢。理由见 `AssignUsernameService` 的类注释。
     *
     * @throws \App\Shared\Domain\Error\DomainException `username_taken`（409）——
     *                                                  `uq_users_username` 冲突。其余唯一约束的冲突原样冒泡
     */
    public function saveNewUsername(User $user): void;

    public function findById(Uuid $id): ?User;

    /**
     * 按邮箱哈希查找 —— §3.8 下**唯一**能按邮箱找人的方式（不存明文列）。
     *
     * 调用方拿到 hash 的方式是 `HmacHasherInterface::hash(lower(trim($email)))`。
     * 归一化必须在算 hash 之前做，否则 `Anna@Example.com ` 与 `anna@example.com`
     * 会算出两个不同的摘要，同一个人能注册两次。
     *
     * 用在 T-103（判断该不该发信 / 建哑挑战）与 T-104（验证成功后 upsert）。
     */
    public function findByEmailHash(HashDigest $emailHash): ?User;

    /**
     * 按 username 精确查找。
     *
     * @param string $normalized **已归一化的小写值**。库里存的就是归一化后的形态
     *                           （§17.1），所以普通等值查询即等价于大小写不敏感查找，
     *                           不需要 `LOWER()` 也就不会丢掉唯一索引
     *
     * 用在 T-107（`409 username_taken` 的判定）与 Social 的好友检索。
     */
    public function findByUsername(string $normalized): ?User;

    /**
     * 物删「僵尸注册行」：`username IS NULL` 且 `created_at < $cutoff`（T-113）。
     *
     * §5.2 把这条列为 MUST：用户中途放弃注册，留下的只是一条邮箱哈希 + 一个密文，
     * 没有任何关联业务数据，所以物删而不是走 {@see \App\Module\Identity\Domain\ValueObject\UserStatus}
     * 的 `pending_deletion` 状态机（那是给**真有账号**的人准备的 30 天宽限期）。
     *
     * ============================================================================
     * ⚠️ 这个方法会让别人手里的 token 失效，这是设计的一部分
     * ============================================================================
     * ADR-0018 决定四：`AuthContext` 持有一个 user id **不**意味着那个 user 还存在
     * （access token 有最长 15 分钟寿命，而这里会在那期间把行删掉）。所以
     * `OnboardingState` 有 `UserUnknown` 那一格，而 onboarding 状态**不得缓存**。
     * 改这个方法的语义前先读那份 ADR。
     *
     * ============================================================================
     * ⚠️ 级联靠 DDL，不靠 ORM
     * ============================================================================
     * 实现是批量 DQL DELETE，**绕过 UnitOfWork** —— Doctrine 的 cascade 配置在这里
     * 一行都不生效。带走 `devices` / `sessions` 的是 `Version20260905101500.php` 里
     * 那两条 `ON DELETE CASCADE` 外键。集成测试专门钉了这一点，因为「换成逐条
     * `remove()` 会更安全」这个念头看起来很有道理，实际会变成一次 N+1 且语义不变。
     *
     * `cards.owner_id` 是 `ON DELETE RESTRICT`（`Version20260908182500.php:127`）。
     * 僵尸用户被 ADR-0018 的拦截器挡在所有建卡端点之外，不可能持有卡，所以这里
     * **不做**跨模块排除（§4.2 规则 5 禁止跨模块 JOIN）。万一那条不变量被破坏，
     * 整条语句会失败 —— 由 `Shared\Application\Cleanup\CleanupRunner` 接住，
     * 只损失这一个任务。
     *
     * @param \DateTimeImmutable $cutoff 严格小于它才删（`created_at < :cutoff`），
     *                                   于是「第 7 天」留下、「第 7 天零 1 秒」删掉
     *
     * @return int<0, max> 受影响行数
     */
    public function deleteZombieRegistrationsBefore(\DateTimeImmutable $cutoff): int;
}
