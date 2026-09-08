<?php

declare(strict_types=1);

namespace App\Module\Wallet\Domain\Entity;

use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Module\Wallet\Domain\ValueObject\EncryptionScheme;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * `cards`（§5.2 / §17.1）—— 一张会员卡。
 *
 * 类不是 `final`、映射在 XML 里、属性不加 `readonly`，理由见
 * {@see \App\Module\Identity\Domain\Entity\User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * ⚠️ `ownerId` 是一个 Uuid 列，**不是** `many-to-one` 关联
 * ============================================================================
 * 这与 ADR-0011 第 3 条（「外键必须建成 ORM 关联」）看起来矛盾，其实不是：
 * 那一条的适用范围是**模块内部**（ADR-0011 自己在末尾写明了）。
 * `cards.owner_id → users(id)` 是**跨模块**外键，而
 * `deptrac.yaml` 里 `Wallet.Domain: [Shared.Domain]` —— 没有 `Identity.Domain`。
 *
 * 库层的那条外键**照建不误**（§17.1 的 `ON DELETE RESTRICT` 是 §3.7 账号删除
 * 流程的护栏），只是它在 ORM 侧由
 * {@see \App\Shared\Infrastructure\Doctrine\CrossModuleForeignKeys} 补进
 * schema，不经过本类。完整论证见 ADR-0019。
 *
 * 顺带的好处：拿不到 `$card->getOwner()`，就写不出
 * `$card->getOwner()->getUsername()` —— §4.2 规则 5（禁止跨模块 JOIN）
 * 从此是结构性成立的，而不是靠自觉。要 owner 的用户名请走 Identity 的 Port。
 *
 * ============================================================================
 * ⚠️ `sort_order` 与 `is_pinned` **不在这个类上**
 * ============================================================================
 * 它们在 `card_members` 上（T-110），因为它们是**每成员私有**的：
 * 同一张共享卡，Anna 置顶、Bob 不置顶，互不影响（§5.2）。
 * 这是共享模型的必然结果，也是这张表最容易被加错字段的地方 ——
 * 往下面加一个 `private int $sortOrder` 就等于把共享功能做没了。
 *
 * ============================================================================
 * `revision` 由 Doctrine 管，不要手动 ++
 * ============================================================================
 * 它映射成 Doctrine 的 `<version>` 字段，UPDATE 时自动递增，并在 SQL 里带上
 * `WHERE revision = :old`（§5.4.3 的乐观锁）。所以本类**没有** `bumpRevision()`
 * —— 有的话就会出现「改了字段但忘了 bump」与「bump 了两次」两种漂移。
 *
 * ⚠️ 两个后果：
 *   1. INSERT 语句**不含** `revision` 列（Doctrine 的 `prepareUpdateData()`
 *      跳过 version 字段），所以迁移里的 `DEFAULT 1` 是**必需**的，不是装饰。
 *   2. 一个什么都没改的 `PATCH` 不产生 UPDATE，因此 `revision` **不动** ——
 *      这是对的：客户端手里的副本已经与服务端一致。
 *      （但改 `barcode_value` 为同一个明文**会**动：Vault Transit 每次加密
 *      产生不同密文，那一列必然是脏的。）
 *
 * ============================================================================
 * 明文永远不进这个类
 * ============================================================================
 * `barcodeValueEncrypted` / `noteEncrypted` 是 {@see Ciphertext}，
 * `barcodeValueFingerprint` 是 {@see HashDigest} —— 两个类型都是挡住
 * 「明文被写进这些列」的闸门（见它们各自的类注释）。
 * 加解密由 Application 层做（§5.3 的批量解密必须在那一层编排，
 * 见 `CryptoServiceInterface` 的类注释），本类只搬运密文。
 */
class Card
{
    private ?string $merchantLabel = null;

    private ?HashDigest $barcodeValueFingerprint = null;

    private ?Ciphertext $noteEncrypted = null;

    private ?\DateTimeImmutable $expiresOn = null;

    private EncryptionScheme $encryptionScheme;

    /**
     * 乐观锁版本（§5.4.3）。**由 Doctrine 写**，见类注释。
     *
     * 初值是 1 而不是 0：`Card::create()` 之后、flush 之前也要能读出一个
     * 与库里一致的值，而库里的 `DEFAULT` 就是 1。
     */
    private int $revision = 1;

    private ?\DateTimeImmutable $deletedAt = null;

    private \DateTimeImmutable $updatedAt;

    /**
     * 属性不加 `readonly` 的理由见 {@see \App\Module\Identity\Domain\Entity\User::__construct()}。
     */
    private function __construct(
        private Uuid $id,
        private Uuid $ownerId,
        private string $title,
        private string $color,
        private BarcodeFormat $barcodeFormat,
        private Ciphertext $barcodeValueEncrypted,
        private \DateTimeImmutable $createdAt,
    ) {
        $this->encryptionScheme = EncryptionScheme::default();
        $this->updatedAt = $createdAt;
    }

    /**
     * `POST /v1/cards`（§5.4.3）。
     *
     * ⚠️ `$id` 是**客户端生成**的 UUIDv7，不是服务端分配的。这是离线优先的前提：
     * 用户在超市地下层没网时加的卡，本地就已经有了最终 id。
     * 因此服务端**不能假设它没被猜到或伪造** —— 归属判断一律看 `owner_id`，
     * 口径同 {@see \App\Module\Identity\Domain\Entity\Device} 的 `id`。
     *
     * @param Ciphertext $barcodeValueEncrypted   `CryptoServiceInterface::encrypt(CryptoKey::Card, …)`
     * @param HashDigest $barcodeValueFingerprint `HmacHasherInterface::hash($barcodeValue)`
     * @param string     $color                   预设调色板的键（如 `blue_600`）。
     *                                            值域待 Q7 / T-153 交付，故服务端不收窄
     */
    public static function create(
        Uuid $id,
        Uuid $ownerId,
        string $title,
        ?string $merchantLabel,
        string $color,
        BarcodeFormat $barcodeFormat,
        Ciphertext $barcodeValueEncrypted,
        HashDigest $barcodeValueFingerprint,
        ?Ciphertext $noteEncrypted,
        ?\DateTimeImmutable $expiresOn,
        \DateTimeImmutable $now,
    ): self {
        $card = new self($id, $ownerId, $title, $color, $barcodeFormat, $barcodeValueEncrypted, $now);

        $card->merchantLabel = $merchantLabel;
        $card->barcodeValueFingerprint = $barcodeValueFingerprint;
        $card->noteEncrypted = $noteEncrypted;
        $card->expiresOn = $expiresOn;

        return $card;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function ownerId(): Uuid
    {
        return $this->ownerId;
    }

    /**
     * 归属判断的**唯一**入口。
     *
     * 提成方法而不是让调用点写 `$card->ownerId()->equals($auth->userId)`：
     * `PATCH` / `DELETE` 的 `403 insufficient_role` 与 `GET` 的
     * `403 not_a_member` 都要问同一个问题，而问错的症状（比较了错的一侧、
     * 或者用 `===` 比两个 Uuid 对象）是**静默放行**。
     */
    public function isOwnedBy(Uuid $userId): bool
    {
        return $this->ownerId->equals($userId);
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * ⚠️ 长度不在这里校验。§7.5 的 `title_chars`（100）由
     * `Shared\Domain\Limit\LimitEnforcer::enforceLength()` 在 Application 层挡，
     * 库层的 `CHECK (char_length(title) <= 100)` 是第二道防线 ——
     * 口径同 {@see \App\Module\Identity\Domain\Entity\User::assignUsername()}。
     */
    public function rename(string $title, \DateTimeImmutable $now): void
    {
        if ($title === $this->title) {
            return;
        }

        $this->title = $title;
        $this->updatedAt = $now;
    }

    public function merchantLabel(): ?string
    {
        return $this->merchantLabel;
    }

    public function changeMerchantLabel(?string $merchantLabel, \DateTimeImmutable $now): void
    {
        if ($merchantLabel === $this->merchantLabel) {
            return;
        }

        $this->merchantLabel = $merchantLabel;
        $this->updatedAt = $now;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function changeColor(string $color, \DateTimeImmutable $now): void
    {
        if ($color === $this->color) {
            return;
        }

        $this->color = $color;
        $this->updatedAt = $now;
    }

    public function barcodeFormat(): BarcodeFormat
    {
        return $this->barcodeFormat;
    }

    /**
     * 只改格式，不动码值。
     *
     * 这是一个合法的独立操作：同一串数字被扫成 `CODE_128` 还是 `ITF`
     * 是渲染方式的选择，用户改过来时码值一个字节都没变。
     */
    public function changeBarcodeFormat(BarcodeFormat $barcodeFormat, \DateTimeImmutable $now): void
    {
        if ($barcodeFormat === $this->barcodeFormat) {
            return;
        }

        $this->barcodeFormat = $barcodeFormat;
        $this->updatedAt = $now;
    }

    public function barcodeValueEncrypted(): Ciphertext
    {
        return $this->barcodeValueEncrypted;
    }

    public function barcodeValueFingerprint(): ?HashDigest
    {
        return $this->barcodeValueFingerprint;
    }

    /**
     * 换码值。
     *
     * ⚠️ 密文与指纹**只能一起换**，所以它们是同一个方法的两个参数而不是两个 setter。
     * 分开的话就有可能出现「指纹还是旧码值的」这种行 —— 而指纹不可逆，
     * 事后没有任何办法从库里发现它对不上（§5.2：它的用途是重复卡检测）。
     *
     * ⚠️ 这里**不比较是否相等**：Vault Transit 每次加密都带新的随机 nonce，
     * 同一个明文两次加密得到不同密文，`equals()` 恒为 false。
     * 真正的「值有没有变」只有 Application 层在明文侧才知道 ——
     * 而它没有理由为此多解一次密：重新加密一次同样的码值是无害的
     * （代价是 `revision` 会 +1）。
     */
    public function changeBarcodeValue(
        Ciphertext $barcodeValueEncrypted,
        HashDigest $barcodeValueFingerprint,
        \DateTimeImmutable $now,
    ): void {
        $this->barcodeValueEncrypted = $barcodeValueEncrypted;
        $this->barcodeValueFingerprint = $barcodeValueFingerprint;
        $this->updatedAt = $now;
    }

    public function noteEncrypted(): ?Ciphertext
    {
        return $this->noteEncrypted;
    }

    /**
     * @param Ciphertext|null $noteEncrypted `null` 表示清空备注（契约里 `note` 可空）
     */
    public function changeNote(?Ciphertext $noteEncrypted, \DateTimeImmutable $now): void
    {
        // 与 changeBarcodeValue() 同理：非 null 时不比较密文。
        // 但 null → null 是可以短路的，那一路没有密文参与。
        if (null === $noteEncrypted && null === $this->noteEncrypted) {
            return;
        }

        $this->noteEncrypted = $noteEncrypted;
        $this->updatedAt = $now;
    }

    public function expiresOn(): ?\DateTimeImmutable
    {
        return $this->expiresOn;
    }

    /**
     * @param \DateTimeImmutable|null $expiresOn 一个**日期**（§3.13）。
     *                                           调用方负责把时间部分归零，见 `CardCreatePayload`
     */
    public function changeExpiry(?\DateTimeImmutable $expiresOn, \DateTimeImmutable $now): void
    {
        if ($expiresOn == $this->expiresOn) {
            // `==` 而不是 `===`：两个 DateTimeImmutable 表示同一时刻时
            // `===` 恒为 false（它们是不同的对象）。这是本仓库里唯一一处
            // 刻意用 `==` 比对象的地方。
            return;
        }

        $this->expiresOn = $expiresOn;
        $this->updatedAt = $now;
    }

    public function encryptionScheme(): EncryptionScheme
    {
        return $this->encryptionScheme;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function deletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }

    /**
     * `DELETE /v1/cards/{id}` —— **软删**（§5.2：写 `deleted_at`，90 天后硬删）。
     *
     * 幂等：已经删过的卡再删一次是 no-op，不重置 `deleted_at`。
     * 口径同 {@see \App\Module\Identity\Domain\Entity\User::requestDeletion()}。
     * 实际上走不到 —— 仓储的每一条查询都带 `deleted_at IS NULL`，
     * 所以第二次 `DELETE` 在服务层就是 404。这里守的是「有人日后加了第二个调用点」。
     *
     * ⚠️ **不清空任何密文列。** 90 天的硬删窗口内这张卡还要能被恢复
     * （§8.4 的账号删除宽限期同理），而且 §5.4.3 的墓碑同步（T-201）
     * 需要这一行还在。
     */
    public function softDelete(\DateTimeImmutable $now): void
    {
        if (null !== $this->deletedAt) {
            return;
        }

        $this->deletedAt = $now;
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
