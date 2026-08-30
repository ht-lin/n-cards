package de.ncards.core.network.impl.error

import de.ncards.core.network.api.model.Problem
import de.ncards.core.network.api.model.ProblemFieldError
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Assertions.assertTrue
import org.junit.jupiter.api.DisplayName
import org.junit.jupiter.api.Test
import java.net.URI

/**
 * T-010 验收标准第 2 条：**`ApiError` 覆盖 §6.1 错误码表全部条目**。
 *
 * ## 这个测试和编译器的分工
 *
 * [toApiError] 里那个 `when` 没有 `else`，所以「契约新增一个 code 却没人处理」
 * 是**编译期**就挡住的 —— 那比任何测试都早。
 *
 * 这里守的是编译器守不住的另一半：`when` 分支写全了，但两个 code 复制粘贴到了
 * 同一个 [ApiError] 类型上。那种代码编译得过、跑得动，只是 `token_expired` 会
 * 被当成 `token_invalid` 处理 —— 用户被静默踢下线，而没有任何东西会红。
 *
 * 顺带把「枚举里到底有几个 code」钉住：§6.1 的表、
 * `backend/src/Shared/Domain/Error/ErrorCode.php`、
 * `docs/api/schemas/problem-details.schema.json` 已经是三方互钉（见
 * `docs/api/README.md`），本模块是第四方。数字对不上，说明契约变了而这一侧没跟。
 */
@DisplayName("ApiError 覆盖 §6.1 错误码表")
class ApiErrorCoverageTest {
    @Test
    @DisplayName("每个 code 都映射到一个不同的 ApiError 类型，没有复制粘贴的重复")
    fun everyCodeMapsToADistinctType() {
        val mapped = Problem.Code.entries
            .filter { code -> code != Problem.Code.unknown_default_open_api }
            .associateWith { code -> problem(code).toApiError(null, null)::class }

        val duplicated = mapped.entries
            .groupBy({ it.value }, { it.key })
            .filterValues { codes -> codes.size > 1 }

        assertTrue(duplicated.isEmpty()) {
            "这些 code 被映射到了同一个 ApiError 类型上，几乎肯定是复制粘贴漏改：\n" +
                duplicated.entries.joinToString("\n") { (type, codes) ->
                    "  ${type.simpleName} ← ${codes.joinToString()}"
                }
        }
    }

    @Test
    @DisplayName("没有一个 code 落到 Unexpected —— 那是留给契约将来新增的码的")
    fun noKnownCodeFallsThroughToUnexpected() {
        val fellThrough = Problem.Code.entries
            .filter { code -> code != Problem.Code.unknown_default_open_api }
            .filter { code -> problem(code).toApiError(null, null) is ApiError.Unexpected }

        assertTrue(fellThrough.isEmpty()) {
            "这些 code 落到了 ApiError.Unexpected：${fellThrough.joinToString()}"
        }
    }

    @Test
    @DisplayName("契约的 code 枚举是 26 项（外加生成器的 UNKNOWN 兜底）")
    fun errorCodeTableSizeIsPinned() {
        // 26 = §6.1 的表 = ErrorCode.php = problem-details.schema.json。
        // 这个数字变了，先去看 docs/api/README.md 的「三方一致由 CI 强制」那一节，
        // 确认另外三处也一起变了 —— 只有这里变，说明有人改了契约但没改后端。
        assertEquals(26 + 1, Problem.Code.entries.size)
    }

    @Test
    @DisplayName("生成器的兜底枚举项映射到 Unexpected（§13.6：服务端可以新增 code）")
    fun unknownCodeFallsBackToUnexpected() {
        val error = problem(Problem.Code.unknown_default_open_api).toApiError(null, null)
        assertTrue(error is ApiError.Unexpected)
    }

    @Test
    @DisplayName("errors[].code 词表 9 项全覆盖，外加 UNKNOWN 兜底")
    fun everyFieldErrorCodeIsMapped() {
        val mapped = ProblemFieldError.Code.entries.associateWith { code ->
            ProblemFieldError(field = "title", code = code, message = "x").toFieldError().code
        }

        val unknownSources = mapped.filterValues { it == FieldError.Code.UNKNOWN }.keys
        assertEquals(setOf(ProblemFieldError.Code.unknown_default_open_api), unknownSources) {
            "只有生成器的兜底项该映射到 UNKNOWN，实际还有：$unknownSources"
        }
        assertEquals(mapped.size, mapped.values.toSet().size) {
            "有两个 errors[].code 映射到了同一个 FieldError.Code：$mapped"
        }
    }

    private fun problem(code: Problem.Code) =
        Problem(
            type = URI.create("https://api.ncards.de/problems/x"),
            title = "Title",
            status = 400,
            code = code,
            detail = "detail",
            instance = "/v1/cards",
            requestId = "0192f3a1-b2c3-7d4e-8f01-23456789abcd",
        )
}
