<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Sharing\Domain\ValueObject;

use App\Module\Sharing\Domain\ValueObject\CardRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ============================================================================
 * ⚠️ 「取值域恰好是 owner|viewer」这件事**测不到这里**
 * ============================================================================
 * 想写的那两条断言是：.
 *
 *     self::assertSame(['owner', 'viewer'], array_map(fn ($c) => $c->value, CardRole::cases()));
 *     self::assertNull(CardRole::tryFrom('editor'));
 *
 * 两条对静态分析都是**恒真**的 —— phpstan 能把枚举的 cases 常量折叠，
 * 于是它们被报成 `staticMethod.alreadyNarrowedType`，而它确实什么都没证明：
 * 断言的两边是同一份源码。这与 T-109 给 `encryption_scheme` 记的是同一个坑
 * （见那张卡的落地记录）。
 *
 * 真正够得着的保证在两个地方，两个都不在单元层：
 *
 *   - 库层的 `CHECK (role IN ('owner','viewer'))` 由
 *     `SharingSchemaTest::testTheRoleCheckRejectsTheRemovedEditorRole()`
 *     对着**真库**断言 —— 插一行 `role='editor'` 会被拒。
 *   - 枚举 ↔ TEXT 的往返由
 *     `DoctrineCardMemberRepositoryTest::testTheRoleEnumIsStoredAsItsBackingString()`
 *     读**真列**来比。
 *
 * 所以本文件只留 `canEdit()` —— 那是一段真的有分支的逻辑。
 */
#[CoversClass(CardRole::class)]
final class CardRoleTest extends TestCase
{
    /**
     * `can_edit` 是契约要求客户端**用来 gate 编辑 UI** 的那个字段
     * （而不是自己比较角色字符串）。它由这一处算出来，
     * Wallet 侧一次角色比较都不写 —— 见 `CardMembership` 的类注释。
     *
     * §5.2 权限矩阵里「修改 title / barcode_value / 删卡」三行是同一个答案，
     * 它们共用这一个方法。
     */
    public function testOnlyTheOwnerCanEdit(): void
    {
        self::assertTrue(CardRole::Owner->canEdit());
        self::assertFalse(CardRole::Viewer->canEdit());
    }
}
