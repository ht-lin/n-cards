package de.ncards.core.barcode.format

/**
 * 一个码制对载荷的**声明式**要求 —— [BarcodeFormatTable.Row] 的第五列。
 *
 * ============================================================================
 * 为什么是数据，而不是一个 `(String) -> Boolean`
 * ============================================================================
 * 规则要能回答「**为什么**不行」，而不只是「行不行」——「EAN-13 要 12 或 13 位数字，
 * 你给了 11 位」对用户有用，「无效」没用。一个 lambda 只能给出后者。
 *
 * 而且做成数据之后，加第 14 个码制时 `rowOf` 那一处 `when` 会编译失败，
 * 新码制被迫连同它的规则一起补齐（ADR-0023 要的正是这条性质）。
 * 做成 lambda 的话它可以写成 `{ true }`，而那在 code review 里看不出问题。
 *
 * @property charset 允许的字符集。
 * @property exactLengths 允许的**精确**长度。`null` = 不限。
 *   ⚠️ EAN / UPC 是两个值而不是一个，因为 ZXing 的 writer **会自动补校验位** ——
 *   给 12 位它也能编出 EAN-13。见 [PayloadChecksum]。
 * @property maxLength 长度上限（字符）。`null` = 只受 §7.5 的 1024 字节上限约束。
 * @property requiresEvenLength ITF 独有：它把数字两两编成一组。
 * @property checksum 末位校验位的规则。
 */
internal data class PayloadRule(
    val charset: PayloadCharset,
    val exactLengths: Set<Int>? = null,
    val maxLength: Int? = null,
    val requiresEvenLength: Boolean = false,
    val checksum: PayloadChecksum = PayloadChecksum.NONE,
)

/**
 * 载荷允许的字符集。
 *
 * ⚠️ 判据写在 `BarcodePayloadValidator` 的一处 `when (charset)` 里。
 * 那**不违反** ADR-0023：被禁的是散落的 `when (format)`，
 * 而 charset 只有五个值、与码制数量无关 —— 加第 14 个码制不会给它加分支。
 */
internal enum class PayloadCharset {
    /** 只有 `0`–`9`。EAN / UPC / ITF。 */
    DIGITS,

    /**
     * 任意 ASCII（0–127）。
     *
     * ⚠️ Code 39 的**小写字母是合法的** —— T-152 实测：ZXing 会自动切到扩展 ASCII 模式，
     * 一个小写字母编成两个字符对。真正编不出来的是**非 ASCII**，
     * 而对德语市场来说那就是变音字母（`ä` / `ö` / `ü` / `ß`）—— 用户会真的输进来。
     */
    ASCII,

    /**
     * Codabar：数字与 `-$:/.+`，两端**可以**带起止符（`A`–`D` / `T`、`N`、`*`、`E`）。
     *
     * ⚠️ T-152 实测并点名交办的一条：**不带起止符是合法的**（writer 自动补 `A…A`），
     * 非法的是**落单的**那一个。它的原话：「T-155 若要求『必须带起止符』，
     * 那是我们自己的规则，不是 ZXing 的」—— 所以这里不要求。
     */
    CODABAR,

    /** 二维码：什么都能装，只受容量与 §7.5 的字节上限约束。 */
    ANY,

    /** [de.ncards.core.model.barcode.BarcodeFormat.UNKNOWN] 专用：什么都编不出来。 */
    NONE,
}

/** 末位校验位的规则。 */
internal enum class PayloadChecksum {
    NONE,

    /**
     * EAN / UPC 家族的 mod-10（权重 3-1-3-1…，从右往左）。
     *
     * ⚠️ **只在用户给满位数时才校验。** 少一位时 ZXing 自己会算出校验位补上，
     * 那是一条完全合法的输入路径 —— 商品包装上印的 EAN-13 是 13 位，
     * 而很多贴纸只印 12 位主体。两种都要收。
     */
    UPC_EAN_MOD_10,
}
