package de.ncards

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import de.ncards.data.auth.AuthRepository
import de.ncards.data.auth.SessionState
import de.ncards.data.auth.SignedOutReason
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.launch
import timber.log.Timber
import javax.inject.Inject

/**
 * 启动时决定「NavHost 从哪一屏开始」，以及接住 Magic Link（T-151）。
 *
 * ⚠️ **这是 DI 图上 `AuthRepository` 的第一个真实消费者。** T-150 的落地记录里
 * 那条警告（「`:app` 里还没有人注入 AuthRepository 或 OkHttpClient，
 * 而 Dagger 默认只校验可达的绑定 —— 插槽绑错了图照样建得起来，
 * 只是每个请求都不带 Bearer」）从本类存在的那一刻起失效：
 * 认证插槽现在是可达的，绑错了编译期就报。
 */
@HiltViewModel
class AppViewModel
    @Inject
    constructor(
        private val auth: AuthRepository,
    ) : ViewModel() {
        private val _state = MutableStateFlow<AppUiState>(AppUiState.Loading)
        val state: StateFlow<AppUiState> = _state.asStateFlow()

        private val eventChannel = Channel<AppEvent>(Channel.BUFFERED)
        val events: Flow<AppEvent> = eventChannel.receiveAsFlow()

        init {
            viewModelScope.launch {
                // ⚠️ 不调这一句，`sessionState` 会**永远**停在 Unknown ——
                // 令牌是懒加载的，在第一个网络请求发生之前没人去读存储，
                // 而下面那个 `first { … }` 会一直等，NavHost 一直显示 splash。
                // T-150 的落地记录把这条列为留给本卡的第一件事。
                auth.restoreSession()

                _state.value =
                    when (val session = auth.sessionState.first { it != SessionState.Unknown }) {
                        is SessionState.SignedOut -> AppUiState.Ready(Destination.Onboarding, session.reason)

                        SessionState.SignedIn -> AppUiState.Ready(resolveSignedInDestination(), signedOutReason = null)

                        // `first { it != Unknown }` 已经把它排除了。
                        SessionState.Unknown -> AppUiState.Ready(Destination.Onboarding, SignedOutReason.NeverSignedIn)
                    }
            }
        }

        /**
         * 冷启动时判 onboarding。
         *
         * ⚠️ **唯一的来源是 `GET /me`。** 不要试图从 access token 里读：§7.1 把
         * claim 集钉成了恰好 `sub sid did jti iat exp`，ADR-0018 明确拒绝了
         * 加一个 `onb` claim（理由是那会让「注册完成」这件事有两个真相源，
         * 而其中一个要等 15 分钟才过期）。
         *
         * ⚠️ **拉不到的时候乐观进钱包，不要卡在 splash。** §4.3 的离线优先铁律
         * 不允许把一次网络故障变成「App 打不开」—— 而真的卡在注册中间态的用户
         * 会在下一个请求上拿到 `403 username_required`，被 ADR-0018 设计好的
         * 那条路捞回来。反过来（卡 splash 直到能联网）没有任何补救。
         */
        private suspend fun resolveSignedInDestination(): Destination =
            when (val me = auth.fetchMe()) {
                is ApiResult.Success -> {
                    if (me.value.onboardingComplete) Destination.Wallet else Destination.Username
                }

                is ApiResult.Failure -> {
                    if (me.error is ApiError.UsernameRequired) {
                        // 理论上到不了这里 —— `GET /me` 是 onboarding 白名单里的三个之一。
                        // 真到了说明服务端的白名单漏了一个，值得留一条日志。
                        Timber.w("GET /me was blocked by the onboarding interceptor; request_id=%s", me.error.requestId)
                        Destination.Username
                    } else {
                        Timber.i("Could not resolve onboarding state at startup; continuing optimistically.")
                        Destination.Wallet
                    }
                }
            }

        /**
         * 消费邮件里的 Magic Link（ADR-0016 / §7.1）。
         *
         * 它与 6 位码挂在**同一条**挑战上、共用一个 `consumed_at` —— 用掉任何一个，
         * 另一个立刻 401。所以这里成功之后，注册流程那边停着的 6 位码页也失效了，
         * 而我们正好要把用户从那里带走。
         *
         * 响应与 `otp/verify` 是同一个 `Session` schema，所以路由判断也是同一条：
         * 看 `onboarding_complete`。
         */
        fun consumeMagicLink(token: String) {
            viewModelScope.launch {
                when (val result = auth.consumeMagicLink(token)) {
                    is ApiResult.Success -> {
                        eventChannel.send(
                            if (result.value.onboardingComplete) AppEvent.OpenWallet else AppEvent.OpenUsernameSetup,
                        )
                    }

                    is ApiResult.Failure -> {
                        // 401 = 令牌过期 / 已被用掉（包括「用户已经用 6 位码登录过了」）。
                        // 不区分显示，理由与 6 位码那一屏相同（§6.3.1 注 3）。
                        Timber.i("Magic link could not be consumed.")
                        eventChannel.send(AppEvent.MagicLinkRejected)
                    }
                }
            }
        }
    }

/** 启动时的界面状态。**[Loading] 期间显示 splash，不跳任何地方**（T-150 的交接说明）。 */
sealed interface AppUiState {
    /** 还没读完存储 —— 冷启动的第一帧。 */
    data object Loading : AppUiState

    data class Ready(
        val startDestination: Destination,
        /**
         * 上一次会话是怎么没的。null = 本来就登录着，或者从没登录过也没什么好说的。
         *
         * ⚠️ 四格必须可辨认（`SignedOutReason` 的类注释）：
         * `KeyMaterialLost` 要说的是「本机数据需重新同步」，**不是**「你被登出了」。
         */
        val signedOutReason: SignedOutReason?,
    ) : AppUiState
}

/** NavHost 的起始目的地。映射到 `core:model` 的路由在 `NcardsNavHost`。 */
enum class Destination {
    Onboarding,
    Username,
    Wallet,
}

/** 一次性事件（§10.4：走 `Channel`，不放进 `StateFlow`）。 */
sealed interface AppEvent {
    data object OpenWallet : AppEvent

    data object OpenUsernameSetup : AppEvent

    /** Magic Link 用不了 —— 回注册流程，请用 6 位码。 */
    data object MagicLinkRejected : AppEvent
}
