package de.ncards.navigation

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import de.ncards.R

/**
 * J1 的终点：**空钱包 + 引导「添加第一张卡」**。
 *
 * ============================================================================
 * ⚠️ 这是一个占位实现，归 T-153 原地替换
 * ============================================================================
 * 钱包列表是 `feature:wallet` 的交付物（T-153：Room `Flow` 驱动的列表、搜索、
 * 置顶、拖拽排序、`sync_state` 徽章…），而那个模块今天是空壳。
 *
 * 它落在 `:app` 而不是 `feature:onboarding`，是为了让替换是**一行 import**：
 * 路由 `WalletRoute` 定义在 `core:model`、由 `NcardsNavHost` 接线，
 * T-153 只要把这里换成 `feature:wallet` 的入口，所有导航调用点一个字都不用动。
 * 放进 onboarding 的话，J1 的钱包首帧会有两份实现，而其中一份注定要删。
 *
 * 「添加第一张卡」按钮暂时无动作 —— 录入的三条路（T-155 手输 / T-156 扫码 /
 * T-157 图片）都还不存在。
 */
@Composable
internal fun WalletPlaceholderScreen(modifier: Modifier = Modifier) {
    Scaffold(modifier = modifier) { innerPadding ->
        Column(
            modifier =
                Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
                    .padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp, Alignment.CenterVertically),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Column(
                modifier = Modifier.widthIn(max = 480.dp).fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(16.dp),
            ) {
                Text(
                    text = stringResource(R.string.wallet_empty_title),
                    style = MaterialTheme.typography.headlineMedium,
                    modifier = Modifier.semantics { heading() },
                )

                Text(
                    text = stringResource(R.string.wallet_empty_body),
                    style = MaterialTheme.typography.bodyLarge,
                )

                Button(
                    // ⚠️ 占位：录入的三条路（T-155 手输 / T-156 扫码 / T-157 图片）
                    // 都还不存在，所以这个按钮现在是禁用的。T-153 接管本屏时把它接上。
                    onClick = {},
                    enabled = false,
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(text = stringResource(R.string.wallet_add_first_card))
                }
            }
        }
    }
}
