package de.ncards.feature.onboarding

import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import de.ncards.core.designsystem.theme.NcardsTheme
import de.ncards.core.model.settings.AppLanguage

/**
 * J1 的第三屏：**6 位码**（§7.1：6 位数字、10 分钟、最多 5 次）。
 *
 * ============================================================================
 * ⚠️ 这一屏只有**一句**错误文案，那不是偷懒
 * ============================================================================
 * 服务端对码错 / 过期 / 已消费 / 次数耗尽 / Magic Link 已被用掉五种情形
 * 返回**逐字相同**、耗时相同的 401（§6.3.1 注 3 / ADR-0014）—— 这是为了挡住
 * 「拿一个真实 challenge_id 去问『这个邮箱注册过吗』」。
 *
 * 所以客户端**问不出**是哪一种。编一个区分出来（「验证码已过期」）只能是猜的，
 * 而猜错比笼统更糟。文案要做的是把用户导向唯一的出路：重发一个码。
 *
 * ============================================================================
 * ⚠️ 重发倒计时的秒数来自服务端
 * ============================================================================
 * `OtpChallenge.resendAfterSeconds`（§7.1 现在是 60）。写死 60 的话，
 * 服务端调这个间隔就需要发一版客户端；而抢在倒计时之前重发，撞的是
 * §7.5 的「每邮箱 1/min」，换来一个 429。
 */
@Composable
internal fun OtpScreen(
    state: OnboardingUiState,
    onCodeChanged: (String) -> Unit,
    onSubmit: () -> Unit,
    onResend: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val busy = state.progress == OnboardingProgress.Busy

    OnboardingScaffold(
        title = stringResource(R.string.onboarding_otp_title),
        subtitle = stringResource(R.string.onboarding_otp_intro, state.emailInput.trim()),
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = state.codeInput,
            onValueChange = onCodeChanged,
            label = { Text(text = stringResource(R.string.onboarding_otp_label)) },
            singleLine = true,
            enabled = !busy,
            isError = state.progress is OnboardingProgress.Failed,
            keyboardOptions =
                KeyboardOptions(
                    keyboardType = KeyboardType.NumberPassword,
                    imeAction = ImeAction.Go,
                ),
            keyboardActions = KeyboardActions(onGo = { onSubmit() }),
            modifier = Modifier.fillMaxWidth().testTag(OTP_FIELD_TAG),
        )

        (state.progress as? OnboardingProgress.Failed)?.let { failed ->
            OnboardingErrorText(message = failed.error.message())
        }

        Button(
            onClick = onSubmit,
            enabled = state.codeComplete && !busy,
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (busy) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp))
            } else {
                Text(text = stringResource(R.string.onboarding_otp_submit))
            }
        }

        // 倒计时没走完时按钮是禁用的，但**文字要说清还要等多久** ——
        // 一个没有解释的灰按钮会让用户以为功能坏了。
        TextButton(
            onClick = onResend,
            enabled = state.resendInSeconds == 0 && !busy,
            modifier = Modifier.fillMaxWidth().testTag(RESEND_BUTTON_TAG),
        ) {
            Text(
                text =
                    if (state.resendInSeconds > 0) {
                        stringResource(R.string.onboarding_otp_resend_in, state.resendInSeconds)
                    } else {
                        stringResource(R.string.onboarding_otp_resend)
                    },
            )
        }

        Text(
            text = stringResource(R.string.onboarding_otp_magic_link_hint),
            style = MaterialTheme.typography.bodySmall,
        )
    }
}

internal const val OTP_FIELD_TAG = "onboarding_otp_field"
internal const val RESEND_BUTTON_TAG = "onboarding_otp_resend"

@Preview(showBackground = true)
@Composable
private fun OtpScreenPreview() {
    NcardsTheme {
        OtpScreen(
            state =
                OnboardingUiState(
                    language = AppLanguage.GERMAN,
                    emailInput = "anna@example.de",
                    codeInput = "418",
                    resendInSeconds = 42,
                ),
            onCodeChanged = {},
            onSubmit = {},
            onResend = {},
        )
    }
}
