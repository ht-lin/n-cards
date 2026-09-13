package de.ncards.core.model.card

import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertSame
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test

@DisplayName("CardRole")
class CardRoleTest {
    @Test
    @DisplayName("wireName 与契约的 CardRole 逐字一致")
    fun wireNamesMatchTheContract() {
        assertSame(CardRole.OWNER, CardRole.fromWire("owner"))
        assertSame(CardRole.VIEWER, CardRole.fromWire("viewer"))
    }

    /**
     * §5.2 的权限矩阵一句话：「owner 是唯一的写入者；viewer 只有两项权利 ——
     * 看，和摆放自己钱包里的位置」。
     */
    @Test
    @DisplayName("只有 owner 能编辑")
    fun onlyOwnerCanEdit() {
        assertTrue(CardRole.OWNER.canEdit)
        assertFalse(CardRole.VIEWER.canEdit)
    }

    /**
     * 认不出角色时按**最小权限**处置。
     *
     * 反过来（兜底成可编辑）的后果是：一个本版本不认识的角色会拿到编辑入口，
     * 用户点下去收一个 403 —— 而 §7.3 要的正是「入口在 UI 层就不存在」。
     */
    @Test
    @DisplayName("认不出来的角色不能编辑，且不抛异常")
    fun unknownRoleCannotEdit() {
        assertSame(CardRole.UNKNOWN, CardRole.fromWire("editor"))
        assertSame(CardRole.UNKNOWN, CardRole.fromWire(null))
        assertFalse(CardRole.UNKNOWN.canEdit)
    }

    /**
     * `editor` 在 v1.1 里被删掉了（C1）。它出现在数据里就是**降级**，
     * 不是「一个我们还不支持的角色」—— 兜底到 [CardRole.UNKNOWN] 因此是对的：
     * 不能编辑。
     */
    @Test
    @DisplayName("v1.1 删掉的 editor 不再是合法角色")
    fun editorIsNoLongerARole() {
        assertFalse(CardRole.entries.any { it.wireName == "editor" })
    }
}
