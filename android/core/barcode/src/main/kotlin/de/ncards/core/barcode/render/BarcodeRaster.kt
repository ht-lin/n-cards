package de.ncards.core.barcode.render

/**
 * 一张已经展开成像素的条码，**还没有变成 `android.graphics.Bitmap`**。
 *
 * 这个类型存在的理由是覆盖率门禁：`:core:barcode` 不在 `Coverage.kt` 的
 * `COVERAGE_EXEMPT` 里，而 Kover **只统计单元测试**。所以从格式映射、静区、
 * 整数缩放一直到这里的 ARGB 数组全是纯 Kotlin（不 import `android.*`），
 * 在 JVM 单测里跑得动；真正碰 Android 的只剩 [BarcodeBitmapFactory] 那三行。
 *
 * 顺带的好处是 render → decode 的往返测试可以直接把 [pixels] 喂给 ZXing 的
 * `RGBLuminanceSource`，不需要设备、不需要 Robolectric。
 *
 * @property pixels 逐行排列的 ARGB_8888 像素，长度恰为 `width * height`。
 *   取值只有两个：[BarcodeRasterizer.WHITE] 与 [BarcodeRasterizer.BLACK]。
 * @property moduleSizePx 一个模块占多少像素。整数 —— 非整数是条码边缘模糊、
 *   扫码枪读不出的直接原因（§10.1）。测试靠它断言游程宽度。
 */
internal data class BarcodeRaster(
    val pixels: IntArray,
    val width: Int,
    val height: Int,
    val moduleSizePx: Int,
) {
    // IntArray 是数组，data class 自动生成的 equals/hashCode 比的是引用。
    // ktlint/detekt 都会点名这一点，而且「两张像素一样的图不相等」在测试里会很意外。
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is BarcodeRaster) return false
        return width == other.width &&
            height == other.height &&
            moduleSizePx == other.moduleSizePx &&
            pixels.contentEquals(other.pixels)
    }

    override fun hashCode(): Int {
        var result = pixels.contentHashCode()
        result = 31 * result + width
        result = 31 * result + height
        result = 31 * result + moduleSizePx
        return result
    }
}
