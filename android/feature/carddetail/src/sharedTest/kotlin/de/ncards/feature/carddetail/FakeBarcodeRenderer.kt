package de.ncards.feature.carddetail

import de.ncards.core.barcode.render.BarcodeRenderRequest
import de.ncards.core.barcode.render.BarcodeRenderResult
import de.ncards.core.barcode.render.BarcodeRenderer

/**
 * [BarcodeRenderer] 的替身：给它什么结果它就返回什么。
 *
 * ⚠️ 它**不重现** ZXing 的任何行为。真的渲染由 `core:barcode` 的单测覆盖
 * （13 种码制逐个 render → decode 往返，纯 JVM），在这里再算一遍条码
 * 只能证明「我把那段逻辑又抄了一遍」。这一层要测的是**四种结局分别长什么样**，
 * 尤其是三种失败态下码值是不是**仍然显示** —— 那是 §10.2 的硬要求。
 *
 * 住在 `src/sharedTest`：`Success` 要一个真 `Bitmap`，只有仪器测试造得出来，
 * 而三种失败态在纯 JVM 单测里就够用。
 */
class FakeBarcodeRenderer(
    private var result: BarcodeRenderResult = BarcodeRenderResult.UnsupportedFormat,
) : BarcodeRenderer {
    /** 最后一次收到的请求。用来断言「尺寸是 View 的实际像素」。 */
    var lastRequest: BarcodeRenderRequest? = null
        private set

    fun returns(value: BarcodeRenderResult) {
        result = value
    }

    override suspend fun render(request: BarcodeRenderRequest): BarcodeRenderResult {
        lastRequest = request
        return result
    }
}
