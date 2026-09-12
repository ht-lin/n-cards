package de.ncards.core.barcode.render

import android.graphics.Bitmap
import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 条码渲染的**唯一**入口（§10.1、§12.3，T-152）。
 *
 * 返回 `android.graphics.Bitmap` 而不是 Compose 的 `ImageBitmap`：消费方有两个 ——
 * T-154 的全屏页用 Compose，T-254 的 Glance widget 用 `ImageProvider` —— 它们唯一的
 * 公约数就是 Bitmap。本模块因此刻意**不**应用 `ncards.android.compose`
 * （那个插件的类注释把 `core:barcode` 列在「不含 UI」那一组里）。
 *
 * 实现跑在 `Dispatchers.Default` 上，并按 `format + value + size` 缓存（§10.1）。
 */
interface BarcodeRenderer {
    suspend fun render(request: BarcodeRenderRequest): BarcodeRenderResult
}

/**
 * 一次渲染请求。
 *
 * @param widthPx 目标宽度。**必须是 View 的实际像素宽**（§10.1：按目标 View 尺寸生成，
 *   不要生成小图再放大 —— 边缘模糊会导致扫码枪读不出）。
 * @param heightPx 目标高度。一维码会自由拉伸到它；二维码按符号本身的宽高比走，
 *   实际输出可能比它小，见 [BarcodeRenderResult.Success]。
 */
data class BarcodeRenderRequest(
    val format: BarcodeFormat,
    val value: String,
    val widthPx: Int,
    val heightPx: Int,
) {
    /**
     * ⚠️ **刻意重写，不要删。**
     *
     * `data class` 自动生成的 `toString()` 会把 [value] —— 也就是码值本身，
     * §14.4 脱敏清单点名的字段 —— 带进任何一条 `Timber.d(request)`。
     * 而 `scripts/ci/check-sensitive-logs.sh` 只认 `barcodeValue` / `rawValue` /
     * `barcode_value` 这几个**标识符名**，看不见一个叫 `request` 的变量。
     */
    override fun toString(): String = "BarcodeRenderRequest($format, ${value.length} chars, ${widthPx}x$heightPx)"
}

/**
 * 渲染结果。
 *
 * 形状与 `core:network:impl` 的 `ApiResult` 同构，理由也一样：**「这张卡画不出来」
 * 在离线优先的 App 里是一种数据状态，不是异常**。建模成异常，第一个后果就是
 * 有人写 `catch` 然后吞掉，用户对着一张空白的全屏页站在收银台前。
 */
sealed interface BarcodeRenderResult {
    /**
     * ⚠️ `bitmap.width` / `bitmap.height` **可能小于请求尺寸** —— 二维码保持符号本身的
     * 宽高比，且模块宽一律取整（非整数模块宽正是 §10.1 要防的那种模糊）。
     * 调用方用 `ContentScale.Fit` + `Alignment.Center` 摆放即可，**不要**再拉伸它：
     * 把 101 px 的图拉到 120 px 就又把整数模块宽毁掉了。
     *
     * 尺寸只有 [bitmap] 这一个真相源 —— 单独再放一份 `widthPx` 只会制造两者不一致的可能。
     */
    data class Success(
        val bitmap: Bitmap,
    ) : BarcodeRenderResult

    /**
     * [BarcodeFormat.UNKNOWN]：服务端加了一个本版本不认识的码制（§13.6 允许）。
     *
     * UI 该给兜底样式 + 大字号可选中的码值 —— §10.2 的无障碍硬要求本来就要求
     * 码值以大字号显示在条码下方，这里只是没有上半部分。
     */
    data object UnsupportedFormat : BarcodeRenderResult

    /**
     * 码值与码制不匹配：校验位、长度、字符集、容量超限……
     *
     * **不带原因**是刻意的：ZXing 的异常消息是没有文档、会随版本变的英文串，
     * 而且其中几条**嵌着码值片段**（见 [BarcodeRasterizer]）。逐字段的原因与德语
     * 文案归 T-155，落点仍在本模块 —— 不要在 `feature:cardedit` 里另起一套。
     */
    data object InvalidPayload : BarcodeRenderResult

    /**
     * 目标尺寸放不下这么多模块。强行渲染只会得到一张糊的图，那正是 §10.1 禁止的。
     *
     * 带上建议尺寸，调用方才知道「要多大」而不只是「不行」——
     * T-154 的横屏放大正需要据此决定是提示旋转还是换布局。
     */
    data class TooSmall(
        val minimumWidthPx: Int,
        val minimumHeightPx: Int,
    ) : BarcodeRenderResult
}
