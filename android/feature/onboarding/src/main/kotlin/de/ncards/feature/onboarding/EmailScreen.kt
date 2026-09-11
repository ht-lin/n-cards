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
 * J1 的第二屏：**邮箱**。也就是 §8.6 说的「注册页」—— AGB 的链接必须在这里。
 *
 * ============================================================================
 * ⚠️ 这一屏**不分**「登录」与「注册」
 * ============================================================================
 * `POST /auth/otp/request` 恒 202，服务端在这条路径上根本不查 `users`
 * （ADR-0014）。所以既没有「该邮箱未注册」这个状态，也没有第二个入口 ——
 * 首次验证成功即注册。任何想在这里加一个「还没有账号？」分叉的改动，
 * 都会需要一个客户端问不出答案的问题。
 */
@Composable
internal fun EmailScreen(
    state: OnboardingUiState,
    onEmailChanged: (String) -> Unit,
    onSubmit: () -> Unit,
    onOpenTerms: () -> Unit,
    onOpenPrivacy: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val busy = state.progress == OnboardingProgress.Busy

    OnboardingScaffold(
        title = stringResource(R.string.onboarding_email_title),
        subtitle = stringResource(R.string.onboarding_email_intro),
        modifier = modifier,
    ) {
        OutlinedTextField(
            value = state.emailInput,
            onValueChange = onEmailChanged,
            label = { Text(text = stringResource(R.string.onboarding_email_label)) },
            singleLine = true,
            enabled = !busy,
            isError = state.progress is OnboardingProgress.Failed,
            keyboardOptions =
                KeyboardOptions(
                    keyboardType = KeyboardType.Email,
                    imeAction = ImeAction.Go,
                ),
            keyboardActions = KeyboardActions(onGo = { onSubmit() }),
            modifier = Modifier.fillMaxWidth().testTag(EMAIL_FIELD_TAG),
        )

        (state.progress as? OnboardingProgress.Failed)?.let { failed ->
            OnboardingErrorText(message = failed.error.message())
        }

        Button(
            onClick = onSubmit,
            // ⚠️ 按钮不因本地预校验而禁用：判严了会把一个真实可用的地址挡在门外，
            // 而用户没有第二个注册入口。校验结果走错误提示，不走 enabled。
            enabled = !busy,
            modifier = Modifier.fillMaxWidth(),
        ) {
            if (busy) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp))
            } else {
                Text(text = stringResource(R.string.onboarding_email_submit))
            }
        }

        // §8.6：「AGB / Nutzungsbedingungen —— 注册页链接」。
        Text(
            text = stringResource(R.string.onboarding_email_legal_notice),
            style = MaterialTheme.typography.bodySmall,
        )

        TextButton(onClick = onOpenTerms) {
            Text(text = stringResource(R.string.onboarding_open_terms))
        }

        TextButton(onClick = onOpenPrivacy) {
            Text(text = stringResource(R.string.onboarding_open_privacy))
        }
    }
}

internal const val EMAIL_FIELD_TAG = "onboarding_email_field"

@Preview(showBackground = true)
@Composable
private fun EmailScreenPreview() {
    NcardsTheme {
        EmailScreen(
            state = OnboardingUiState(language = AppLanguage.GERMAN, emailInput = "anna@example.de"),
            onEmailChanged = {},
            onSubmit = {},
            onOpenTerms = {},
            onOpenPrivacy = {},
        )
    }
}
