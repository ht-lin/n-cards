package de.ncards.feature.wallet

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource

/**
 * sealed 的错误值 → 用户看得懂的那句话。
 *
 * ⚠️ 这个映射在 **UI 层**，不在 ViewModel 里。§10.4：「`ViewModel` **不得**
 * import Android framework 类」，而 `stringResource` 要 Compose 的上下文。
 *
 * 形状照 `feature:onboarding` 的 `OnboardingMessages.kt` —— 那里的注释顺带
 * 记了一笔债：「`core:common` 的 `UiText` 还不存在；第三个 feature 需要同样的
 * 东西时该把它建起来」。T-153 把 `UiText` 建起来了，但**这里没有用它**：
 * `WalletError` 今天只有一格，为一个 `when` 引入一层间接不划算。
 * `UiText` 真正要用在带参数、要当字段传的文案上（比如列表项的徽章文字）。
 */
@Composable
internal fun WalletError.message(): String =
    when (this) {
        WalletError.CurrentUserUnknown -> stringResource(R.string.wallet_error_user_unknown_body)
    }

@Composable
internal fun WalletError.title(): String =
    when (this) {
        WalletError.CurrentUserUnknown -> stringResource(R.string.wallet_error_user_unknown_title)
    }
