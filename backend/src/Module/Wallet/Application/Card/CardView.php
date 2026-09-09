<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\Entity\Card;
use App\Shared\Domain\Identity\Uuid;

/**
 * 一张卡在**调用者视角**下的样子（契约的 `Card` schema）。
 *
 * ============================================================================
 * 为什么是扁平标量而不是捎上 Card 实体
 * ============================================================================
 * 与 {@see \App\Module\Identity\Application\Me\UserProfile} 逐字同源：
 * deptrac 里 `Wallet.Http` **看不到** `Wallet.Domain`，控制器拿不到
 * {@see Card} 也读不了它的 getter。把实体塞进这个 DTO，控制器编译不过 ——
 * 于是「控制器不许有业务」从约定变成了机械强制。
 *
 * 还有第二个理由，这里比 Identity 那边更重要：本 DTO 装的是**明文码值**。
 * 让它只能由 {@see CardViewAssembler} 构造，就保证了「谁解的密」只有一个答案，
 * 而那个答案是批量解密（§5.3 的硬要求）。
 *
 * ============================================================================
 * ⚠️ T-109 里缺席的四个字段
 * ============================================================================
 * 契约的 `Card` 还有 `sort_order` / `is_pinned` / `member_count` /
 * `owner_username`，它们**都不在这里**，而且都不在 schema 的 `required` 里：
 *
 *   - 前三个来自 `card_members`（T-110）。
 *   - `owner_username` 要跨模块读 Identity；T-109 阶段 owner 恒为调用者本人，
 *     而他的用户名已经在 `GET /v1/me` 的响应里了。
 *
 * ⚠️ **不要**为了「契约看起来完整」补上常量占位（`sort_order: 0` 之类）。
 * 客户端会把那个 0 当成用户真实的排序存进本地库，T-110 上线后本地与服务端
 * 不一致，且没有任何信号提示要重拉。缺席则由客户端的 `ignoreUnknownKeys`
 * 与契约的可选性天然处理。
 */
final readonly class CardView
{
    /**
     * @param string      $barcodeValue 明文码值。**传输时是明文**（走 TLS），
     *                                  落库时是 Vault Transit 密文（§5.3）
     * @param string|null $note         明文备注
     * @param string      $myRole       `owner` / `viewer`（契约的 `CardRole`）。
     *                                  T-109 阶段恒为 `owner`：没有成员表，
     *                                  能看到这张卡的只有它的 owner
     * @param bool        $canEdit      `myRole === 'owner'` 的便利镜像。契约要求
     *                                  客户端**用它**来 gate 编辑 UI，
     *                                  而不是自己比较角色字符串
     */
    public function __construct(
        public Uuid $id,
        public string $title,
        public ?string $merchantLabel,
        public string $color,
        public string $barcodeFormat,
        public string $barcodeValue,
        public ?string $note,
        public ?\DateTimeImmutable $expiresOn,
        public Uuid $ownerId,
        public string $myRole,
        public bool $canEdit,
        public int $revision,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
