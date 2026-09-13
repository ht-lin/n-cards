package de.ncards.core.model.card

/**
 * 卡片调色板的**键**（Q7 / T-153）。
 *
 * ============================================================================
 * 为什么键在这里，而色值在 `core:designsystem`
 * ============================================================================
 * 与 [de.ncards.core.model.barcode.BarcodeFormat] 同构，且理由更硬一层：
 *
 * - **键是契约的一部分。** `docs/api/openapi.yaml` 的 `Card.color` 是一个自由
 *   字符串（「色值本身待设计交付（开放问题 Q7 / T-153），故此处不收窄成 enum」），
 *   后端 `Card.orm.xml` 也只当它是「预设调色板的键（如 `blue_600`）」。
 *   服务端从不知道 `blue_600` 到底是哪个蓝 —— 那是客户端的事。
 * - **色值是 Android 类型。** `androidx.compose.ui.graphics.Color` 在本模块
 *   import 不到（`ncards.jvm.library`，连 `android.*` 都够不着）。
 *
 * 这个分法换来的性质正是 Q7 需要的：**改色值不改枚举**。设计复核之后要换掉某个
 * 蓝，改的是 `core:designsystem` 的一个文件加重跑一次对比度测试，
 * 已经落库的 `blue_600` 一行都不用迁移，契约一个字都不用改。
 *
 * ============================================================================
 * ⚠️ Q7 的状态：**工程侧闭环，待设计复核**
 * ============================================================================
 * §17.5 把 Q7 的决策人写作「设计」，而设计没有交付。T-153 不能就这么停下 ——
 * 「颜色/首字母图标」是本卡的交付物，而 §11.2 要求每个卡片色**逐个校验**
 * ≥ 4.5:1 对比度。
 *
 * 所以色值由工程侧定，并且把「逐个校验」这件事做成了一条自动化断言
 * （`core:designsystem` 的 `CardColorContrastTest`）—— 设计后来换色值时，
 * 那条测试会当场告诉他新值过不过得了 §11.2。这比一份 PDF 上的色卡可靠。
 *
 * @property wireName 契约（`Card.color`）与 Room 的 `cards.color` **共用**的
 *   那个字符串，逐字一致。因此 [fromWire] 同时也是「从 Room 的 TEXT 列还原」。
 */
enum class CardColor(
    val wireName: String,
) {
    BLUE("blue_600"),
    INDIGO("indigo_600"),
    TEAL("teal_600"),
    GREEN("green_600"),
    OLIVE("olive_600"),
    ORANGE("orange_600"),
    RED("red_600"),
    PINK("pink_600"),
    PURPLE("purple_600"),
    BROWN("brown_600"),
    SLATE("slate_600"),
    ;

    companion object {
        /**
         * 新建卡时的默认色，同时也是 [fromWire] 认不出来时的兜底。
         *
         * 它是**第一个**，不是随便挑的：T-155 的颜色选择器按 `entries` 的顺序排，
         * 用户看到的第一格与「什么都不选」拿到的是同一个色，不会出现
         * 「我明明没选，怎么是绿的」。
         */
        val DEFAULT: CardColor = BLUE

        /**
         * 从契约值或 Room 的 TEXT 列还原。**认不出来就是 [DEFAULT]**，不抛异常。
         *
         * 为什么这里兜底到一个真色值，而 `BarcodeFormat.fromWire` 兜底到一个
         * `UNKNOWN` 常量 —— 两者不是同一类问题：
         *
         * - 认不出来的**码制**画不出来，UI 必须显式决定「这种卡长什么样」，
         *   所以 `UNKNOWN` 要出现在每一个穷举 `when` 里逼人表态（ADR-0023）。
         * - 认不出来的**颜色**画得出来，它只是好看不好看。为它增设一个
         *   `UNKNOWN` 常量，只会让每个 `when (color)` 多一个永远走不到的分支。
         *
         * ⚠️ 兜底**只影响渲染**。`data:card` 保留 Room 里服务端给的原样字符串，
         * 本枚举不参与写回 —— 否则一个装了新版本 App 的设备发来的
         * `mint_600`，会被老客户端在下一次编辑时静默改写成 `blue_600`。
         */
        fun fromWire(raw: String?): CardColor = entries.firstOrNull { it.wireName == raw } ?: DEFAULT
    }
}
