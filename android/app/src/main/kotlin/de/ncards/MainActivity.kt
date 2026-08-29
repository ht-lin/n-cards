package de.ncards

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import dagger.hilt.android.AndroidEntryPoint
import de.ncards.core.designsystem.theme.NcardsTheme

/**
 * 唯一的 Activity（§4.3：全 Compose，无 XML 布局）。
 *
 * NavHost 由 T-151 起接线 —— 跨 feature 的导航在这里汇合，feature 之间因此
 * 不需要互相引用（§12.3 的第二条规则）。
 */
@AndroidEntryPoint
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)

        setContent {
            NcardsTheme {
                SkeletonScreen()
            }
        }
    }
}

/**
 * 骨架页。由 T-151（Onboarding）与 T-153（钱包列表）替换。
 *
 * 它存在的理由不是「让 App 有东西看」，而是**让三条 lint 规则有东西可咬**：
 * 空 App 里 HardcodedText / MissingTranslation / ContentDescription 永远是绿的，
 * 那样配了等于没配（§13.3）。这里的每一条文案都走 stringResource，
 * 图标按钮带 contentDescription —— 把任何一条改成字面量，`:app:lintDebug` 就会红。
 */
@Composable
private fun SkeletonScreen() {
    Scaffold { innerPadding ->
        Column(
            modifier =
                Modifier
                    .fillMaxSize()
                    .padding(innerPadding)
                    .padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text = stringResource(R.string.skeleton_title),
                style = MaterialTheme.typography.headlineMedium,
            )
            Text(
                text = stringResource(R.string.skeleton_body),
                style = MaterialTheme.typography.bodyLarge,
            )
            IconButton(onClick = {}) {
                Icon(
                    painter = painterResource(R.drawable.ic_refresh),
                    // §11.2：全部图标按钮必须有 contentDescription。
                    contentDescription = stringResource(R.string.skeleton_refresh_content_description),
                )
            }
        }
    }
}
