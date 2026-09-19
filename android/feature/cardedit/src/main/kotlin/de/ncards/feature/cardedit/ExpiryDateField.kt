package de.ncards.feature.cardedit

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle

/**
 * 到期日（§3.13：一期仅本地展示与排序，**不做推送提醒**）。
 *
 * ============================================================================
 * ⚠️ 全程 UTC，一个时区转换都不做
 * ============================================================================
 * `expires_on` 是 `DATE` —— 一个「哪一天」的概念，不是一个时刻。
 * Material3 的 `DatePickerState` 用的是 **UTC 午夜的 epoch millis**，
 * 所以这里两个方向都走 [ZoneOffset.UTC]。
 *
 * 用设备时区转换的话，柏林（UTC+1/+2）的用户选「12 月 31 日」会存成
 * 12 月 30 日 —— 而这个 bug 只在时区偏移为正的地方发作，也就是**只在德国发作**，
 * 开发机若设成 UTC 就永远看不见它。
 *
 * 展示走 `DateTimeFormatter.ofLocalizedDate(MEDIUM)` 而不是手搓 `dd.MM.yyyy`：
 * 德语是 `31.12.2026`、英语是 `31 Dec 2026`，而 §11.1 的语言由 per-app locales 决定。
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun ExpiryDateField(
    value: LocalDate?,
    onChange: (LocalDate?) -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    var showDialog by remember { mutableStateOf(false) }

    val text =
        value?.format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM))
            ?: stringResource(R.string.cardedit_expires_none)

    Box(modifier = modifier) {
        OutlinedTextField(
            value = text,
            onValueChange = {},
            readOnly = true,
            enabled = enabled,
            label = { Text(text = stringResource(R.string.cardedit_label_expires)) },
            trailingIcon = {
                // 只有选了日期才给「清除」—— §3.13 说 null 是合法且常见的值
                // （大多数会员卡不过期），所以清除必须是一步的事。
                if (value != null) {
                    TextButton(
                        onClick = { onChange(null) },
                        enabled = enabled,
                        modifier = Modifier.testTag(EXPIRY_CLEAR_TAG),
                    ) {
                        Text(text = stringResource(R.string.cardedit_expires_clear))
                    }
                }
            },
            modifier = Modifier.fillMaxWidth().testTag(EXPIRY_FIELD_TAG),
        )

        // 只读输入框本身不接收点击 —— 与 BarcodeFormatPicker 同一个处置。
        // ⚠️ 它**不能**盖住上面那个清除按钮，所以宽度让给 trailingIcon。
        Box(
            modifier =
                Modifier
                    .matchParentSize()
                    .testTag(EXPIRY_TRIGGER_TAG)
                    .clickable(enabled = enabled) { showDialog = true },
        )
    }

    if (showDialog) {
        val state =
            rememberDatePickerState(
                initialSelectedDateMillis = value?.atStartOfDay(ZoneOffset.UTC)?.toInstant()?.toEpochMilli(),
            )

        DatePickerDialog(
            onDismissRequest = { showDialog = false },
            confirmButton = {
                TextButton(
                    onClick = {
                        onChange(state.selectedDateMillis?.toUtcLocalDate())
                        showDialog = false
                    },
                ) {
                    Text(text = stringResource(R.string.cardedit_expires_confirm))
                }
            },
            dismissButton = {
                TextButton(onClick = { showDialog = false }) {
                    Text(text = stringResource(R.string.cardedit_expires_dismiss))
                }
            },
        ) {
            DatePicker(state = state)
        }
    }
}

/** ⚠️ UTC，不是设备时区。见类注释 —— 用本地时区会在德国把日期差一天。 */
private fun Long.toUtcLocalDate(): LocalDate = Instant.ofEpochMilli(this).atZone(ZoneOffset.UTC).toLocalDate()

internal const val EXPIRY_FIELD_TAG = "cardedit_expiry_field"
internal const val EXPIRY_TRIGGER_TAG = "cardedit_expiry_trigger"
internal const val EXPIRY_CLEAR_TAG = "cardedit_expiry_clear"
