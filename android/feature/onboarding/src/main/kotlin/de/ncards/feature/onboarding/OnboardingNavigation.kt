package de.ncards.feature.onboarding

import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.hilt.lifecycle.viewmodel.compose.hiltViewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.repeatOnLifecycle
import androidx.navigation.NavBackStackEntry
import androidx.navigation.NavController
import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import androidx.navigation.compose.navigation
import de.ncards.core.model.navigation.OnboardingGraph
import de.ncards.core.model.navigation.UsernameRoute
import de.ncards.feature.onboarding.username.UsernameEvent
import de.ncards.feature.onboarding.username.UsernameScreen
import de.ncards.feature.onboarding.username.UsernameViewModel
import kotlinx.coroutines.flow.Flow
import kotlinx.serialization.Serializable

/**
 * 注册流程内部的三个目的地。
 *
 * ⚠️ **`internal`，而且必须留在本模块。** `core:model` 的 `NcardsRoute` 只放
 * 「另一个模块需要导航到它」的目的地 —— welcome → email → otp 三步是
 * `feature:onboarding` 的私事，把它们提上去只会让 `:app` 有机会直接跳到
 * 流程中间的一屏。
 */
@Serializable
internal data object WelcomeRoute

@Serializable
internal data object EmailRoute

@Serializable
internal data object OtpRoute

/**
 * 注册流程的嵌套图（§1.4 J1 的前半段）。由 `:app` 的 NavHost 挂进去。
 *
 * @param onOpenTerms 打开 AGB（§8.6：注册页链接）。
 *   ⚠️ 它是一个**回调**而不是一次 `navController.navigate(TermsRoute)`：
 *   那两页在 `feature:legal`，而 §12.3 禁止 feature 之间互相依赖 ——
 *   `feature:onboarding → feature:legal` 会让 `./gradlew help` 当场变红。
 * @param onSignedIn 已登录且 `onboarding_complete == true` → 钱包。
 * @param onUsernameRequired 已登录但 `username IS NULL` → username 设定页。
 */
fun NavGraphBuilder.onboardingGraph(
    navController: NavController,
    onOpenTerms: () -> Unit,
    onOpenPrivacy: () -> Unit,
    onSignedIn: () -> Unit,
    onUsernameRequired: () -> Unit,
) {
    navigation<OnboardingGraph>(startDestination = WelcomeRoute) {
        composable<WelcomeRoute> { entry ->
            val viewModel = entry.sharedOnboardingViewModel(navController)
            val state by viewModel.state.collectAsStateWithLifecycle()

            WelcomeScreen(
                language = state.language,
                onLanguageSelected = viewModel::onLanguageSelected,
                onContinue = { navController.navigate(EmailRoute) },
                onOpenPrivacy = onOpenPrivacy,
            )
        }

        composable<EmailRoute> { entry ->
            val viewModel = entry.sharedOnboardingViewModel(navController)
            val state by viewModel.state.collectAsStateWithLifecycle()

            ObserveEvents(viewModel.events) { event ->
                if (event is OnboardingEvent.CodeSent) {
                    navController.navigate(OtpRoute)
                }
            }

            EmailScreen(
                state = state,
                onEmailChanged = viewModel::onEmailChanged,
                onSubmit = viewModel::requestCode,
                onOpenTerms = onOpenTerms,
                onOpenPrivacy = onOpenPrivacy,
            )
        }

        composable<OtpRoute> { entry ->
            val viewModel = entry.sharedOnboardingViewModel(navController)
            val state by viewModel.state.collectAsStateWithLifecycle()

            ObserveEvents(viewModel.events) { event ->
                when (event) {
                    OnboardingEvent.SignedIn -> onSignedIn()
                    OnboardingEvent.UsernameRequired -> onUsernameRequired()
                    OnboardingEvent.CodeSent -> Unit // 重发不导航，留在本屏。
                }
            }

            OtpScreen(
                state = state,
                onCodeChanged = viewModel::onCodeChanged,
                onSubmit = viewModel::verifyCode,
                onResend = viewModel::resendCode,
            )
        }
    }
}

/**
 * username 设定页。**顶层目的地**，不在 [OnboardingGraph] 里面 ——
 * 理由见 [UsernameRoute] 的 KDoc（冷启动要能直接落在它上面）。
 *
 * @param onCompleted 注册完成 → 钱包。调用方**必须**在导航时清空回退栈
 *   （§3.8：不可跳过、不可返回）。
 */
fun NavGraphBuilder.usernameDestination(onCompleted: () -> Unit) {
    composable<UsernameRoute> {
        val viewModel: UsernameViewModel = hiltViewModel()
        val state by viewModel.state.collectAsStateWithLifecycle()

        ObserveEvents(viewModel.events) { event ->
            when (event) {
                UsernameEvent.Completed -> onCompleted()
            }
        }

        UsernameScreen(
            state = state,
            onInputChanged = viewModel::onInputChanged,
            onConfirm = viewModel::submit,
        )
    }
}

/**
 * 三屏共用的 ViewModel，scoped 到嵌套图的 `NavBackStackEntry`。
 *
 * ⚠️ `hiltViewModel()` 不带参数时 scope 是**当前这一屏**，于是三屏会拿到
 * 三个实例 —— 用户从 6 位码页退回邮箱页再前进，倒计时会重来，
 * 而服务端那边挑战还活着、下一次重发会撞 429。
 */
@Composable
private fun NavBackStackEntry.sharedOnboardingViewModel(navController: NavController): OnboardingViewModel {
    val graphEntry = remember(this) { navController.getBackStackEntry(OnboardingGraph) }
    return hiltViewModel(graphEntry)
}

/**
 * 一次性事件的收集点。
 *
 * ⚠️ **`RESUMED` 而不是 `STARTED`，这一条是必须的。** 事件走的是 `Channel`，
 * 一个事件只会送到一个收集者。导航切换的那一段时间里，前一屏与后一屏
 * 可以同时处于 `STARTED`，于是「登录成功」有机会落到正在退场的那一屏上，
 * 表现为**偶发的**不导航。只有一屏会是 `RESUMED`。
 */
@Composable
private fun <T> ObserveEvents(
    events: Flow<T>,
    onEvent: (T) -> Unit,
) {
    val lifecycleOwner = LocalLifecycleOwner.current
    LaunchedEffect(events, lifecycleOwner) {
        lifecycleOwner.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            events.collect(onEvent)
        }
    }
}
