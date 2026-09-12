package de.ncards.core.model.barcode

/**
 * 一张卡的码制。**全仓唯一的词汇表**（§10.1、§12.3，T-152 交付）。
 *
 * ============================================================================
 * 为什么词汇表在这里，而三张映射表在 `core:barcode`
 * ============================================================================
 * §10.1 的原文是「格式映射表（ML Kit → ZXing → 展示名）必须集中在 `core:model`
 * 的单一 `BarcodeFormat` 枚举中」。字面执行**不可能**，两条各自足以否掉它：
 *
 * - ML Kit 的 `Barcode.FORMAT_*` 是 Android 常量，而本模块是 `ncards.jvm.library`
 *   ——「连 `android.*` 都 import 不到」是它刻意造成的结构约束，不是疏漏。
 * - 展示名必须住在 `strings.xml` 里（§11.1），而本模块没有 `res/`。
 *
 * 而 §12.3 自己又把 `core:barcode` 称作「格式映射**唯一实现处**」。两条要求的
 * 实质交集是：**枚举是键，表按键排一行**。于是本枚举定义词汇，
 * `core:barcode` 的 `BarcodeFormatTable` 用**一个** `when` 一次产出
 * 「ZXing 常量 / ML Kit int / 展示名」三列 —— 全仓只有那一处 `when (format)`，
 * 加第 14 个码制会在那一处编译失败，三列被迫同时补齐。
 * 这比字面照做更严格：三个各自穷举的 `when` 反而允许漏改其中一个。
 *
 * 形状与 [de.ncards.core.model.settings.AppLanguageStore] 相同 ——
 * 词汇定义在下游看得见的地方，Android 侧的实现从上游绑进来。
 *
 * ⚠️ **ZXing 放得进本模块，但不放。** `com.google.zxing:core` 是纯 JVM jar，
 * `implementation(libs.zxing.core)` 在这里是编译得过的。不这么做的理由是
 * `data:auth`、每个 `feature:*` 与 `:app` 都已经依赖本模块 —— 把一个编码库塞进
 * 它们的运行时类路径，并让 build.gradle.kts 里「纯 Kotlin 领域模型」那句注释变成
 * 假话，换来的只是少一个文件。见 ADR-0023。
 *
 * @property wireName 契约（`docs/api/openapi.yaml` 的 `BarcodeFormat`）与
 *   Room 的 `cards.barcode_format` **共用**的那个字符串，逐字一致。
 *   因此 [fromWire] 同时也是「从 Room 的 TEXT 列还原」，不要再写第二个函数。
 * @property dimension 一维还是二维。渲染器靠它决定静区宽度与「高度能不能自由拉伸」，
 *   因此 `core:barcode` 里不需要「哪些是一维码」的第二张表。
 */
enum class BarcodeFormat(
    val wireName: String,
    val dimension: BarcodeDimension,
) {
    // ------------------------------------------------------------------ 一维
    EAN_13("EAN_13", BarcodeDimension.ONE_D),
    EAN_8("EAN_8", BarcodeDimension.ONE_D),
    UPC_A("UPC_A", BarcodeDimension.ONE_D),
    UPC_E("UPC_E", BarcodeDimension.ONE_D),
    CODE_128("CODE_128", BarcodeDimension.ONE_D),
    CODE_39("CODE_39", BarcodeDimension.ONE_D),
    CODE_93("CODE_93", BarcodeDimension.ONE_D),
    ITF("ITF", BarcodeDimension.ONE_D),
    CODABAR("CODABAR", BarcodeDimension.ONE_D),

    // ------------------------------------------------------------------ 二维
    QR_CODE("QR_CODE", BarcodeDimension.TWO_D),
    AZTEC("AZTEC", BarcodeDimension.TWO_D),
    PDF_417("PDF_417", BarcodeDimension.TWO_D),
    DATA_MATRIX("DATA_MATRIX", BarcodeDimension.TWO_D),

    /**
     * 服务端给了一个本版本不认识的码制。
     *
     * ⚠️ 这是**第 14 个常量**，不是可空返回值 —— 所以 `entries.size == 14`
     * 而规格说「13 种码制」，两者都没写错。
     *
     * 契约对这一条是明确的：§13.6 允许服务端新增枚举值，**老客户端不得因此崩**，
     * 生成的 `core:network:api` 那份也有 `unknown_default_open_api` 兜底。
     * 建模成可空会被某处的 `!!` 消掉；建模成常量则出现在每一个穷举 `when` 里，
     * 逼每个消费方显式决定「这种卡长什么样」（渲染器给
     * `UnsupportedFormat`，UI 给兜底样式 + 大字号码值）。
     *
     * [wireName] 是空串：**它永远不会被写回契约或 Room**。`data:card` 保留服务端
     * 原样的字符串，本枚举只用于渲染与展示。
     */
    UNKNOWN("", BarcodeDimension.UNKNOWN),
    ;

    /** 能不能画出来。只有 [UNKNOWN] 不能 —— ZXing 没有对应的 writer。 */
    val isRenderable: Boolean get() = this != UNKNOWN

    companion object {
        /**
         * 码值载荷的字节上限，与契约的 `barcode_value.maxLength` 和后端
         * `ncards.limits.barcode_payload_bytes` 是同一个数（§7.5）。
         *
         * ⚠️ 单位是**字节**不是字符 —— 后端那行注释写得很清楚：码值是条码的原始载荷，
         * 可能含非 UTF-8 字节，按字符计会让一个合法的 1024 字节 PDF417 被拒。
         */
        const val MAX_PAYLOAD_BYTES: Int = 1024

        /** 十三种真码制，不含 [UNKNOWN]。UI 的「选择码制」下拉用这个。 */
        val renderable: List<BarcodeFormat> = entries.filter(BarcodeFormat::isRenderable)

        /**
         * 从契约值或 Room 的 TEXT 列还原。**认不出来就是 [UNKNOWN]**，不抛异常 ——
         * 理由见 [UNKNOWN] 的注释。
         *
         * 大小写敏感：契约里这些值全是大写，Room 里存的就是契约值。
         * 生成的 `core:network:api` 枚举的 `decode()` 是大小写不敏感的，
         * 这里**刻意**不跟随 —— 一个小写的 `ean_13` 进了库就是数据出了问题，
         * 悄悄认下来只会让问题更晚才暴露。
         */
        fun fromWire(raw: String?): BarcodeFormat = entries.firstOrNull { it.wireName == raw } ?: UNKNOWN
    }
}

/**
 * 码制的维度。决定渲染时的静区宽度，以及高度能不能自由拉伸。
 *
 * 一维码的高度不携带信息（每一行都一样），所以可以拉成 View 要的任意高度 ——
 * T-154 的「横屏放大 1D 码」正是靠这一条。二维码则必须保持符号本身的宽高比。
 */
enum class BarcodeDimension {
    ONE_D,
    TWO_D,

    /** 只属于 [BarcodeFormat.UNKNOWN]：画不出来，也就谈不上维度。 */
    UNKNOWN,
}
