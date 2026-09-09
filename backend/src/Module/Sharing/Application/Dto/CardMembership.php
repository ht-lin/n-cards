<?php

declare(strict_types=1);

namespace App\Module\Sharing\Application\Dto;

/**
 * 一个人在一张卡上的成员事实，**给别的模块看的那一份**（T-110）。
 *
 * ============================================================================
 * ⚠️ 为什么 `role` 是 string 而不是 `CardRole`
 * ============================================================================
 * deptrac 里 `Sharing.Dto: [Shared.Domain]` —— 它**看不见** `Sharing.Domain`。
 * 所以那个枚举放不进这里，而在这里再定义一个同名枚举就成了两份要同步的取值域。
 *
 * 选择是：枚举**只有一份**，在 `Sharing\Domain\ValueObject\CardRole`
 * （实体要用它，「owner|viewer」是领域不变量），本 DTO 带的是它算好的结果。
 * 值恒为契约 `CardRole` 的两个之一，调用方可以直接发出去。
 *
 * ============================================================================
 * ⚠️ `canEdit` 在**这一侧**算好，不留给调用方比较字符串
 * ============================================================================
 * 契约把 `can_edit` 定义成「`my_role` 的便利镜像」，并要求客户端用它来 gate
 * 编辑 UI 而不是自己比角色。那面镜子属于拥有角色语义的模块（§5.2 的权限矩阵
 * 是 Sharing 的业务），所以它在 `CardRole::canEdit()` 里算完才进这个 DTO。
 *
 * 收益是 Wallet 侧一次角色比较都不用写 —— `CardViewAssembler` 只搬字段，
 * `CardBody` 那句「本类不做任何判断」也继续为真。M3 加角色时，
 * 「什么角色能编辑」只有一处要改。
 *
 * ============================================================================
 * 这里**没有**的字段
 * ============================================================================
 *   - `userId`：每次查询已经限定了一个人（reader 收 `$userId`），带回去只会
 *     诱导调用方拿单人查询的结果去拼一张按人分组的表。
 *   - `joinedAt` / `addedBy` / `leftAt`：那是 T-305 的成员列表端点要的，
 *     不是「渲染一张卡」要的。§5.2 的成员可见性（C11）对那些字段有额外的
 *     裁剪规则，让它们从这条缝里漏出去会绕开那些规则。
 *   - 任何 `Card` 类型：Sharing 侧不出现 Wallet 的类型（§4.2 规则 1）。
 */
final readonly class CardMembership
{
    /**
     * @param string $role      契约的 `CardRole`：`owner` / `viewer`
     * @param bool   $canEdit   `CardRole::canEdit()` 的结果
     * @param int    $sortOrder 每成员私有（§5.2）
     * @param bool   $isPinned  每成员私有（§5.2）
     */
    public function __construct(
        public string $role,
        public bool $canEdit,
        public int $sortOrder,
        public bool $isPinned,
    ) {
    }
}
