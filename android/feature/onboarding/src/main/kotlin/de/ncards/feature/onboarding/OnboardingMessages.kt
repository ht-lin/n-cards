package de.ncards.feature.onboarding

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource
import de.ncards.core.model.user.UsernameProblem
import de.ncards.feature.onboarding.username.UsernameRejection

/**
 * 错误 → 德语（或英语）文案。**全部经 `stringResource`**（§11.1）。
 *
 * ⚠️ 映射住在 UI 层而不是 ViewModel 里，是 §10.4 的要求：
 * 「`ViewModel` **不得** import Android framework 类…资源引用一律用资源 id
 * 或 sealed 的 `UiText`」。这里选的是前者的变体 —— ViewModel 只产出 sealed 的
 * 错误值，这个文件把它翻成字符串。`core:common` 的 `UiText` 还不存在；
 * 第三个 feature 需要同样的东西时该把它建起来，而不是抄第三份。
 */
@Composable
internal fun OnboardingError.message(): String =
    when (this) {
        OnboardingError.EmailLooksInvalid -> {
            stringResource(R.string.onboarding_error_email_invalid)
        }

        OnboardingError.CodeRejected -> {
            stringResource(R.string.onboarding_error_code_rejected)
        }

        is OnboardingError.TooManyRequests -> {
            retryAfterSeconds
                ?.let { stringResource(R.string.onboarding_error_too_many_requests_in, it) }
                ?: stringResource(R.string.onboarding_error_too_many_requests)
        }

        OnboardingError.Offline -> {
            stringResource(R.string.onboarding_error_offline)
        }

        OnboardingError.ServiceUnavailable -> {
            stringResource(R.string.onboarding_error_service_unavailable)
        }

        OnboardingError.ClientTooOld -> {
            stringResource(R.string.onboarding_error_client_too_old)
        }

        OnboardingError.Unexpected -> {
            stringResource(R.string.onboarding_error_unexpected)
        }
    }

/**
 * username 设定页的拒绝理由 → 文案。
 *
 * ⚠️ [UsernameRejection.AttemptsExhausted] 的文案里**不得**出现「稍后再试」：
 * 那是一个永不恢复的生命周期计数，重试永远不会成功（ADR-0017 决策二）。
 *
 * ⚠️ [UsernameRejection.Invalid] 在 `problem == null`（多半是保留词）时给的是
 * 一句通用文案 —— **不得**回声命中了哪个保留词，那等于把黑名单发出去。
 */
@Composable
internal fun UsernameRejection.message(): String =
    when (this) {
        UsernameRejection.Taken -> {
            stringResource(R.string.onboarding_username_error_taken)
        }

        is UsernameRejection.Invalid -> {
            problem?.message() ?: stringResource(R.string.onboarding_username_error_invalid)
        }

        UsernameRejection.AttemptsExhausted -> {
            stringResource(R.string.onboarding_username_error_attempts_exhausted)
        }

        is UsernameRejection.RateLimited -> {
            retryAfterSeconds
                ?.let { stringResource(R.string.onboarding_error_too_many_requests_in, it) }
                ?: stringResource(R.string.onboarding_error_too_many_requests)
        }

        UsernameRejection.Offline -> {
            stringResource(R.string.onboarding_error_offline)
        }

        UsernameRejection.ServiceUnavailable -> {
            stringResource(R.string.onboarding_error_service_unavailable)
        }

        UsernameRejection.Unexpected -> {
            stringResource(R.string.onboarding_error_unexpected)
        }
    }

/** 本地预校验的三格 —— §16 R13 的「输入时实时显示字符集规则」。 */
@Composable
internal fun UsernameProblem.message(): String =
    when (this) {
        UsernameProblem.TooShort -> stringResource(R.string.onboarding_username_error_too_short)
        UsernameProblem.TooLong -> stringResource(R.string.onboarding_username_error_too_long)
        UsernameProblem.IllegalCharacters -> stringResource(R.string.onboarding_username_error_charset)
    }
