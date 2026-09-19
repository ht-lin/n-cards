package de.ncards.core.barcode.format

/**
 * 码值**编不出来的原因**。
 *
 * ============================================================================
 * 为什么原因要带着，而不是一个布尔
 * ============================================================================
 * T-152 只做到了「ZXing 抛了 → `InvalidPayload`」，并在落地记录里点名把带原因的版本
 * 交给本卡。差别在用户那一端：「无效」让人无从下手，
 * 而「EAN-13 要 12 或 13 位数字，你给了 11 位」让人当场改对。
 * 这是个**手输**表单 —— 输错是常态，不是异常路径。
 *
 * ⚠️ 它是 sealed 而不是字符串：§10.4 要求 ViewModel 不得 `getString`，
 * 文案由 UI 层映射（`feature:cardedit` 的 `CardEditMessages.kt`）。
 * 带上的那些参数（位数、上限、非法字符样例）是给文案填空用的。
 */
sealed interface BarcodePayloadProblem {
    /** 一个字符都没有。 */
    data object Empty : BarcodePayloadProblem

    /** [BarcodeFormat.UNKNOWN] —— 本版本画不出这种码，也就无从校验。 */
    data object UnsupportedFormat : BarcodePayloadProblem

    /**
     * 字符集不对。
     *
     * @property sample 第一个非法字符，原样带回来给文案用（「』ä』 在 Code 39 里编不出来」）。
     *   ⚠️ 只带**一个字符**，不带整串码值：码值在 §14.4 的脱敏清单上，
     *   而一个字符不足以还原它。调用方仍然不得把它记进日志。
     */
    data class IllegalCharacters(
        val sample: String,
    ) : BarcodePayloadProblem

    /**
     * 位数不对（EAN / UPC）。
     *
     * @property expected 允许的位数，升序。两个值是常态而不是笔误 —— 见 [PayloadChecksum]。
     */
    data class WrongLength(
        val expected: List<Int>,
    ) : BarcodePayloadProblem

    /** 太长。ZXing 的一维 writer 家族封在 80 个字符。 */
    data class TooLong(
        val maxLength: Int,
    ) : BarcodePayloadProblem

    /** ITF 把数字两两编成一组，所以位数必须是偶数。 */
    data object OddLength : BarcodePayloadProblem

    /** 给满了位数，但最后一位不是正确的校验位。 */
    data object ChecksumMismatch : BarcodePayloadProblem

    /**
     * 声明式规则全过了，但 ZXing 仍然编不出来。
     *
     * 今天已知的唯一一类是**二维码的容量超限**（QR / PDF417 / Aztec / DataMatrix 的
     * 容量取决于纠错级别与编码模式，算不出一个能写进表里的上限）。
     *
     * ⚠️ 它是刻意的兜底而不是遗漏：见 [validateBarcodePayload] 的第二步。
     */
    data object NotEncodable : BarcodePayloadProblem
}
