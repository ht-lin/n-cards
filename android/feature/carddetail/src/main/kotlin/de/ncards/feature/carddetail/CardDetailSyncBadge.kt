package de.ncards.feature.carddetail

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
 * ⚠️ 这是 `feature:wallet` 的 `SyncStateBadge` 的**第二份**，而且是刻意的
 * ============================================================================
 * `feature:*` 之间禁止互相依赖（§12.3，`ModuleGraph` 在配置期强制），所以
 * 「直接用钱包那个」不成立。剩下两条路：把它下沉到 `core:ui`，或者抄一份。
 *
 * 抄。`android/README.md` 的规则是「**第三个消费者**出现时就该把它建起来，
 * 而不是抄第三份」—— 本卡是第二个。而下沉的代价不只是挪一个文件：
 * `core:ui` **没有 `res/`**（它的注释写明了这一点，所有文案都由调用方传入），
 * 所以下沉意味着要么给 `core:ui` 开一个资源目录，要么把两条德英文案搬出
 * `feature:wallet` 再改那边的调用点 —— 为一个还没有第三个消费者的组件
 * 动两个模块，不划算。
 *
 * **T-254 的 Widget 是第三个**（它要显示同一个状态），到那时再建 ——
 * 而且那一次必须建，因为 Glance 吃不了 Composable，抄第三份等于把
 * §11.2 那条「不以颜色作为唯一信息载体」分散到三个地方各判一次。
 *
 * ⚠️ §11.2：徽章带**文字**而不只是颜色。去掉颜色它照样读得懂 ——
 * 色弱用户与高对比度模式需要这一条，TalkBack 也直接就能念出来。
 * 只画一个彩色小圆点是最容易顺手写出来的版本，也是明确违规的那个版本。
 *
 * [SyncState.SYNCED] 不画：绝大多数时候每张卡都是它，给每张卡挂一个绿勾
 * 只会让真正需要注意的那张淹没掉。
 */
@Composable
internal fun CardDetailSyncBadge(
    syncState: SyncState,
    modifier: Modifier = Modifier,
) {
    val label =
        when (syncState) {
            SyncState.SYNCED -> return
            SyncState.PENDING -> stringResource(R.string.carddetail_badge_pending)
            SyncState.FAILED -> stringResource(R.string.carddetail_badge_failed)
        }

    val container =
        when (syncState) {
            SyncState.SYNCED -> Color.Unspecified
            SyncState.PENDING -> MaterialTheme.colorScheme.secondaryContainer
            SyncState.FAILED -> MaterialTheme.colorScheme.errorContainer
        }

    val content =
        when (syncState) {
            SyncState.SYNCED -> Color.Unspecified
            SyncState.PENDING -> MaterialTheme.colorScheme.onSecondaryContainer
            SyncState.FAILED -> MaterialTheme.colorScheme.onErrorContainer
        }

    Text(
        text = label,
        style = MaterialTheme.typography.labelMedium,
        color = content,
        modifier =
            modifier
                .clip(RoundedCornerShape(BadgeCorner))
                .background(container)
                .padding(horizontal = BadgePaddingH, vertical = BadgePaddingV),
    )
}

private val BadgeCorner = 8.dp
private val BadgePaddingH = 8.dp
private val BadgePaddingV = 2.dp
