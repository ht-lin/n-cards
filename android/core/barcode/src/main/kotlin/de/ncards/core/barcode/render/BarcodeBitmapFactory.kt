package de.ncards.core.barcode.render

import android.graphics.Bitmap

/**
 * 把纯 Kotlin 的 [BarcodeRaster] 变成 `android.graphics.Bitmap`。
 *
 * 整个模块里**唯一**碰 `android.*` 的生产逻辑，而且只有一行 —— 这不是巧合，
 * 是为覆盖率门禁设计的边界：`:core:barcode` 不在 `Coverage.kt` 的
 * `COVERAGE_EXEMPT` 里，Kover 又只统计单元测试，所以格式映射、静区、整数缩放、
 * 像素展开全都留在 JVM 这一侧（[BarcodeRasterizer]），
 * [DefaultBarcodeRenderer] 则注入本接口，于是它自己也能被单测覆盖。
 *
 * ⚠️ 写成 `interface` + `object :` 而不是 `fun interface` + lambda：
 * AGP 9 的 lint 在 `fun interface` 的 SAM 转换上会崩
 * （`ExperimentalDetector` 抛 `NoSuchElementException`，整个 `lintAnalyzeDebug` 失败，
 * 而报错只说文件名不说行号）。`NetworkModule.provideSleeper` 是同一个绕法，
 * android/README.md 的「已知事项」里记着这条。
 */
internal interface BarcodeBitmapFactory {
    fun create(raster: BarcodeRaster): Bitmap
}

/**
 * 生产实现。
 *
 * `ARGB_8888` 而不是 `RGB_565`：后者能省一半内存且对纯黑白无损，但那个杠杆留给
 * T-254 —— Glance 的 `RemoteViews` 有 IPC 体积上限，到那时才有真实的取舍依据。
 */
internal object AndroidBarcodeBitmapFactory : BarcodeBitmapFactory {
    override fun create(raster: BarcodeRaster): Bitmap =
        Bitmap.createBitmap(raster.pixels, raster.width, raster.height, Bitmap.Config.ARGB_8888)
}
