package de.ncards.core.barcode.format

import de.ncards.core.barcode.render.BarcodeRasterizer
import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 码值能不能编成这个码制。`null` = 能。
 *
 * ============================================================================
 * 两步，缺一不可
 * ============================================================================
 * 1. **声明式规则**（[BarcodeFormatTable.Row.payload]）→ 产出带原因的问题。
 *    它覆盖用户真的会犯的那些错：位数、字符集、校验位、奇偶。
 * 2. 全过了就**真的编一次**。编不出来 → [BarcodePayloadProblem.NotEncodable]。
 *
 * 第二步不是保险起见，它是这个函数**正确性的定义**：本模块对外承诺的是
 * 「说合法就画得出来」。只有第一步的话，任何一条规则写漏都会变成一个
 * 「表单说没问题、全屏条码页一片空白」的投诉 —— 而那是最难查的一类，
 * 因为两处代码各自看起来都对。
 *
 * 只有第二步的话就退回 T-152 的现状：知道不行，说不出为什么。
 *
 * `BarcodePayloadValidatorTest` 把这条承诺写成了一条性质断言：
 * **`validateBarcodePayload(f, v) == null` 当且仅当 rasterizer 编得出 `(f, v)`**。
 * 两步的结构让它其中一个方向恒真，另一个方向由那条测试的语料守着。
 *
 * ⚠️ **长度上限（§7.5 的 1024 字节）不在这里判。** 那是契约与配额的事，对每个码制
 * 都一样，判据在 `core:model` 的 `CardDraft.problems()`。这里只管「这个码制编不编得出来」。
 */
fun validateBarcodePayload(
    format: BarcodeFormat,
    value: String,
): BarcodePayloadProblem? {
    val rule = BarcodeFormatTable.rowFor(format).payload

    ruleProblemOf(rule, value)?.let { return it }

    // 规则全过了 —— 让 ZXing 自己说了算。
    return if (BarcodeRasterizer.canEncode(format, value)) null else BarcodePayloadProblem.NotEncodable
}

/**
 * 第一步：声明式规则。
 *
 * `ReturnCount` 的豁免与 `BarcodeRasterizer.rasterize` 的那条同构：每个 return 对应
 * 一种互斥的结局，合并成一条只会得到一个嵌套六层的版本。
 */
@Suppress("ReturnCount")
private fun ruleProblemOf(
    rule: PayloadRule,
    value: String,
): BarcodePayloadProblem? {
    if (rule.charset == PayloadCharset.NONE) return BarcodePayloadProblem.UnsupportedFormat

    // ⚠️ `isEmpty` 而不是 `isBlank`：空格在 Code 39 / Code 128 里是合法载荷字符。
    // 与 `CardDraft.problems()` 里码值那条是同一个判据，理由写在那边。
    if (value.isEmpty()) return BarcodePayloadProblem.Empty

    illegalCharacterOf(rule.charset, value)?.let { return BarcodePayloadProblem.IllegalCharacters(it) }

    rule.exactLengths?.let { lengths ->
        if (value.length !in lengths) return BarcodePayloadProblem.WrongLength(lengths.sorted())
    }

    rule.maxLength?.let { max ->
        if (value.length > max) return BarcodePayloadProblem.TooLong(max)
    }

    if (rule.requiresEvenLength && value.length % 2 != 0) return BarcodePayloadProblem.OddLength

    if (rule.checksum == PayloadChecksum.UPC_EAN_MOD_10 && !checksumOk(rule, value)) {
        return BarcodePayloadProblem.ChecksumMismatch
    }

    return null
}

/**
 * 第一个不属于该字符集的字符，没有就是 `null`。
 *
 * ⚠️ 这里的 `when` 是 `when (charset)` 而**不是** `when (format)` ——
 * ADR-0023 禁的是后者。charset 只有五个值且与码制数量无关：
 * 加第 14 个码制不会给这里加分支，它只会在 `BarcodeFormatTable.rowOf` 里被迫选一个。
 */
private fun illegalCharacterOf(
    charset: PayloadCharset,
    value: String,
): String? {
    val offender =
        when (charset) {
            PayloadCharset.DIGITS -> value.firstOrNull { it !in '0'..'9' }
            PayloadCharset.ASCII -> value.firstOrNull { it.code > MAX_ASCII }
            PayloadCharset.CODABAR -> codabarOffenderOf(value)
            PayloadCharset.ANY, PayloadCharset.NONE -> null
        }

    return offender?.toString()
}

/**
 * Codabar：两端**可以**带起止符，中间只能是数字与 `-$:/.+`。
 *
 * ⚠️ T-152 实测并点名交办的一条：**不带起止符是合法的**（ZXing 的 writer 自动补 `A…A`）。
 * 非法的是**落单的**那一个 —— 只有开头带、或只有结尾带。
 * 它的原话：「T-155 若要求『必须带起止符』，那是我们自己的规则，不是 ZXing 的」。
 *
 * 落单的情形这里报成「那个起止符本身非法」：对用户说「』A』 在这里编不出来」，
 * 比说「起止符不成对」更容易照着改 —— 大多数人是从别处抄了一串带 `A` 的码值进来的。
 */
private fun codabarOffenderOf(value: String): Char? {
    val hasStart = value.first() in CODABAR_GUARDS
    val hasEnd = value.length > 1 && value.last() in CODABAR_GUARDS

    val body =
        if (hasStart && hasEnd) value.substring(1, value.length - 1) else value

    return body.firstOrNull { it !in CODABAR_BODY }
}

/**
 * EAN / UPC 的 mod-10：从右往左加权 3-1-3-1…，总和补到 10 的倍数。
 *
 * ⚠️ **少一位时不校验**，直接算合法 —— 那一位正是 ZXing 要替用户补上的。
 * 判据是「给满了没有」：位数等于 [PayloadRule.exactLengths] 里**大**的那个才校验。
 */
private fun checksumOk(
    rule: PayloadRule,
    value: String,
): Boolean {
    val full = rule.exactLengths?.maxOrNull()
    if (full == null || value.length != full) return true

    val digits = value.map { it - '0' }
    val sum =
        digits
            .dropLast(1)
            .reversed()
            .mapIndexed { index, digit -> if (index % 2 == 0) digit * ODD_POSITION_WEIGHT else digit }
            .sum()

    return (TEN - sum % TEN) % TEN == digits.last()
}

private const val MAX_ASCII = 127
private const val TEN = 10

/** mod-10 里从右数第 1、3、5… 位的权重。 */
private const val ODD_POSITION_WEIGHT = 3

/** ZXing 的 `CodaBarReader.STARTEND_ENCODING`，大小写都收。 */
private val CODABAR_GUARDS = "ABCDabcdTNtn*eE".toSet()

private val CODABAR_BODY = "0123456789-$:/.+".toSet()
