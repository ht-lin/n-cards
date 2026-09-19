package de.ncards.navigation

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.rememberNavController
import de.ncards.AppEvent
import de.ncards.AppUiState
import de.ncards.AppViewModel
import de.ncards.Destination
import de.ncards.R
import de.ncards.barcode.FullscreenBarcodeActivity
import de.ncards.core.model.navigation.CardCreateRoute
import de.ncards.core.model.navigation.CardDetailRoute
import de.ncards.core.model.navigation.CardEditRoute
import de.ncards.core.model.navigation.OnboardingGraph
import de.ncards.core.model.navigation.PrivacyRoute
import de.ncards.core.model.navigation.TermsRoute
import de.ncards.core.model.navigation.UsernameRoute
import de.ncards.core.model.navigation.WalletRoute
import de.ncards.data.auth.SignedOutReason
import de.ncards.feature.carddetail.cardDetailDestination
import de.ncards.feature.cardedit.cardEditDestination
import de.ncards.feature.legal.PrivacyScreen
import de.ncards.feature.legal.TermsScreen
import de.ncards.feature.onboarding.onboardingGraph
import de.ncards.feature.onboarding.usernameDestination
import de.ncards.feature.wallet.walletDestination

/**
 * 全应用唯一的 NavHost（§12.3）。
 *
 * ============================================================================
 * 它为什么在 `:app`
 * ============================================================================
 * 「跨 feature 导航经 app 的 NavHost + core:model 的路由定义」——
 * `ModuleGraph.kt` 的违规文案里逐字写着这一条，而本文件是它第一次落地。
 *
 * 具体的好处在这一屏就看得见：注册页要链到 AGB（§8.6），那两页在
 * `feature:legal`。`feature:onboarding` **看不见**它（配置期就会报错），
 * 于是它只收一个 `onOpenTerms: () -> Unit`，由这里接到 `TermsRoute` 上。
 * 两个 feature 因此可以各自编译、各自测试。
 */
@Composable
internal fun NcardsNavHost(
    viewModel: AppViewModel,
    modifier: Modifier = Modifier,
    navController: NavHostController = rememberNavController(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    when (val current = state) {
        // ⚠️ Unknown 期间显示 splash，**不跳任何地方**。
        // 把它当成「未登录」的后果是：每一次冷启动，已登录用户都会先被弹到
        // 登录页，再在几十毫秒后被弹回钱包（`SessionState` 的类注释）。
        AppUiState.Loading -> {
            SplashScreen(modifier)
        }

        is AppUiState.Ready -> {
            ReadyNavHost(
                startDestination = current.startDestination,
                signedOutReason = current.signedOutReason,
                events = viewModel,
                navController = navController,
                modifier = modifier,
            )
        }
    }
}

@Composable
private fun ReadyNavHost(
    startDestination: Destination,
    signedOutReason: SignedOutReason?,
    events: AppViewModel,
    navController: NavHostController,
    modifier: Modifier = Modifier,
) {
    // 起全屏条码页那个 Activity 要一个 Context（见下面 cardDetailDestination）。
    val context = LocalContext.current

    /** username 设定页**不可跳过、不可返回**（§3.8）：进去就把回退栈清空。 */
    fun openUsernameSetup() {
        navController.navigate(UsernameRoute) {
            popUpTo(navController.graph.id) { inclusive = true }
        }
    }

    fun openWallet() {
        navController.navigate(WalletRoute) {
            popUpTo(navController.graph.id) { inclusive = true }
        }
    }

    LaunchedEffect(events) {
        events.events.collect { event ->
            when (event) {
                AppEvent.OpenWallet -> {
                    openWallet()
                }

                AppEvent.OpenUsernameSetup -> {
                    openUsernameSetup()
                }

                AppEvent.MagicLinkRejected -> {
                    navController.navigate(OnboardingGraph) {
                        popUpTo(navController.graph.id) { inclusive = true }
                    }
                }
            }
        }
    }

    SignedOutNotice(signedOutReason)

    NavHost(
        navController = navController,
        startDestination =
            when (startDestination) {
                Destination.Onboarding -> OnboardingGraph
                Destination.Username -> UsernameRoute
                Destination.Wallet -> WalletRoute
            },
        modifier = modifier,
    ) {
        onboardingGraph(
            navController = navController,
            onOpenTerms = { navController.navigate(TermsRoute) },
            onOpenPrivacy = { navController.navigate(PrivacyRoute) },
            onSignedIn = ::openWallet,
            onUsernameRequired = ::openUsernameSetup,
        )

        usernameDestination(onCompleted = ::openWallet)

        walletDestination(
            // T-153 押的那个注兑现了：接上详情页只改了这一行。
            onOpenCard = { cardId -> navController.navigate(CardDetailRoute(cardId)) },
            // 空状态的「添加第一张卡」与列表页的 FAB 共用这一个去处（T-155）。
            onAddCard = { navController.navigate(CardCreateRoute) },
        )

        cardDetailDestination(
            onBack = { navController.popBackStack() },
            // ⚠️ 全屏条码页**不是一个路由**，它是一个独立 Activity（§10.2：
            // 「便于设置窗口属性」）。所以这里是 startActivity 而不是 navigate ——
            // feature:carddetail 对此一无所知，它只收一个 lambda。
            onShowBarcode = { cardId ->
                context.startActivity(FullscreenBarcodeActivity.intent(context, cardId))
            },
            // T-155 接上了。详情页的按钮由 `card.canEdit` gate 住 ——
            // viewer 根本看不到它，所以这里不需要再判一次角色。
            onEdit = { cardId -> navController.navigate(CardEditRoute(cardId)) },
        )

        cardEditDestination(
            onBack = { navController.popBackStack() },
            // 保存成功之后退回来的那一页：新建来自钱包、编辑来自详情。
            // 两条都是 popBackStack —— 不需要区分，回退栈自己知道。
            onSaved = { navController.popBackStack() },
        )

        composable<TermsRoute> { TermsScreen(onBack = { navController.popBackStack() }) }

        composable<PrivacyRoute> { PrivacyScreen(onBack = { navController.popBackStack() }) }
    }
}

/**
 * 「上一次为什么被登出」。
 *
 * ⚠️ `SignedOutReason` 的四格**刻意**可辨认，别合并成一句话：
 * [SignedOutReason.KeyMaterialLost] 要说的是「本机数据需重新同步」，
 * 而不是「你被登出了」—— 那种情形下 SQLCipher 的 `db_passphrase` 多半也一起
 * 失效了，用户回来之后会发现卡不见了，需要**事先**知道原因（§16 R12 同理）。
 *
 * [SignedOutReason.NeverSignedIn] 与 [SignedOutReason.UserAction] 没什么好说的 ——
 * 一个是首次安装，一个是他自己刚点的。给它们弹窗只是噪音。
 */
@Composable
private fun SignedOutNotice(reason: SignedOutReason?) {
    val messageRes =
        when (reason) {
            SignedOutReason.SessionRevoked -> R.string.signed_out_session_revoked
            SignedOutReason.KeyMaterialLost -> R.string.signed_out_key_material_lost
            SignedOutReason.NeverSignedIn, SignedOutReason.UserAction, null -> null
        } ?: return

    var visible by remember(reason) { mutableStateOf(true) }
    if (!visible) return

    AlertDialog(
        onDismissRequest = { visible = false },
        title = { Text(text = stringResource(R.string.signed_out_title)) },
        text = { Text(text = stringResource(messageRes)) },
        confirmButton = {
            TextButton(onClick = { visible = false }) {
                Text(text = stringResource(R.string.signed_out_acknowledge))
            }
        },
    )
}

/** 冷启动的第一帧。读一次令牌要过两趟 Keystore，那是 I/O。 */
@Composable
private fun SplashScreen(modifier: Modifier = Modifier) {
    Box(
        modifier = modifier.fillMaxSize(),
        contentAlignment = Alignment.Center,
    ) {
        CircularProgressIndicator()
    }
}
