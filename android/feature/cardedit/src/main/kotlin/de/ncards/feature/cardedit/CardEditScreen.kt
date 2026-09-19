package de.ncards.feature.cardedit

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import de.ncards.core.model.barcode.BarcodeDimension
import de.ncards.core.model.barcode.BarcodeFormat
import de.ncards.core.model.card.CardColor
import de.ncards.core.model.card.CardField
import de.ncards.core.ui.ErrorPane
import java.time.LocalDate

/**
 * 新增/编辑卡的表单（T-155）。
 *
 * 无状态：它只消费 [CardEditUiState]（§4.3 的分层图第一行）。
 *
 * ============================================================================
 * ⚠️ 保存按钮**永远可点**，不合法时点它会亮出红字
 * ============================================================================
 * 直觉做法是 `enabled = state.form.canSave`。那在这一屏是敌意的：
 * 表单有七个字段，禁用的按钮不告诉用户差在哪儿，而红字只在点过之后才出现 ——
 * 于是用户卡在一个按不动的按钮前，界面上没有任何提示。
 *
 * 这与钱包空状态那个「画出来但按不动」的按钮**不是**一回事：那边按钮背后
 * 根本没有功能（T-155 之前录入不存在），诚实的做法就是禁用；这边有功能，
 * 只是输入还不对。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun CardEditScreen(
    state: CardEditUiState,
    onBack: () -> Unit,
    onTitleChange: (String) -> Unit,
    onMerchantLabelChange: (String) -> Unit,
    onColorChange: (CardColor) -> Unit,
    onBarcodeFormatChange: (BarcodeFormat) -> Unit,
    onBarcodeValueChange: (String) -> Unit,
    onNoteChange: (String) -> Unit,
    onExpiresOnChange: (LocalDate?) -> Unit,
    onSave: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Scaffold(
        modifier = modifier,
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text =
                            stringResource(
                                if ((state as? CardEditUiState.Editing)?.mode == CardEditMode.EDIT) {
                                    R.string.cardedit_title_edit
                                } else {
                                    R.string.cardedit_title_create
                                },
                            ),
                    )
                },
                navigationIcon = {
                    TextButton(onClick = onBack, modifier = Modifier.testTag(EDIT_BACK_TAG)) {
                        Text(text = stringResource(R.string.cardedit_back))
                    }
                },
                actions = {
                    // 只在表单真的开着时给保存 —— Missing / Error 两屏没什么可保存的。
                    if (state is CardEditUiState.Editing) {
                        TextButton(
                            onClick = onSave,
                            // ⚠️ 唯一会禁用它的是「正在保存」，不是「表单不合法」。见类注释。
                            enabled = !state.isSaving,
                            modifier = Modifier.testTag(EDIT_SAVE_TAG),
                        ) {
                            Text(text = stringResource(R.string.cardedit_save))
                        }
                    }
                },
            )
        },
    ) { innerPadding ->
        Column(modifier = Modifier.fillMaxSize().padding(innerPadding)) {
            when (state) {
                // 一闪而过的一帧，画个空白比画一个转圈更不晃眼 ——
                // 与 CardDetailScreen 的同一处置。
                CardEditUiState.Loading -> {
                    Unit
                }

                is CardEditUiState.Editing -> {
                    CardEditFormBody(
                        form = state.form,
                        enabled = !state.isSaving,
                        onTitleChange = onTitleChange,
                        onMerchantLabelChange = onMerchantLabelChange,
                        onColorChange = onColorChange,
                        onBarcodeFormatChange = onBarcodeFormatChange,
                        onBarcodeValueChange = onBarcodeValueChange,
                        onNoteChange = onNoteChange,
                        onExpiresOnChange = onExpiresOnChange,
                    )
                }

                // ⚠️ Missing 不是 Error：这张卡被 owner 删了、或我被移出成员，
                // 全都是正常结局。用 ErrorPane 画它是因为形状合适（标题 + 正文 + 一个出路），
                // 文案口径是「它不在了」而不是「出错了」。见 CardEditUiState 的类注释。
                CardEditUiState.Missing -> {
                    ErrorPane(
                        title = stringResource(R.string.cardedit_missing_title),
                        message = stringResource(R.string.cardedit_missing_body),
                        modifier = Modifier.testTag(EDIT_MISSING_TAG),
                    )
                }

                is CardEditUiState.Error -> {
                    ErrorPane(
                        title = state.reason.title(),
                        message = state.reason.body(),
                        modifier = Modifier.testTag(EDIT_ERROR_TAG),
                    )
                }
            }
        }
    }
}

@Composable
private fun CardEditFormBody(
    form: CardEditForm,
    enabled: Boolean,
    onTitleChange: (String) -> Unit,
    onMerchantLabelChange: (String) -> Unit,
    onColorChange: (CardColor) -> Unit,
    onBarcodeFormatChange: (BarcodeFormat) -> Unit,
    onBarcodeValueChange: (String) -> Unit,
    onNoteChange: (String) -> Unit,
    onExpiresOnChange: (LocalDate?) -> Unit,
) {
    Column(
        modifier =
            Modifier
                .fillMaxSize()
                .verticalScroll(rememberScrollState())
                .padding(ScreenPadding),
        verticalArrangement = Arrangement.spacedBy(FieldGap),
    ) {
        FormField(
            value = form.title,
            onValueChange = onTitleChange,
            label = stringResource(R.string.cardedit_label_title),
            problem = form.problemOf(CardField.TITLE)?.message(),
            enabled = enabled,
            tag = TITLE_TAG,
            capitalize = true,
        )

        FormField(
            value = form.merchantLabel,
            onValueChange = onMerchantLabelChange,
            label = stringResource(R.string.cardedit_label_merchant),
            problem = form.problemOf(CardField.MERCHANT_LABEL)?.message(),
            enabled = enabled,
            tag = MERCHANT_TAG,
            capitalize = true,
        )

        Text(
            text = stringResource(R.string.cardedit_label_color),
            style = MaterialTheme.typography.labelLarge,
        )
        ColorPicker(
            selectedWire = form.colorWire,
            onSelect = onColorChange,
            enabled = enabled,
            modifier = Modifier.testTag(COLOR_PICKER_TAG),
        )

        BarcodeFormatPicker(
            selected = form.barcodeFormat,
            onSelect = onBarcodeFormatChange,
            enabled = enabled,
        )

        FormField(
            value = form.barcodeValue,
            onValueChange = onBarcodeValueChange,
            label = stringResource(R.string.cardedit_label_value),
            // 长度问题优先于编码问题 —— 先说最基础的那条。
            problem =
                form.problemOf(CardField.BARCODE_VALUE)?.message()
                    ?: form.visibleBarcodeProblem?.message(),
            enabled = enabled,
            tag = VALUE_TAG,
            // 纯数字码制给数字键盘：EAN / UPC / ITF 占了德国零售卡的绝大多数，
            // 而在全键盘上敲 13 位数字对银发用户是实打实的负担。
            // 判据走 BarcodeFormat 自己的 dimension/charset 语义的近似 ——
            // ⚠️ 这只影响**键盘**，不影响校验：用户仍可粘贴任何东西进来，
            // 合不合法由 core:barcode 说了算。
            numericKeyboard = form.barcodeFormat.dimension == BarcodeDimension.ONE_D,
        )

        FormField(
            value = form.note,
            onValueChange = onNoteChange,
            label = stringResource(R.string.cardedit_label_note),
            problem = form.problemOf(CardField.NOTE)?.message(),
            enabled = enabled,
            tag = NOTE_TAG,
            capitalize = true,
            // §3.13 的 note 是「条款、有效期、门槛」那类自由文本，2000 字符。
            // 单行输入框装不下它，而且用户会想分行。
            singleLine = false,
        )

        ExpiryDateField(
            value = form.expiresOn,
            onChange = onExpiresOnChange,
            enabled = enabled,
        )
    }
}

/**
 * 一个输入框 + 它的错误行。
 *
 * ⚠️ 错误文案走 `supportingText` 而不是在下面另画一行 `Text` ——
 * Material3 会把它接进输入框的无障碍节点，于是 TalkBack 聚焦到输入框时
 * **连错误一起读出来**。另画一行的话那句话是一个独立节点，
 * 视障用户要多划一次才会遇到它，而且不知道它属于哪个字段。
 */
@Composable
private fun FormField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    problem: String?,
    enabled: Boolean,
    tag: String,
    capitalize: Boolean = false,
    numericKeyboard: Boolean = false,
    singleLine: Boolean = true,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onValueChange,
        label = { Text(text = label) },
        isError = problem != null,
        supportingText = problem?.let { { Text(text = it) } },
        enabled = enabled,
        singleLine = singleLine,
        keyboardOptions =
            KeyboardOptions(
                capitalization = if (capitalize) KeyboardCapitalization.Sentences else KeyboardCapitalization.None,
                keyboardType = if (numericKeyboard) KeyboardType.Number else KeyboardType.Text,
            ),
        modifier =
            Modifier
                .fillMaxWidth()
                .heightIn(min = if (singleLine) 0.dp else MultilineMinHeight)
                .testTag(tag),
    )
}

private val ScreenPadding = 16.dp
private val FieldGap = 16.dp
private val MultilineMinHeight = 120.dp

internal const val EDIT_BACK_TAG = "cardedit_back"
internal const val EDIT_SAVE_TAG = "cardedit_save"
internal const val EDIT_MISSING_TAG = "cardedit_missing"
internal const val EDIT_ERROR_TAG = "cardedit_error"
internal const val TITLE_TAG = "cardedit_title"
internal const val MERCHANT_TAG = "cardedit_merchant"
internal const val VALUE_TAG = "cardedit_value"
internal const val NOTE_TAG = "cardedit_note"
internal const val COLOR_PICKER_TAG = "cardedit_color_picker"
