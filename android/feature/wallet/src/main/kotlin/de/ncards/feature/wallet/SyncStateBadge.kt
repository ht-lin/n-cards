package de.ncards.feature.wallet

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import de.ncards.core.model.sync.SyncState

/**
 * 「这张卡还没推上去」的细微徽章（§4.3 铁律三）。
 *
 * ============================================================================
 * ⚠️ §11.2：**不以颜色作为唯一信息载体**
 * ============================================================================
 * 那条上线门禁逐字写着「共享徽章需带**图标或文字**」。这里用的是**文字**：
 * 「Wird synchronisiert」/「Synchronisierung fehlgeschlagen」。
 * 颜色只是第二层强调 —— 去掉颜色它照样读得懂，这正是色弱用户与高对比度模式
 * 下需要的，TalkBack 也直接就能念出来。
 *
 * 只画一个彩色小圆点是最容易顺手写出来的版本，也是明确违规的那个版本。
 *
 * ⚠️ **没有用图标**，虽然规格允许。原因很实际：目录里没有
 * `material-icons-*`（实测零声明），而为两个徽章引一个图标库，
 * 在 §9.1 的「APK ≤ 20 MB」下换不来什么 —— 文字已经满足规格，
 * 而且德语文案本身比一个感叹号图标说得更清楚。
 *
 * ============================================================================
 * [SyncState.SYNCED] 不画
 * ============================================================================
 * 「一切正常」不需要徽章。给每张卡都挂一个绿勾，会让真正需要注意的那两张
 * 淹没在一片绿里 —— 而绝大多数时候钱包里每一张卡都是 SYNCED。
 */
@Composable
internal fun SyncStateBadge(
    syncState: SyncState,
    modifier: Modifier = Modifier,
) {
    val style =
        when (syncState) {
            // 正常态不画任何东西。
            SyncState.SYNCED -> {
                return
            }

            SyncState.PENDING -> {
                BadgeStyle(
                    label = stringResource(R.string.wallet_badge_pending),
                    container = MaterialTheme.colorScheme.secondaryContainer,
                    content = MaterialTheme.colorScheme.onSecondaryContainer,
                )
            }

            SyncState.FAILED -> {
                BadgeStyle(
                    label = stringResource(R.string.wallet_badge_failed),
                    container = MaterialTheme.colorScheme.errorContainer,
                    content = MaterialTheme.colorScheme.onErrorContainer,
                )
            }
        }

    Text(
        text = style.label,
        style = MaterialTheme.typography.labelSmall,
        color = style.content,
        modifier =
            modifier
                .clip(RoundedCornerShape(BadgeCornerRadius))
                .background(style.container)
                .padding(horizontal = 8.dp, vertical = 2.dp),
    )
}

private data class BadgeStyle(
    val label: String,
    val container: Color,
    val content: Color,
)

private val BadgeCornerRadius = 6.dp
