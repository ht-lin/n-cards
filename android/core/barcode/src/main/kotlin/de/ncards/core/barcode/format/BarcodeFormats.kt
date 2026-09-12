package de.ncards.core.barcode.format

import androidx.annotation.StringRes
import de.ncards.core.model.barcode.BarcodeFormat

// BarcodeFormatTable 对外的那一小块。表本身是 internal，因为 ZXing 与 ML Kit 的类型
// 不该越过模块边界（§12.3：core:barcode 是「格式映射唯一实现处」）。
// 上游只需要两件事：把码制显示成人看得懂的名字，以及把扫描结果映射回领域词汇。

/**
 * 码制的展示名资源 id。
 *
 * 返回 `@StringRes` 而不是 `String`，因为 §10.4 规定 `ViewModel` **不得** import
 * Android framework 类，资源引用一律用资源 id 或 `UiText`。
 */
@get:StringRes
val BarcodeFormat.displayNameRes: Int
    get() = BarcodeFormatTable.rowFor(this).displayNameRes

/**
 * 把 ML Kit 的 `Barcode.getFormat()` 映射回领域词汇。
 *
 * §10.1 明令相机路径（T-156）与图片路径（T-157）**必须**经过同一个入口，
 * 「禁止为图片路径写第二套 `when` 分支」—— 这个函数就是那个入口。
 *
 * 认不出来给 [BarcodeFormat.UNKNOWN]（ML Kit 升版可能返回新码制），
 * 调用方据此提示「这张卡我们还画不出来」，而不是崩。
 */
fun barcodeFormatOfMlKit(mlKitFormat: Int): BarcodeFormat = BarcodeFormatTable.formatForMlKit(mlKitFormat)
