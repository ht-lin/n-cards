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
 * ⚠️ 仍然缺席的两个字段
 * ============================================================================
 * 契约的 `Card` 还有 `member_count` 与 `owner_username`，它们**不在这里**，
 * 而且都不在 schema 的 `required` 里：
 *
 *   - `member_count` 来自 `card_members`，但 §5.2 要求它**只对 owner 可见**
 *     （viewer 拿到它就等于知道了还有几个人，C11）。M1 阶段它恒为 1，
 *     没有第二个值可发 —— 连同那条按角色裁剪的逻辑一起留给 T-305。
 *   - `owner_username` 要跨模块读 Identity 的 `UserDirectoryInterface`；
 *     M1 阶段 owner 恒为调用者本人，而他的用户名已经在 `GET /v1/me` 里了。
 *
 * ⚠️ **不要**为了「契约看起来完整」补上常量占位（`member_count: 1` 之类）。
 * 客户端会把那个值当成真的存进本地库，等它变成假的那天，本地与服务端不一致
 * 且没有任何信号提示要重拉。缺席则由客户端的 `ignoreUnknownKeys`
 * 与契约的可选性天然处理。
 *
 * （T-110 把 `sort_order` / `is_pinned` 补上了 —— 它们来自 `card_members`，
 * 而那张表现在存在了。`my_role` / `can_edit` 也从「恒为 owner」变成了
 * 一次真正的成员查找，见 {@see CardViewAssembler}。）
 */
final readonly class CardView
{
    /**
     * @param string      $barcodeValue 明文码值。**传输时是明文**（走 TLS），
     *                                  落库时是 Vault Transit 密文（§5.3）
     * @param string|null $note         明文备注
     * @param string      $myRole       `owner` / `viewer`（契约的 `CardRole`）。
     *                                  T-110 起来自 `card_members` 上调用者
     *                                  自己那一行，不再是推导出来的
     * @param bool        $canEdit      `myRole === 'owner'` 的便利镜像。契约要求
     *                                  客户端**用它**来 gate 编辑 UI，
     *                                  而不是自己比较角色字符串。
     *                                  ⚠️ 它由 **Sharing** 算好（`CardRole::canEdit()`）——
     *                                  Wallet 侧一次角色比较都不写
     * @param int         $sortOrder    **每成员私有**（`card_members`，§5.2）。
     *                                  走 `PUT /v1/cards/{id}/placement` 改，
     *                                  **不参与** `revision`
     * @param bool        $isPinned     每成员私有，同上
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
        public int $sortOrder,
        public bool $isPinned,
        public int $revision,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
