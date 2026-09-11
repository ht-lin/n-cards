package de.ncards.feature.onboarding.username

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.feature.onboarding.OnboardingErrorText
import de.ncards.feature.onboarding.OnboardingScaffold
import de.ncards.feature.onboarding.R
import de.ncards.feature.onboarding.message

/**
 * username 设定页 —— 注册流程的最后一步，也是本项目为数不多的**不可逆**操作。
 *
 * ============================================================================
 * 这一屏上有五条是规格明写的 MUST，删任何一条都是违规
 * ============================================================================
 * 1. **不可跳过、不可返回**（§3.8「设定时机」）：没有返回按钮，[BackHandler]
 *    吃掉系统返回键，而导航进来时回退栈已经被清空。
 * 2. **二次确认对话框**，文案逐字 `Dieser Name kann später nicht geändert werden.`
 *    （§3.8 的加粗段 / §16 R13）。
 * 3. **实时显示字符集规则**（§16 R13）+ 实时显示归一化后的形态 ——
 *    用户按下「确认」时提交的必须是他眼睛看着的那个字符串。
 * 4. **必须提示**「其他人可通过该名字找到你，请勿使用真实姓名或邮箱」
 *    （§3.8「可发现性」：GDPR 数据最小化 + 用户知情）。
 * 5. 四种失败分开显示（契约 `/me/username` 的说明表）；尤其
 *    `limit_exceeded` 是**终局**，不许出现「稍后再试」。
 */
@Composable
internal fun UsernameScreen(
    state: UsernameUiState,
    onInputChanged: (String) -> Unit,
    onConfirm: () -> Unit,
    modifier: Modifier = Modifier,
) {
    // ⚠️ §3.8：未设定 username 不得进入钱包，而这一页是那个状态唯一的出口。
    // 空的 BackHandler 不是「屏蔽用户」—— 退回去也没有任何可用的地方：
    // 他已经登录了，邮箱与验证码那两屏对他不再有意义。
    BackHandler(enabled = true) { /* 有意为之：这一页不可返回。 */ }

    // 对话框是纯粹的 UI 开关，留在这里而不是 ViewModel：
    // 旋转屏幕要保住它，所以是 rememberSaveable。
    var confirming by rememberSaveable { mutableStateOf(false) }

    val busy = state.progress == UsernameProgress.Busy

    OnboardingScaffold(
        title = stringResource(R.string.onboarding_username_title),
        subtitle = stringResource(R.string.onboarding_username_intro),
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = state.input,
            onValueChange = onInputChanged,
            label = { Text(text = stringResource(R.string.onboarding_username_label)) },
            singleLine = true,
            enabled = !busy,
            isError = state.localProblem != null || state.progress is UsernameProgress.Rejected,
            supportingText = {
                // §16 R13：「输入时实时显示字符集规则」。规则**一直**在，
                // 不是只在出错时才冒出来 —— 出错之后才告诉用户规则是什么，
                // 已经浪费了他一次尝试。
                Text(text = stringResource(R.string.onboarding_username_rules))
            },
            keyboardOptions =
                KeyboardOptions(
                    keyboardType = KeyboardType.Ascii,
                    // 字符集里没有大写字母，别让键盘自作主张首字母大写。
                    capitalization = KeyboardCapitalization.None,
                    autoCorrectEnabled = false,
                    imeAction = ImeAction.Done,
                ),
            keyboardActions = KeyboardActions(onDone = { if (state.canSubmit) confirming = true }),
            modifier = Modifier.fillMaxWidth().testTag(USERNAME_FIELD_TAG),
        )

        // 实时归一化预览。用户输 `Anna_B ` 就要当场看到 `anna_b`。
        if (state.input.isNotBlank()) {
            Text(
                text = stringResource(R.string.onboarding_username_normalized_preview, state.normalized),
                style = MaterialTheme.typography.bodyMedium,
                modifier = Modifier.testTag(NORMALIZED_PREVIEW_TAG),
            )
        }

        state.localProblem?.let { problem ->
            OnboardingErrorText(message = problem.message())
        }

        (state.progress as? UsernameProgress.Rejected)?.let { rejected ->
            OnboardingErrorText(
                message = rejected.reason.message(),
                modifier = Modifier.testTag(SERVER_ERROR_TAG),
            )
        }

        // §3.8「可发现性」—— GDPR 数据最小化 + 用户知情。
        Text(
            text = stringResource(R.string.onboarding_username_discoverability_notice),
            style = MaterialTheme.typography.bodyMedium,
        )

        // 不可逆警告在按钮**之前**，不是只在对话框里。
        Text(
            text = stringResource(R.string.onboarding_username_immutable_warning),
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.error,
        )

        Button(
            onClick = { confirming = true },
            enabled = state.canSubmit,
            modifier = Modifier.fillMaxWidth().testTag(SUBMIT_BUTTON_TAG),
        ) {
            if (busy) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp))
            } else {
                Text(text = stringResource(R.string.onboarding_username_submit))
            }
        }
    }

    if (confirming) {
        ImmutableNameDialog(
            username = state.normalized,
            onDismiss = { confirming = false },
            onConfirm = {
                confirming = false
                onConfirm()
            },
        )
    }
}

/**
 * ⚠️ §3.8 的加粗段：「UI 上的『确认设定』是一个不可逆动作，**必须**用二次确认
 * 对话框，且文案明确（`Dieser Name kann später nicht geändert werden.`）。
 * 这是本项目为数不多的不可逆用户操作之一，**和删号同级对待**。」
 *
 * 那句德语在 `values/strings.xml` 里逐字存着。改它之前先读 §16 R13 ——
 * 改名诉求的统一口径是「注销后重新注册」，而用户能接受那个口径的前提是
 * 他在设定的那一刻真的被告知过。
 */
@Composable
private fun ImmutableNameDialog(
    username: String,
    onDismiss: () -> Unit,
    onConfirm: () -> Unit,
) {
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(text = stringResource(R.string.onboarding_username_dialog_title)) },
        text = {
            Text(
                text =
                    stringResource(R.string.onboarding_username_dialog_body, username) +
                        "\n\n" +
                        stringResource(R.string.onboarding_username_immutable_warning),
            )
        },
        confirmButton = {
            TextButton(onClick = onConfirm, modifier = Modifier.testTag(DIALOG_CONFIRM_TAG)) {
                Text(text = stringResource(R.string.onboarding_username_dialog_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(text = stringResource(R.string.onboarding_username_dialog_cancel))
            }
        },
    )
}

internal const val USERNAME_FIELD_TAG = "onboarding_username_field"
internal const val NORMALIZED_PREVIEW_TAG = "onboarding_username_preview"
internal const val SERVER_ERROR_TAG = "onboarding_username_server_error"
internal const val SUBMIT_BUTTON_TAG = "onboarding_username_submit"
internal const val DIALOG_CONFIRM_TAG = "onboarding_username_dialog_confirm"

@Preview(showBackground = true)
@Composable
private fun UsernameScreenPreview() {
    NcardsTheme {
        UsernameScreen(
            state = UsernameUiState(input = "Anna_B "),
            onInputChanged = {},
            onConfirm = {},
        )
    }
}
