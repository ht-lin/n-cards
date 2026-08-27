<?php

declare(strict_types=1);

namespace App\Shared\Domain\Error;

/**
 * §6.1 统一错误码表的 PHP 落地。
 *
 * ============================================================================
 * 为什么在 Shared\Domain 而不是 Shared\Infrastructure\Http
 * ============================================================================
 * deptrac 里每个模块的 Domain 层允许列表只有 `Shared.Domain` 一项
 * （`Identity.Domain: [Shared.Domain]`，其余模块同）。错误码放在任何别的地方，
 * 任何模块的 Domain 与 Application 都**永远无法命名一个错误码** —— 而
 * 「卡不属于你」「username 已被占用」这类判定本来就该由 Domain 做出。
 * 这是本 enum 的位置唯一说得通的地方，改动前先读 §12.2 的分层职责表。
 *
 * ============================================================================
 * 三条不变量
 * ============================================================================
 * 1. `code` 是**机器可读的稳定标识**。§13.6 明令禁止在 `/v1` 内改变一个 code 的含义 ——
 *    Android 侧（T-010）的 `ApiError` sealed class 是照这张表生成的，改含义 = 悄悄改客户端行为。
 *    新增 case 是向后兼容的；改名或改语义不是。
 * 2. `httpStatus()` 是权威的：一个 code 永远不会带着非规范状态码发出去。
 *    `ApiProblemFactory` 只从这里取状态码，不接受调用方覆盖。
 * 3. `httpStatus()` 与 `title()` 用**没有 `default` 分支**的 `match($this)`。
 *    新增 case 忘了补映射 → 第一次被碰到就 `\UnhandledMatchError`，而 phpunit.xml.dist
 *    开了 failOnWarning/failOnRisky，测试直接红。这就是「单测覆盖每个错误码」这条
 *    验收标准的自我强制机制，配合 ApiProblemFactoryTest 的黄金对照表使用。
 *
 * ============================================================================
 * 状态码为什么写裸 int
 * ============================================================================
 * `Response::HTTP_CONFLICT` 属于 `Symfony\Component\HttpFoundation`，在 deptrac 里是
 * `Framework.Http`，而 `Shared.Domain` 的允许列表是**空的**。这里写 409 不是偷懒，
 * 是这一层唯一合法的写法。
 */
enum ErrorCode: string
{
    // ------------------------------------------------------------------ 400
    case ValidationFailed = 'validation_failed';
    /** T-004 新增（§6.1 未覆盖）：请求体不是合法 JSON / 不是 JSON 对象 / 为空。 */
    case MalformedRequest = 'malformed_request';

    // ------------------------------------------------------------------ 401
    case TokenExpired = 'token_expired';
    case TokenInvalid = 'token_invalid';

    // ------------------------------------------------------------------ 403
    case InsufficientRole = 'insufficient_role';
    case NotAMember = 'not_a_member';
    case UsernameRequired = 'username_required';
    case NotFriends = 'not_friends';

    // ------------------------------------------------------------------ 404
    case NotFound = 'not_found';

    // ------------------------------------------------------------------ 405
    /** T-004 新增（§6.1 未覆盖）：路由存在但方法不允许。 */
    case MethodNotAllowed = 'method_not_allowed';

    // ------------------------------------------------------------------ 409
    case RevisionConflict = 'revision_conflict';
    case FullResyncRequired = 'full_resync_required';
    case AlreadyExists = 'already_exists';
    case UsernameTaken = 'username_taken';
    case UsernameImmutable = 'username_immutable';
    /** §5.4.3 里定义了，但 §6.1 的表漏了这一行 —— T-004 一并补进规格。 */
    case IdConflict = 'id_conflict';
    /** T-004 新增：同一个 Idempotency-Key 的前一次请求仍在处理中。响应带 Retry-After。 */
    case IdempotencyInProgress = 'idempotency_in_progress';

    // ------------------------------------------------------------------ 413
    /** T-004 新增：请求体超过上限。 */
    case PayloadTooLarge = 'payload_too_large';

    // ------------------------------------------------------------------ 415
    /** T-004 新增：Content-Type 不是 application/json。 */
    case UnsupportedMediaType = 'unsupported_media_type';

    // ------------------------------------------------------------------ 422
    case UsernameInvalid = 'username_invalid';
    case LimitExceeded = 'limit_exceeded';
    /** T-004 新增：同一个 Idempotency-Key 配了不同的请求体 —— 客户端 bug，重试无用。 */
    case IdempotencyKeyReused = 'idempotency_key_reused';

    // ------------------------------------------------------------------ 426
    case ClientTooOld = 'client_too_old';

    // ------------------------------------------------------------------ 429
    case RateLimited = 'rate_limited';

    // ------------------------------------------------------------------ 500
    /** T-004 新增：未映射的服务端异常。`detail` 恒为固定文案，绝不回显异常消息。 */
    case InternalError = 'internal_error';

    // ------------------------------------------------------------------ 503
    case ServiceUnavailable = 'service_unavailable';

    /**
     * 该 code 对应的 HTTP 状态码。**唯一权威来源**，调用方不得覆盖。
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed, self::MalformedRequest => 400,
            self::TokenExpired, self::TokenInvalid => 401,
            self::InsufficientRole, self::NotAMember,
            self::UsernameRequired, self::NotFriends => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::RevisionConflict, self::FullResyncRequired, self::AlreadyExists,
            self::UsernameTaken, self::UsernameImmutable, self::IdConflict,
            self::IdempotencyInProgress => 409,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::UsernameInvalid, self::LimitExceeded, self::IdempotencyKeyReused => 422,
            self::ClientTooOld => 426,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::ServiceUnavailable => 503,
        };
    }

    /**
     * RFC 9457 的 `title`：对该问题类型的**简短、人类可读、且不随具体出现而变**的概括。
     *
     * 英文开发者文案。面向用户的文案一律由客户端本地化生成（§6.1）。
     */
    public function title(): string
    {
        return match ($this) {
            self::ValidationFailed => 'Validation failed',
            self::MalformedRequest => 'Malformed request',
            self::TokenExpired => 'Access token expired',
            self::TokenInvalid => 'Token invalid',
            self::InsufficientRole => 'Insufficient role',
            self::NotAMember => 'Not a member',
            self::UsernameRequired => 'Username required',
            self::NotFriends => 'Not friends',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::RevisionConflict => 'Revision conflict',
            self::FullResyncRequired => 'Full resync required',
            self::AlreadyExists => 'Already exists',
            self::UsernameTaken => 'Username taken',
            self::UsernameImmutable => 'Username immutable',
            self::IdConflict => 'Id conflict',
            self::IdempotencyInProgress => 'Idempotency key in progress',
            self::PayloadTooLarge => 'Payload too large',
            self::UnsupportedMediaType => 'Unsupported media type',
            self::UsernameInvalid => 'Username invalid',
            self::LimitExceeded => 'Limit exceeded',
            self::IdempotencyKeyReused => 'Idempotency key reused',
            self::ClientTooOld => 'Client too old',
            self::RateLimited => 'Rate limited',
            self::InternalError => 'Internal error',
            self::ServiceUnavailable => 'Service unavailable',
        };
    }

    /**
     * `type` URI 的最后一段：`revision_conflict` → `revision-conflict`（§6.1 的例子就是这个形状）。
     */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    /**
     * 完整的 RFC 9457 `type` URI。
     *
     * `$base` 来自 `%ncards.problem_type_base_uri%`，**在所有环境取同一个值**（生产值）。
     * 理由：RFC 9457 把 `type` 首先当作标识符、其次才是可解引用的 URL；若随
     * staging/prod 变化，按 `type` 分支的客户端会在两个环境里行为不同。
     */
    public function typeUri(string $base): string
    {
        return rtrim($base, '/').'/'.$this->slug();
    }

    /**
     * 该 code 该用哪个 PSR-3 级别记录。
     *
     * ⚠️ 这个方法存在的理由：Symfony 的 `ErrorListener::resolveLogLevel()` 对任何非
     * `HttpExceptionInterface` 的 throwable 一律返回 **CRITICAL**。`DomainException`
     * 不是 `HttpExceptionInterface`，所以放任不管的话，每一个正常的
     * `409 revision_conflict` 都会被记成 CRITICAL，直接污染 §14.4 的
     * 「API 5xx 率高 > 1% 持续 5min」告警。`ApiProblemExceptionListener` 抢在
     * `logKernelException` 之前记日志，级别就取自这里。
     */
    public function logLevel(): string
    {
        return match ($this) {
            // 真正的服务端故障 —— 这是唯一应该惊动 §14.4 告警的一类。
            self::InternalError => 'error',
            // 刻意进入的状态（维护中 / 触发限流），要看得见但不是故障。
            self::ServiceUnavailable, self::RateLimited => 'warning',
            // 其余全是正常的业务分支：viewer 试图改卡、乐观锁冲突、username 被占……
            // 记 info，不记 warning —— 它们在正常运行中每天都会发生若干次。
            default => 'info',
        };
    }

    /**
     * 4xx（客户端可以自行纠正）还是 5xx（服务端的问题）。
     */
    public function isClientError(): bool
    {
        $status = $this->httpStatus();

        return $status >= 400 && $status < 500;
    }
}
