<?php

declare(strict_types=1);

namespace App\Shared\Domain\Error;

/**
 * 所有可以被翻译成 RFC 9457 Problem Details 的业务异常的基类（§6.1、§12.2）。
 *
 * ============================================================================
 * 用法
 * ============================================================================
 * 任何一层都可以 `throw`，由 `Shared\Infrastructure\Http\ApiProblemExceptionListener`
 * 统一渲染。业务代码**不构造响应**，只抛异常 —— 这是「Domain 不得了解 HTTP」
 * （§12.2 分层职责表）在实践中的落法：Domain 表达「哪种错」，Http 层决定「长什么样」。
 *
 * ```php
 * throw DomainException::revisionConflict(['revision' => $card->revision()]);
 * throw DomainException::validationFailed(
 *     new FieldError('title', FieldErrorCode::TooLong, 'Title must be at most 100 characters.'),
 * );
 * ```
 *
 * ============================================================================
 * 刻意**不** final
 * ============================================================================
 * 各模块会继承它做更具体的类型（`CardNotFoundException` 之类），好让
 * `catch` 能按类型收窄而不是按 code 比字符串。子类只需在构造里定死 `ErrorCode`。
 *
 * ============================================================================
 * 三条约束
 * ============================================================================
 * 1. `$detail` 只写**英文开发者文案**。绝不放可展示的德语文案（§6.1），
 *    也绝不把用户输入原样拼进去 —— `detail` 会进日志与 Sentry。
 * 2. `$current` 是 §5.4.3 冲突解决要用的「服务端当前状态」快照，只在
 *    `revision_conflict` 一类场景有意义，其余场景留空即可（为空时不会出现在响应里）。
 * 3. 继承自 `\RuntimeException` 而不是自定义接口：PHP 的异常层级已经够用，
 *    而且 `\RuntimeException` 保证它能被任何通用的 `catch (\Throwable)` 兜住。
 *    注意它**不是** `HttpExceptionInterface` —— 相关后果见 ErrorCode::logLevel() 的注释。
 */
class DomainException extends \RuntimeException
{
    /** @var list<FieldError> */
    private readonly array $fieldErrors;

    /** @var array<string, mixed> */
    private readonly array $current;

    /**
     * ⚠️ 属性名是 `$errorCode` 而不是 `$code`：`\Exception::$code` 已经占了后者，
     * 它是 protected、readwrite、且无原生类型的 int —— 覆盖它在 PHP 层面是非法的。
     * 这也顺带说明了为什么 `parent::__construct()` 的第二个参数恒传 0。
     *
     * @param string                       $detail  英文开发者文案；留空时由 Http 层回落到 code 的通用文案
     * @param array<array-key, FieldError> $errors  字段级错误，仅 `validation_failed` 一类用得上
     * @param array<string, mixed>         $current 服务端当前状态快照（§5.4.3 的冲突解决）
     */
    public function __construct(
        private readonly ErrorCode $errorCode,
        string $detail = '',
        array $errors = [],
        array $current = [],
        ?\Throwable $previous = null,
    ) {
        // 父类的 $message 用 $detail —— 这样任何按标准方式打印异常的地方
        // （日志、Sentry、phpunit 失败输出）都能看到有意义的内容。
        // 父类的 $code 恒为 0：它是 int 语义，塞不下我们的字符串 code。
        parent::__construct($detail, 0, $previous);

        // array_values 是必要的：调用方可能传进来一个带键的数组，而 jsonSerialize
        // 之后会被 json_encode —— 带键的数组会编成 JSON 对象，而 `errors` 必须是数组。
        $this->fieldErrors = array_values($errors);
        $this->current = $current;
    }

    public function errorCode(): ErrorCode
    {
        return $this->errorCode;
    }

    public function detail(): string
    {
        return $this->getMessage();
    }

    /**
     * @return list<FieldError>
     */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return $this->current;
    }

    // ========================================================================
    // 命名构造 —— 覆盖最常用的几种，让调用点读起来是业务语言而不是错误码语言。
    // ========================================================================

    public static function validationFailed(FieldError ...$errors): self
    {
        return new self(
            ErrorCode::ValidationFailed,
            'One or more fields failed validation.',
            $errors,
        );
    }

    public static function notFound(string $detail = 'The requested resource does not exist.'): self
    {
        return new self(ErrorCode::NotFound, $detail);
    }

    /**
     * @param array<string, mixed> $current 服务端当前状态，客户端据此走 §5.4.3 的冲突解决
     */
    public static function revisionConflict(array $current): self
    {
        return new self(
            ErrorCode::RevisionConflict,
            'The resource was modified by someone else.',
            [],
            $current,
        );
    }

    /**
     * §7.5 的系统限额。注意与**速率限制**（429 `rate_limited`）是两回事 —— 见 T-006。
     *
     * @param string $limit 限额名，例如 `cards_per_user`；只进 detail，不进 errors[]
     */
    public static function limitExceeded(string $limit, int $max): self
    {
        return new self(
            ErrorCode::LimitExceeded,
            \sprintf('The limit "%s" of %d has been reached.', $limit, $max),
        );
    }

    public static function alreadyExists(string $detail = 'The resource already exists.'): self
    {
        return new self(ErrorCode::AlreadyExists, $detail);
    }

    /**
     * 调用者是这个资源的成员，但角色不够（403，§5.2 的角色权限矩阵）。
     *
     * 典型调用点：`PATCH` / `DELETE /v1/cards/{id}` 的非 owner ——
     * 任务卡逐字要求「**一律** 403，即使请求体合法、revision 正确」，
     * 所以这条判定要排在请求体校验与乐观锁**之前**。
     *
     * ⚠️ 与 `not_a_member`（也是 403）不是一回事，别合并：
     * 契约让客户端对后者**删掉本地副本**（说明共享已被撤销），
     * 对前者只是禁用编辑 UI 并上报 Sentry（正常 UI 不该产生这个请求）。
     * 压成一个码，客户端就只能选一种处置，而两种都会出错。
     */
    public static function insufficientRole(string $detail = 'Your role on this resource does not permit this operation.'): self
    {
        return new self(ErrorCode::InsufficientRole, $detail);
    }

    /**
     * 客户端生成的 id 已经属于**别人**（409，§5.4.3）。
     *
     * 只用于「id 由客户端生成」的资源（一期只有 `cards`）。契约要求客户端
     * **重新生成一个 id 再重试** —— 所以它必须与 `already_exists` 区分开：
     * 后者的正确处置是「别重试，你要的东西已经在了」。
     *
     * ⚠️ detail 里**不放**那个 id 属于谁的任何信息。请求方对那张卡没有任何权限，
     * 而 `detail` 会进日志与 Sentry。它甚至不该确认「那是一张卡」——
     * 但这一点做不到，因为端点本身就是 `/v1/cards`。
     */
    public static function idConflict(string $detail = 'The supplied id already belongs to another user; generate a new one and retry.'): self
    {
        return new self(ErrorCode::IdConflict, $detail);
    }

    /**
     * 未预期的服务端故障。
     *
     * `detail` 是**固定文案**：原始异常消息只进日志，绝不进响应体 ——
     * 它可能含连接串、SQL 片段或加密载荷。原异常挂在 `previous` 上供日志取用。
     */
    public static function internal(\Throwable $previous): self
    {
        return new self(
            ErrorCode::InternalError,
            'An unexpected error occurred.',
            [],
            [],
            $previous,
        );
    }
}
