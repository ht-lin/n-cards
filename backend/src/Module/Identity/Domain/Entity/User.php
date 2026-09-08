<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\UserStatus;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `users`（§5.2 / §17.1）。
 *
 * ============================================================================
 * ⚠️ 这个类为什么不是 `final`，也没有 `#[ORM\Entity]`
 * ============================================================================
 * 两条都是被 deptrac 与 Doctrine 一起逼出来的，改之前先读 ADR-0011：
 *
 * 1. **没有属性映射**：`deptrac.yaml` 里 `Identity.Domain: [Shared.Domain]` 是一份
 *    空的框架允许列表。`#[ORM\Entity]` 会 import `Doctrine\ORM\Mapping\*`，
 *    落进 `Framework.Persistence` 图层 → violation。映射是 XML，在
 *    `Infrastructure/Doctrine/Mapping/User.orm.xml`。
 * 2. **不是 final**：Doctrine 的懒加载对象要继承实体类。`Device` 与 `Session`
 *    对 `User` 的关联是懒的，所以 `User` 必须可继承。
 *    本仓库其余地方一律 `final readonly`，这三个实体是有据可查的例外。
 *
 * ============================================================================
 * 不存明文邮箱列（§3.8）
 * ============================================================================
 * `email_hash` 是查找键（HMAC-SHA256 + Vault pepper，`HmacHasherInterface`），
 * `email_encrypted` 是发信用的密文（Vault Transit，`CryptoKey::Pii`）。
 * 两者都不是 `string`：{@see HashDigest} 与 {@see Ciphertext} 是挡住
 *「明文被写进这两列」的类型闸门，理由见它们各自的类注释。
 *
 * ============================================================================
 * `username` 为什么可空
 * ============================================================================
 * §5.2 给了完整论证，一句话版本：`POST /auth/otp/verify` 成功即建行（首次验证即注册），
 * 而那一刻用户还没设 username。三个备选方案里，「服务端随机生成」等于永久惩罚，
 * 「塞进 verify 请求体」强迫客户端提前收集，所以选了「允许 NULL + 应用层状态机」。
 *
 * 这条中间态的强制点**不在这个类里**：
 *   - `username IS NULL` 的用户被单一 Kernel 监听器拦成 `403 username_required`（T-108）
 *   - 格式校验与保留词黑名单归 `Domain\ValueObject\Username`（T-107）
 *   - 超过 7 天的僵尸行由每日清理任务物删（T-113）
 * 这里只管一条不变量：**写过一次就不能再写**（见 {@see assignUsername()}）。
 */
class User
{
    /**
     * `409 username_immutable` 的 detail —— **两个调用方共用一句话**。
     *
     * {@see assignUsername()} 抛它（`POST /v1/me/username` 打第二次），
     * `Application\Me\ProfileUpdatePayload` 也抛它（`PATCH /v1/me` 的请求体里
     * 出现了 `username` 字段，§6.2：**不静默忽略**）。
     *
     * ⚠️ 提成常量而不是各写一份：两处描述的是同一条产品不变量，
     * 文案分叉之后，客户端按 `code` 分支没问题，但日志与 Sentry 里会出现
     * 两句意思一样的话，读的人要花时间确认它们是不是同一件事。
     *
     * ⚠️ `PATCH /v1/me` 那一路**不**经过本类的 {@see assignUsername()}：
     * 那个方法的语义是「没设过就写进去」，对一个尚未设过 username 的调用者
     * 它会**真的写进去** —— 绕过 `Username::fromInput()` 的字符集校验、
     * 保留词表与 §7.5 的 10 次计数。详见 ProfileUpdatePayload 的类注释。
     */
    public const USERNAME_IMMUTABLE_DETAIL = 'The username has already been set and cannot be changed.';

    private ?string $username = null;

    /**
     * `POST /v1/me/username` 已经被这个用户打过几次（§7.5：10 次总计，T-107）。
     *
     * ⚠️ 它是**账号生命周期**的计数，不是滑动窗口，所以它在这张表上而不在 Redis：
     * §8.2 的 ROPA 规定限流计数只保留 24 小时，而这个计数必须跨越整个账号生命周期
     *（`config/packages/rate_limiter.yaml` 的页脚逐字登记了这条豁免）。
     * 完整论证与它为什么返回 422 而不是 429，见 ADR-0017。
     */
    private int $usernameAttempts = 0;

    private UserStatus $status;

    private ?\DateTimeImmutable $deletionRequestedAt = null;

    private \DateTimeImmutable $updatedAt;

    /**
     * ⚠️ 属性一律**不加 `readonly`**，`id` / `email_hash` / `created_at` 这些
     * 事实上不可变的也不加。理由不是风格：Doctrine 的懒加载对象（ORM 3 的
     * lazy ghost）要在实例已存在之后回填属性，而 readonly 属性一旦初始化就
     * 不能再被 reflection 写入。「事实不可变」由「没有 setter」保证，
     * 与 §17.1 里 username 的不可变性靠「没有 UPDATE 端点」保证是同一个做法。
     */
    private function __construct(
        private Uuid $id,
        private HashDigest $emailHash,
        private Ciphertext $emailEncrypted,
        private Locale $locale,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->status = UserStatus::default();
        $this->updatedAt = $createdAt;
    }

    /**
     * 首次 OTP 验证成功时建行（§6.3.1 —— 验证即注册）。
     *
     * ⚠️ **建出来的行 `username` 恒为 NULL**，这是 §5.2 的注册中间态，不是遗漏。
     * T-104 的集成测试会断言这一条。
     *
     * @param Uuid       $id             UUIDv7（`Shared\Domain\Identity\Uuid7Generator`）
     * @param HashDigest $emailHash      `HmacHasherInterface::hash(lower(trim(email)))`
     * @param Ciphertext $emailEncrypted `CryptoServiceInterface::encrypt(CryptoKey::Pii, ...)`
     */
    public static function register(
        Uuid $id,
        HashDigest $emailHash,
        Ciphertext $emailEncrypted,
        Locale $locale,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $emailHash, $emailEncrypted, $locale, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function emailHash(): HashDigest
    {
        return $this->emailHash;
    }

    public function emailEncrypted(): Ciphertext
    {
        return $this->emailEncrypted;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    /**
     * 注册是否已完成 —— `GET /v1/me` 的 `onboarding_complete` 字段（T-108）。
     */
    public function hasUsername(): bool
    {
        return null !== $this->username;
    }

    /**
     * **一次性**写入 username（§5.2）。
     *
     * @param string $normalized 已归一化的小写值。**本方法不做格式校验** ——
     *                           字符集、长度与保留词黑名单归 T-107 的
     *                           `Domain\ValueObject\Username`，库层的
     *                           `chk_users_username_format` 是第二道防线。
     *                           这里只守「写过一次就不能再写」这一条不变量。
     *
     * @throws DomainException `username_immutable`（409）—— 已经设过了
     */
    public function assignUsername(string $normalized, \DateTimeImmutable $now): void
    {
        if (null !== $this->username) {
            // ⚠️ 这里**必须**抛，不能静默忽略。T-107 的验收标准写明：
            // 设定后 `POST /v1/me/username` 与 `PATCH /v1/me`（含 username 字段）
            // **均**返回 409 —— 静默忽略会让客户端以为改成功了。
            //
            // detail 里不放两个 username 的值：§6.1 的 detail 会进日志与 Sentry，
            // 而 username 是可检索的公开伪名，没有必要顺手记一遍。
            throw new DomainException(ErrorCode::UsernameImmutable, self::USERNAME_IMMUTABLE_DETAIL);
        }

        $this->username = $normalized;
        $this->updatedAt = $now;
    }

    /**
     * 已用掉的设定次数（§7.5：10 次总计）。
     */
    public function usernameAttempts(): int
    {
        return $this->usernameAttempts;
    }

    /**
     * 记一次设定尝试。
     *
     * 形状照抄 {@see OtpChallenge::recordAttempt()}：
     * 到上限为止**饱和**，不无限累加。
     * 调用方（T-107 的 `AssignUsernameService`）会先 `enforceCanAdd()` 再调这里，
     * 所以饱和分支正常情况下走不到 —— 它防的是「有人日后加了第二个调用点却忘了先检查」，
     * 那时的后果是计数溢出 SMALLINT，而不是一个能被看见的错误。
     *
     * ⚠️ **不是每个请求都调这里。** 格式非法（422）与已设过（409）都不消耗次数：
     * §7.5 给这条限流的理由是「用于试探占用情况」，而一个格式非法的名字探不到
     * 任何占用。反过来，把 422 也计数意味着客户端本地校验的一个 bug 能在 10 次内
     * 把用户永久钉死在 onboarding（username 不可变，且没有第二条出路）。见 ADR-0017。
     *
     * @param int $max §7.5 的上限（10）。**由调用方传入**：这个数字是策略不是不变量，
     *                 真相在 `%ncards.limits.username_attempts_per_user%`
     */
    public function recordUsernameAttempt(int $max): void
    {
        if ($this->usernameAttempts < $max) {
            ++$this->usernameAttempts;
        }
    }

    public function locale(): Locale
    {
        return $this->locale;
    }

    /**
     * `PATCH /v1/me` 的 `locale` 字段（T-108）。
     */
    public function changeLocale(Locale $locale, \DateTimeImmutable $now): void
    {
        if ($locale === $this->locale) {
            return;
        }

        $this->locale = $locale;
        $this->updatedAt = $now;
    }

    /**
     * 邮箱密文的重加密（§5.3 的 rotate → rewrap 流程，T-404）。
     *
     * 只换密文，**不碰 `email_hash`** —— `ncards-hmac` 那把 key 绝不轮换
     * （见 `CryptoKey::isRotatable()`），换了所有既有行都查不回来。
     */
    public function rewrapEmail(Ciphertext $emailEncrypted, \DateTimeImmutable $now): void
    {
        $this->emailEncrypted = $emailEncrypted;
        $this->updatedAt = $now;
    }

    public function status(): UserStatus
    {
        return $this->status;
    }

    public function deletionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->deletionRequestedAt;
    }

    /**
     * 进入 §8.4 的删除宽限期。
     *
     * 幂等：重复请求不重置宽限期的起点 —— 否则用户（或一段重试逻辑）反复调用
     * 就能把删除无限期推迟下去。
     */
    public function requestDeletion(\DateTimeImmutable $now): void
    {
        if (UserStatus::PendingDeletion === $this->status) {
            return;
        }

        $this->status = UserStatus::PendingDeletion;
        $this->deletionRequestedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * 宽限期内撤销删除请求（§8.4）。
     */
    public function cancelDeletion(\DateTimeImmutable $now): void
    {
        if (UserStatus::PendingDeletion !== $this->status) {
            return;
        }

        $this->status = UserStatus::Active;
        $this->deletionRequestedAt = null;
        $this->updatedAt = $now;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
