package de.ncards.feature.carddetail

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import de.ncards.core.barcode.format.displayNameRes
import de.ncards.core.barcode.render.BarcodeRenderer
import de.ncards.core.model.card.Card
import de.ncards.core.ui.ErrorPane
import de.ncards.core.ui.ErrorPaneRetry

/**
 * 全屏条码页的界面（T-154）。
 *
 * ============================================================================
 * ⚠️ 承载它的 `Activity` 在 `:app`，不在本模块
 * ============================================================================
 * §10.2 要它是一个**独立 `Activity`**「便于设置窗口属性」，而那个 Activity 必须是
 * `AppCompatActivity`（per-app locales 在 API < 33 上只对 AppCompat 组件生效），
 * 而 androidx.appcompat 按 `libs.versions.toml` 的明令**只给 `:app`**。
 * 所以窗口那一半（亮度、`FLAG_KEEP_SCREEN_ON`、`FLAG_SECURE`、manifest）在 `:app`，
 * 界面这一半在这里。完整理由见 `de.ncards.barcode.FullscreenBarcodeActivity`。
 *
 * 这也是本文件里的东西是 `public` 而 `CardDetailScreen` 是 `internal` 的原因。
 */
@Composable
fun FullscreenBarcodeRoute(
    state: CardDetailUiState,
    renderer: BarcodeRenderer,
    onClose: () -> Unit,
    onBarcodeDrawn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    FullscreenBarcodeScreen(
        state = state,
        renderer = renderer,
        onClose = onClose,
        onBarcodeDrawn = onBarcodeDrawn,
        modifier = modifier,
    )
}

@Composable
internal fun FullscreenBarcodeScreen(
    state: CardDetailUiState,
    renderer: BarcodeRenderer,
    onClose: () -> Unit,
    onBarcodeDrawn: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier =
            modifier
                .fillMaxSize()
                // 整页纯白，包括系统栏后面那一圈 —— 见 BarcodeColors.kt。
                .background(BarcodeWhite)
                .safeDrawingPadding()
                .testTag(FULLSCREEN_TAG),
    ) {
        when (state) {
            CardDetailUiState.Loading -> {
                CircularProgressIndicator(
                    color = BarcodeBlack,
                    modifier = Modifier.align(Alignment.Center),
                )
            }

            CardDetailUiState.Missing -> {
                // 卡在全屏页开着的时候被删掉了（owner 删卡 / 我被移出成员）。
                // 这不是错误，说清楚并给一条出路。
                FullscreenNotice(
                    title = stringResource(R.string.carddetail_missing_title),
                    body = stringResource(R.string.carddetail_missing_body),
                    onClose = onClose,
                    modifier = Modifier.testTag(FULLSCREEN_MISSING_TAG),
                )
            }

            is CardDetailUiState.Error -> {
                FullscreenNotice(
                    title = state.reason.title(),
                    body = state.reason.message(),
                    onClose = onClose,
                    modifier = Modifier.testTag(FULLSCREEN_ERROR_TAG),
                )
            }

            is CardDetailUiState.Content -> {
                FullscreenBarcode(
                    card = state.card,
                    renderer = renderer,
                    onClose = onClose,
                    onBarcodeDrawn = onBarcodeDrawn,
                )
            }
        }
    }
}

@Composable
private fun FullscreenBarcode(
    card: Card,
    renderer: BarcodeRenderer,
    onClose: () -> Unit,
    onBarcodeDrawn: () -> Unit,
) {
    Column(modifier = Modifier.fillMaxSize()) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = ScreenPadding),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                text = card.title,
                style = MaterialTheme.typography.titleMedium,
                color = BarcodeBlack,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f),
            )

            // 没有图标库（目录里 material-icons-* 零声明，见 SyncStateBadge 的
            // 注释），所以「关闭」是一个文字按钮 —— 它本来就带可读标签，
            // 比一个需要额外 contentDescription 的字形叉更省事也更清楚。
            TextButton(onClick = onClose, modifier = Modifier.testTag(FULLSCREEN_CLOSE_TAG)) {
                Text(text = stringResource(R.string.fullscreen_close), color = BarcodeBlack)
            }
        }

        BarcodeSurface(
            card = card,
            renderer = renderer,
            onBarcodeDrawn = onBarcodeDrawn,
            // weight 而不是固定高度：横竖屏共用这一段代码，条码那一块拿走
            // 剩下的全部空间，于是横屏时一维码自然更宽更高 ——
            // 「横屏放大 1D 码」就是这么来的，不需要第二套布局。
            modifier = Modifier.fillMaxWidth().weight(1f),
        )

        Column(
            modifier = Modifier.fillMaxWidth().padding(ScreenPadding),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(ValueSpacing),
        ) {
            BarcodeValueText(
                barcodeValue = card.barcodeValue,
                color = BarcodeBlack,
                style = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.testTag(FULLSCREEN_VALUE_TAG),
            )

            Text(
                text = stringResource(card.barcodeFormat.displayNameRes),
                style = MaterialTheme.typography.labelLarge,
                color = BarcodeBlack,
                textAlign = TextAlign.Center,
            )
        }
    }
}

@Composable
private fun FullscreenNotice(
    title: String,
    body: String,
    onClose: () -> Unit,
    modifier: Modifier = Modifier,
) {
    ErrorPane(
        title = title,
        message = body,
        retry =
            ErrorPaneRetry(
                label = stringResource(R.string.fullscreen_close),
                onClick = onClose,
            ),
        modifier = modifier,
    )
}

private val ScreenPadding = 16.dp
private val ValueSpacing = 4.dp

internal const val FULLSCREEN_TAG = "fullscreen_barcode"
internal const val FULLSCREEN_VALUE_TAG = "fullscreen_barcode_value"
internal const val FULLSCREEN_CLOSE_TAG = "fullscreen_barcode_close"
internal const val FULLSCREEN_MISSING_TAG = "fullscreen_barcode_missing"
internal const val FULLSCREEN_ERROR_TAG = "fullscreen_barcode_error"
