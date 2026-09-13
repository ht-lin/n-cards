package de.ncards.feature.carddetail

import androidx.compose.runtime.Composable
import androidx.compose.ui.res.stringResource

/**
 * sealed 的错误值 → 用户看得懂的那句话。
 *
 * ⚠️ 这个映射在 **UI 层**，不在 ViewModel 里。§10.4：「`ViewModel` **不得**
 * import Android framework 类」，而 `stringResource` 要 Compose 的上下文。
 * 形状照 `feature:wallet` 的 `WalletMessages.kt`。
 */
@Composable
internal fun CardDetailError.title(): String =
    when (this) {
        CardDetailError.CurrentUserUnknown -> stringResource(R.string.carddetail_error_user_unknown_title)
    }

@Composable
internal fun CardDetailError.message(): String =
    when (this) {
        CardDetailError.CurrentUserUnknown -> stringResource(R.string.carddetail_error_user_unknown_body)
    }
