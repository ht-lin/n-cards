package de.ncards.feature.onboarding.username

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import dagger.hilt.android.lifecycle.HiltViewModel
import de.ncards.core.model.user.UsernameProblem
import de.ncards.core.model.user.UsernameRules
import de.ncards.core.network.impl.ApiResult
import de.ncards.core.network.impl.error.ApiError
import de.ncards.core.network.impl.error.FieldError
import de.ncards.data.auth.AuthRepository
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.receiveAsFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import timber.log.Timber
import javax.inject.Inject

/**
 * username 设定页（§3.8 / §16 R13）。
 *
 * ============================================================================
 * ⚠️ 这是本项目为数不多的**不可逆**用户操作，和删号同级
 * ============================================================================
 * 设定之后没有任何 API 能改它 —— 不可变性正是靠「契约里没有 PATCH/PUT
 * `/me/username`」保证的。所以 [submit] **只应该**在用户过了二次确认对话框
 * 之后被调用（对话框本身在 Composable 里，见 `UsernameScreen`）。
 *
 * ============================================================================
 * ⚠️ 那 10 次是账号的命
 * ============================================================================
 * §7.5 给这个端点的配额是按 user **10 次总计的生命周期计数**，永不恢复。
 * 用尽 = 这个账号永远完成不了 onboarding，而 T-108 的拦截器连注销路径都挡着
 * —— 没有任何自助出路（ADR-0017）。
 *
 * 两件事共同守住它：
 * 1. 只有**走到唯一性检查**的请求消耗次数，所以本地预校验挡下的输入是免费的；
 * 2. `DefaultAuthRepository.setUsername` 带一个由名字导出的 `Idempotency-Key`，
 *    「响应丢了、用户再按一次」命中回放而不是再烧一次。
 */
@HiltViewModel
class UsernameViewModel
    @Inject
    constructor(
        private val auth: AuthRepository,
    ) : ViewModel() {
        private val _state = MutableStateFlow(UsernameUiState())
        val state: StateFlow<UsernameUiState> = _state.asStateFlow()

        private val eventChannel = Channel<UsernameEvent>(Channel.BUFFERED)
        val events: Flow<UsernameEvent> = eventChannel.receiveAsFlow()

        fun onInputChanged(value: String) {
            _state.update { it.copy(input = value, progress = UsernameProgress.Idle) }
        }

        /**
         * ⚠️ 调用方**必须**先过二次确认对话框（§3.8 / §16 R13）。
         *
         * 提交的是 [UsernameUiState.normalized]，也就是用户在输入框下方
         * 一直看着的那个字符串 —— 不是他敲进去的原文。
         */
        fun submit() {
            val current = _state.value
            if (!current.canSubmit) return

            viewModelScope.launch {
                _state.update { it.copy(progress = UsernameProgress.Busy) }

                when (val result = auth.setUsername(current.normalized)) {
                    is ApiResult.Success -> {
                        _state.update { it.copy(progress = UsernameProgress.Idle) }
                        eventChannel.send(UsernameEvent.Completed)
                    }

                    is ApiResult.Failure -> {
                        handleFailure(result.error)
                    }
                }
            }
        }

        private suspend fun handleFailure(error: ApiError) {
            if (error is ApiError.UsernameImmutable) {
                // 不是输入错误：这个账号早就设过名字了，本机状态过期。
                // 契约的处置表写的就是「重新拉 GET /me」。
                Timber.w("username_immutable at the setup page; request_id=%s", error.requestId)
                recoverFromStaleState()
                return
            }

            _state.update { it.copy(progress = UsernameProgress.Rejected(error.toRejection())) }
        }

        /**
         * `409 username_immutable` 之后的唯一正确动作：以服务端为准。
         *
         * 拉不到（离线 / 服务端故障）就退回一个普通的可重试错误 ——
         * **不要**在这里硬跳钱包：万一真的还没设名字，跳过去只会在下一个请求上
         * 拿到 `403 username_required` 被弹回来。
         */
        private suspend fun recoverFromStaleState() {
            when (val me = auth.fetchMe()) {
                is ApiResult.Success -> {
                    if (me.value.onboardingComplete) {
                        _state.update { it.copy(progress = UsernameProgress.Idle) }
                        eventChannel.send(UsernameEvent.Completed)
                    } else {
                        _state.update { it.copy(progress = UsernameProgress.Rejected(UsernameRejection.Unexpected)) }
                    }
                }

                is ApiResult.Failure -> {
                    _state.update { it.copy(progress = UsernameProgress.Rejected(me.error.toRejection())) }
                }
            }
        }
    }

/**
 * §6.1 的错误码 → 这一页的拒绝理由。
 *
 * ⚠️ `username_immutable` 不在这张表里：它不是拒绝，是「本机状态过期」，
 * 由 [UsernameViewModel] 单独处置。
 */
internal fun ApiError.toRejection(): UsernameRejection =
    when (this) {
        is ApiError.UsernameTaken -> UsernameRejection.Taken
        is ApiError.UsernameInvalid -> UsernameRejection.Invalid(fieldErrors.toUsernameProblem())
        is ApiError.LimitExceeded -> UsernameRejection.AttemptsExhausted
        is ApiError.RateLimited -> UsernameRejection.RateLimited(retryAfter?.inWholeSeconds)
        is ApiError.Network -> UsernameRejection.Offline
        is ApiError.ServiceUnavailable -> UsernameRejection.ServiceUnavailable
        else -> UsernameRejection.Unexpected
    }

/**
 * 服务端的字段错误能对上本地那三格时就细化一下，对不上就 null。
 *
 * ⚠️ 对不上的主力情形是**保留词** —— 本地根本不判它（[UsernameRules] 的类注释
 * 写了为什么），所以那一路只能显示一句通用的「这个名字不能用」。
 * 这恰好也是对的：文案不得回声命中了哪个保留词，那等于把黑名单发出去。
 */
private fun List<FieldError>.toUsernameProblem(): UsernameProblem? =
    firstOrNull { it.field == "username" }
        ?.let { fieldError ->
            when (fieldError.code) {
                FieldError.Code.TOO_SHORT -> UsernameProblem.TooShort
                FieldError.Code.TOO_LONG -> UsernameProblem.TooLong
                FieldError.Code.INVALID_FORMAT -> UsernameProblem.IllegalCharacters
                else -> null
            }
        }
