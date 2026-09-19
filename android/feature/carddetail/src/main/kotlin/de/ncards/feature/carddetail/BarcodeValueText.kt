package de.ncards.feature.carddetail

import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.sp

/**
 * 码值那一块。**§10.2 的无障碍硬要求，不是可选项**：
 *
 * > 码值必须以**可选中的大字号文本**显示在条码下方，且带 `contentDescription`，
 * > 使 TalkBack 用户可以让系统朗读、口述给收银员。这是银发用户与视障用户的
 * > 关键功能，**不是可选项**。
 *
 * 详情页与全屏页共用这一个。
 *
 * ============================================================================
 * ⚠️ 可见文本是**原样的码值**，一个字符都不加
 * ============================================================================
 * 把 EAN-13 按 1-6-6 分成三组确实更好认 —— 但 [SelectionContainer] 里选中复制
 * 出来的就会是一串带空格的东西，而用户复制码值多半正是为了粘到别处用。
 * 可读性由 `letterSpacing` + 等宽字体 + 字号解决，那三样都不改变字符串本身。
 *
 * ============================================================================
 * ⚠️ [contentDescription] **不能**直接是码值
 * ============================================================================
 * TTS 会把 `4012345678901` 念成「四万零一百二十三亿……」——收银员听不懂，
 * 用户也没法照着念。而「念给收银员听」正是这个功能存在的全部理由。
 * 所以朗读串由 [spokenBarcodeValue] 逐字符拆开，见那个函数的注释。
 */
@Composable
internal fun BarcodeValueText(
    barcodeValue: String,
    modifier: Modifier = Modifier,
    color: Color = Color.Unspecified,
    style: TextStyle = LocalTextStyle.current,
) {
    val spoken = stringResource(R.string.carddetail_value_talkback, spokenBarcodeValue(barcodeValue))

    SelectionContainer(
        // ⚠️ 语义挂在**外层**，也就是调用方打 `testTag` 的那个节点，不是内层 `Text`。
        //
        // 挂在内层运行时是能用的（TalkBack 聚焦到那个 Text，念的就是朗读串），
        // 但「码值节点」在测试里和在 a11y 树里就指的不是同一个节点了：
        // `SelectionContainer` 与 `Text` 是两个 semantics 节点，前者又不合并子节点，
        // 于是 `onNodeWithTag(...).fetchSemanticsNode()` 拿不到 `ContentDescription`。
        // T-154 的 `valueCarriesASpokenContentDescription` 就是这么红的。
        //
        // `mergeDescendants` 把内层那串原样码值并进这一个节点，TalkBack 于是只念
        // 朗读串一遍 —— 而不是「朗读串」加「一个天文数字」两遍。
        // 可见文本与选中复制走的是指针事件，不受影响。
        modifier = modifier.semantics(mergeDescendants = true) { contentDescription = spoken },
    ) {
        Text(
            text = barcodeValue,
            style =
                style.copy(
                    // 等宽 + 字距：让「原样的字符串」也能一眼数清位数，
                    // 而不必靠插入分组空格（那会污染复制出来的值）。
                    fontFamily = FontFamily.Monospace,
                    letterSpacing = VALUE_LETTER_SPACING,
                    textAlign = TextAlign.Center,
                ),
            color = color,
        )
    }
}

/**
 * 码值 → 给 TTS 念的那一串。
 *
 * 纯函数，住在 UI 层（§10.4：ViewModel 不得 import Android 框架类，
 * 而这东西天然属于「怎么说给用户听」）。有单测钉住。
 *
 * ⚠️ **只对「短的、全是数字的」码值逐字符拆开。** 把一个 200 字符的 QR 网址
 * 或者一段 1024 字节的 PDF417 载荷拆成一个字符一个逗号，得到的是一段没人
 * 听得完的噪音，比不拆更糟。那两类码值本来也不是用来口述的 ——
 * 会员卡号才是（§1.3 的 North Star 场景）。
 *
 * 分隔用 `", "` 而不是空格：多数 TTS 引擎对空格分隔的数字串仍会尝试合并成
 * 一个数，逗号则强制它逐个念并留出停顿 —— 而停顿正是收银员跟着录入需要的。
 */
internal fun spokenBarcodeValue(barcodeValue: String): String =
    if (barcodeValue.length <= MAX_SPELLED_OUT_LENGTH && barcodeValue.all(Char::isDigit)) {
        barcodeValue.toCharArray().joinToString(separator = DIGIT_SEPARATOR)
    } else {
        barcodeValue
    }

/**
 * 逐字符朗读的长度上限。
 *
 * 20 覆盖了全部一维码制的实际位数（最长的 ITF/Code 128 会员号在这个量级以内），
 * 而二维码的载荷普遍远长于它。取一个具体的数而不是「按码制分」：
 * 码制只说明**能**装多长，说明不了这一张装了多长，而后者才是该不该逐字念的依据。
 */
private const val MAX_SPELLED_OUT_LENGTH = 20

private const val DIGIT_SEPARATOR = ", "

private val VALUE_LETTER_SPACING = 2.sp
