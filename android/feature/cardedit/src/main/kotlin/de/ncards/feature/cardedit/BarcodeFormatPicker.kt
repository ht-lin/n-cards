package de.ncards.feature.cardedit

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import de.ncards.core.barcode.format.displayNameRes
import de.ncards.core.model.barcode.BarcodeFormat

/**
 * 「选择码制」下拉。
 *
 * ⚠️ 列表取 [BarcodeFormat.renderable] 而不是 `entries` —— 后者带着
 * [BarcodeFormat.UNKNOWN]，而那不是一个用户能选的东西（画不出来）。
 * `BarcodeFormat` 的 KDoc 里那句「UI 的「选择码制」下拉用这个」说的就是本处。
 *
 * ⚠️ 展示名走 `core:barcode` 的 [displayNameRes]，**不要**在这里写第二套
 * `when (format)` —— ADR-0023 的全部价值就是全仓只有一处那样的 `when`。
 *
 * 用只读的 `OutlinedTextField` 而不是 `ExposedDropdownMenuBox`：后者在
 * Material3 的不同小版本之间 API 一直在动（`menuAnchor` 的签名换过两次），
 * 而这里要的只是「点一下弹出一个列表」。
 */
@Composable
internal fun BarcodeFormatPicker(
    selected: BarcodeFormat,
    onSelect: (BarcodeFormat) -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    var expanded by remember { mutableStateOf(false) }

    Box(modifier = modifier) {
        OutlinedTextField(
            value = stringResource(selected.displayNameRes),
            onValueChange = {},
            readOnly = true,
            enabled = enabled,
            label = { Text(text = stringResource(R.string.cardedit_label_format)) },
            modifier =
                Modifier
                    .fillMaxWidth()
                    .testTag(FORMAT_PICKER_TAG)
                    // ⚠️ 语义与 testTag 必须在**同一个**节点上（T-154 的两条红断言
                    // 就是栽在这件事上）。这里两者都挂在这个 Modifier 链上，
                    // 所以它们落在同一个语义节点。
                    .semantics { role = Role.DropdownList },
        )

        // 只读输入框本身不接收点击（readOnly 仍然可聚焦但不弹键盘），
        // 所以盖一层透明的可点区域 —— 它与输入框同尺寸。
        Box(
            modifier =
                Modifier
                    .matchParentSize()
                    .testTag(FORMAT_PICKER_TRIGGER_TAG)
                    .clickable(enabled = enabled) { expanded = true },
        )

        DropdownMenu(
            expanded = expanded,
            onDismissRequest = { expanded = false },
        ) {
            BarcodeFormat.renderable.forEach { format ->
                DropdownMenuItem(
                    text = { Text(text = stringResource(format.displayNameRes)) },
                    onClick = {
                        onSelect(format)
                        expanded = false
                    },
                    modifier = Modifier.testTag(formatOptionTag(format)),
                )
            }
        }
    }
}

internal const val FORMAT_PICKER_TAG = "cardedit_format_picker"
internal const val FORMAT_PICKER_TRIGGER_TAG = "cardedit_format_picker_trigger"

internal fun formatOptionTag(format: BarcodeFormat) = "cardedit_format_option_${format.name}"
