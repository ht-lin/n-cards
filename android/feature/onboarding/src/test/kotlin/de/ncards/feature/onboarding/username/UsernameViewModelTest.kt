package de.ncards.feature.onboarding.username

import app.cash.turbine.test
import de.ncards.core.model.user.UsernameProblem
import de.ncards.core.network.impl.error.ApiError
import de.ncards.feature.onboarding.FakeAuthRepository
import de.ncards.feature.onboarding.MainDispatcherExtension
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runTest
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertFalse
import org.junit.jupiter.api.Assertions.assertNull
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import org.junit.jupiter.api.extension.RegisterExtension

@OptIn(ExperimentalCoroutinesApi::class)
@DisplayName("UsernameViewModel")
class UsernameViewModelTest {
    @JvmField
    @RegisterExtension
    val mainDispatcher = MainDispatcherExtension()

    private val auth = FakeAuthRepository()

    private fun viewModel() = UsernameViewModel(auth)

    // ---- 本地预校验 -----------------------------------------------------

    @Test
    @DisplayName("空输入：按钮灰着，但不报错")
    fun emptyInputShowsNoError() =
        runTest {
            val viewModel = viewModel()

            assertNull(viewModel.state.value.localProblem)
            assertFalse(viewModel.state.value.canSubmit)
        }

    @Test
    @DisplayName("字符集与长度就地拦下，不发请求")
    fun localValidationGatesSubmission() =
        runTest {
            val viewModel = viewModel()

            viewModel.onInputChanged("ab")
            assertEquals(UsernameProblem.TooShort, viewModel.state.value.localProblem)
            assertFalse(viewModel.state.value.canSubmit)

            viewModel.onInputChanged("anna b")
            assertEquals(UsernameProblem.IllegalCharacters, viewModel.state.value.localProblem)

            viewModel.submit()
            advanceUntilIdle()
            assertTrue(auth.assignedUsernames.isEmpty())
        }

    /**
     * ⚠️ 用户按下「确认」提交的必须是他**眼睛看着的**那个字符串。
     * 这个操作不可逆（§16 R13），提交一个他没见过的值是不能接受的。
     */
    @Test
    @DisplayName("归一化：预览与提交值一致，且都是 trim + 小写")
    fun normalizedValueIsWhatGetsSubmitted() =
        runTest {
            val viewModel = viewModel()
            viewModel.onInputChanged("  Anna_B  ")

            assertEquals("anna_b", viewModel.state.value.normalized)
            assertTrue(viewModel.state.value.canSubmit)

            viewModel.submit()
            advanceUntilIdle()

            assertEquals("anna_b", auth.assignedUsernames.single())
        }

    /**
     * ⚠️ 保留词**不在客户端判**（见 `UsernameRules` 的类注释）。
     * 抄一份进来就有了第二个真相源，而 Q9 至今未定案；
     * 服务端拒它是 `422 username_invalid`，不消耗 10 次计数，所以放行是免费的。
     */
    @Test
    @DisplayName("保留词在本地放行，交给服务端判")
    fun reservedWordsAreNotBlockedLocally() =
        runTest {
            val viewModel = viewModel()
            viewModel.onInputChanged("admin")

            assertNull(viewModel.state.value.localProblem)
            assertTrue(viewModel.state.value.canSubmit)
        }

    // ---- 服务端的四种拒绝 -----------------------------------------------

    @Test
    @DisplayName("409 username_taken → 提示重选")
    fun takenIsShownInline() =
        runTest {
            auth.setUsernameResult = FakeAuthRepository.failure(ApiError.UsernameTaken("req-1"))

            val viewModel = submitting("anna_b")

            assertEquals(UsernameProgress.Rejected(UsernameRejection.Taken), viewModel.state.value.progress)
        }

    @Test
    @DisplayName("422 username_invalid → 提示重选（保留词走这一路，无字段错误）")
    fun invalidIsShownInline() =
        runTest {
            auth.setUsernameResult =
                FakeAuthRepository.failure(ApiError.UsernameInvalid(fieldErrors = emptyList(), requestId = "req-1"))

            val viewModel = submitting("admin")

            assertEquals(
                UsernameProgress.Rejected(UsernameRejection.Invalid(problem = null)),
                viewModel.state.value.progress,
            )
        }

    /**
     * ⚠️ ADR-0017 决策二逐字点名了这条：422 与 429 在 Android 上的处置分别是
     * 「终局错误，展示给用户」与「自动退避重试」，**选错的后果是用户看着一个
     * 永远转圈的按钮**。所以这两格必须分得开。
     */
    @Test
    @DisplayName("422 limit_exceeded → 终局，与 429 不是同一格")
    fun exhaustedAttemptsAreTerminal() =
        runTest {
            auth.setUsernameResult = FakeAuthRepository.failure(ApiError.LimitExceeded("req-1"))

            val viewModel = submitting("anna_b")

            assertEquals(
                UsernameProgress.Rejected(UsernameRejection.AttemptsExhausted),
                viewModel.state.value.progress,
            )
        }

    /**
     * `409 username_immutable` **不是输入错误**：这个账号早就设过名字了，
     * 本机状态过期。契约的处置表写的是「重新拉 `GET /me`」。
     */
    @Test
    @DisplayName("409 username_immutable → 重新拉 /me，然后进钱包")
    fun immutableRecoversByRefetchingMe() =
        runTest {
            auth.setUsernameResult = FakeAuthRepository.failure(ApiError.UsernameImmutable("req-1"))
            auth.fetchMeResult = FakeAuthRepository.success(FakeAuthRepository.user(onboardingComplete = true))
            val viewModel = viewModel()
            viewModel.onInputChanged("anna_b")

            viewModel.events.test {
                viewModel.submit()
                advanceUntilIdle()

                assertEquals(UsernameEvent.Completed, awaitItem())
            }
            assertEquals(1, auth.fetchMeCalls)
        }

    /**
     * ⚠️ `/me` 也拉不到时**不要**硬跳钱包：万一真的还没设名字，跳过去只会在
     * 下一个请求上拿到 `403 username_required` 被弹回来，而用户看不懂发生了什么。
     */
    @Test
    @DisplayName("409 username_immutable 之后 /me 也失败 → 停在本页，可重试")
    fun immutableWithoutNetworkStaysOnThePage() =
        runTest {
            auth.setUsernameResult = FakeAuthRepository.failure(ApiError.UsernameImmutable("req-1"))
            auth.fetchMeResult = FakeAuthRepository.failure(ApiError.Network(java.io.IOException("offline")))

            val viewModel = submitting("anna_b")

            assertEquals(UsernameProgress.Rejected(UsernameRejection.Offline), viewModel.state.value.progress)
        }

    @Test
    @DisplayName("成功 → Completed")
    fun successCompletesOnboarding() =
        runTest {
            val viewModel = viewModel()
            viewModel.onInputChanged("anna_b")

            viewModel.events.test {
                viewModel.submit()
                advanceUntilIdle()

                assertEquals(UsernameEvent.Completed, awaitItem())
            }
        }

    private fun kotlinx.coroutines.test.TestScope.submitting(input: String): UsernameViewModel {
        val viewModel = viewModel()
        viewModel.onInputChanged(input)
        viewModel.submit()
        advanceUntilIdle()
        return viewModel
    }
}
