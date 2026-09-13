package de.ncards.core.model.card

/**
 * 调用者**自己**在一张卡上的角色（§5.2 的权限矩阵）。
 *
 * v1.1 之后取值域只有 `owner | viewer` —— `editor` 已从规格里删掉（C1）。
 *
 * ⚠️ [UNKNOWN] 是第三个常量而不是可空返回，理由与
 * [de.ncards.core.model.barcode.BarcodeFormat.UNKNOWN] 逐字相同（ADR-0023）：
 * §13.6 允许服务端新增枚举值而老客户端不得因此崩，而建模成可空会被某处的 `!!`
 * 消掉。建模成常量，则每个穷举 `when` 都被迫显式决定「这种卡能不能编辑」——
 * 而那个答案必须是**不能**（见 [canEdit]）。
 *
 * @property wireName 契约 `CardRole` 与 Room 的 `card_members.role` 共用的字符串。
 */
enum class CardRole(
    val wireName: String,
) {
    OWNER("owner"),
    VIEWER("viewer"),

    /** 服务端给了一个本版本不认识的角色。[wireName] 是空串，它永远不写回。 */
    UNKNOWN(""),
    ;

    /**
     * 能不能改这张卡的内容。
     *
     * ============================================================================
     * ⚠️ UI **必须**用它来 gate 编辑入口，而不是自己比较角色字符串
     * ============================================================================
     * 契约里 `Card.can_edit` 那一条写着同样的话：「`my_role == owner` 的便利镜像。
     * 客户端**必须**用它来 gate 编辑 UI，不要自己比较角色字符串」。
     * 后端也是这么做的 —— ADR-0020 决策二把 `canEdit` 放在 Sharing 侧算完才出去，
     * 因为「§5.2 的权限矩阵是 Sharing 的业务」。
     *
     * §7.3 与 §4.4 要的是**入口在 UI 层就不存在**，不是「点了才报错」：
     * viewer 看不到编辑与删除按钮，而不是看得到、点下去收一个 403。
     *
     * [UNKNOWN] 返回 `false` 是**安全侧**的选择：认不出角色时按最小权限处置。
     * 服务端仍会独立强制（§3.5），这里错了也泄不出去，但会让用户看到一个
     * 按下去必然失败的按钮。
     */
    val canEdit: Boolean get() = this == OWNER

    companion object {
        /** 从契约值或 Room 的 TEXT 列还原。认不出来就是 [UNKNOWN]，不抛异常。 */
        fun fromWire(raw: String?): CardRole = entries.firstOrNull { it.wireName == raw } ?: UNKNOWN
    }
}
