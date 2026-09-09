<?php

declare(strict_types=1);

namespace App\Shared\Http\Concurrency;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use Symfony\Component\HttpFoundation\Request;

/**
 * `If-Match: "7"` 的解析（§5.4.3 的乐观锁、§17.2）。
 *
 * ============================================================================
 * 为什么在 Shared\Http 而不是 Wallet
 * ============================================================================
 * 它解析的是一个**标准 HTTP 头**，里面没有任何卡片的概念 ——
 * 与 {@see \App\Shared\Http\Pagination\CursorPaginator} 解析 `?cursor=&limit=`
 * 是同一类东西。T-109 是第一个用到它的端点，但 `revision` 这个机制在 §5.4.3
 * 里是给「所有可并发编辑的资源」定义的。
 *
 * ============================================================================
 * ⚠️ 这里只解析，**不比较**
 * ============================================================================
 * 「客户端给的 revision 与服务端当前的一样吗」是 Application 层的判断，
 * 而且它必须与「读出实体」在同一个事务里 —— 在这里比等于把一个业务不变量
 * 搬进了 HTTP 解析器。本类只回答「客户端说的是几」。
 *
 * ============================================================================
 * 缺失是 400 `validation_failed`，不是 428
 * ============================================================================
 * RFC 6585 有个 `428 Precondition Required` 正好描述这件事，但：
 *
 *   - §6.1 的错误码表里没有它，而那张表是**闭合**的（`ErrorCode` 的
 *     `httpStatus()` 用无 default 分支的 match 自我强制）。为一个头加一个
 *     全新的状态码要动契约、动 problem-details schema、动 Android 的拦截器。
 *   - 契约把 `If-Match` 写成 `required: true` 的参数。**缺一个必填参数**在这个
 *     API 里一律是 `400 validation_failed` + `errors[].code = required` ——
 *     客户端已经有处理这条的分支了（T-010 的生成器按契约生成），
 *     而 428 会掉进它的「未知状态码」兜底里。
 *
 * 换句话说：428 更精确，但精确度换不来任何客户端行为上的差别，
 * 而它要付的是一个新错误码的全部成本。
 *
 * ============================================================================
 * ⚠️ 弱验证器与 `*` 一律拒绝
 * ============================================================================
 * `If-Match: *`（RFC 9110：「资源存在即匹配」）与 `W/"7"`（弱验证器）
 * 在语法上都是合法的 `If-Match`，这里都报 `invalid_format`：
 *
 *   - `*` 的语义是「我不在乎版本，只要它还在就写」—— 那恰好是乐观锁要挡住的
 *     那件事。默默接受它等于给客户端开了一条绕过冲突检测的后门。
 *   - 弱验证器的定义是「语义等价但字节可能不同」，而 revision 是一个精确计数，
 *     没有「弱相等」这回事。接受 `W/"7"` 只会让人以为它有别的含义。
 *
 * 两者都不是「还没实现」，所以报的是格式错误而不是 501。
 */
final readonly class IfMatch
{
    /**
     * 只认**一个**强验证器，且内容是十进制非负整数。
     *
     * 逗号分隔的多值列表（`"7", "8"`）也不接受：那的语义是「其中任意一个匹配即可」，
     * 而对一个单调递增的 revision 来说，同时接受两个版本没有任何合理用途。
     */
    private const PATTERN = '/^"(\d{1,19})"$/';

    public const HEADER = 'If-Match';

    /**
     * 本次请求的 `If-Match` 里那个 revision。
     *
     * @throws DomainException `validation_failed`（400）—— 缺失或格式非法
     */
    public static function revision(Request $request): int
    {
        $raw = $request->headers->get(self::HEADER);

        if (null === $raw || '' === trim($raw)) {
            throw DomainException::validationFailed(new FieldError(self::HEADER, FieldErrorCode::Required, 'The If-Match header is required and must carry the current revision, e.g. If-Match: "7".'));
        }

        if (1 !== preg_match(self::PATTERN, trim($raw), $matches)) {
            // ⚠️ detail 里**不回显** $raw。它是客户端可控的字符串，而 detail
            // 会进日志与 Sentry —— 口径同 AbstractApiController::decodeBody()。
            throw DomainException::validationFailed(new FieldError(self::HEADER, FieldErrorCode::InvalidFormat, 'The If-Match header must be a single quoted revision number, e.g. If-Match: "7". Weak validators and "*" are not accepted.'));
        }

        // 19 位十进制在 64 位 PHP 上一定放得下（PHP_INT_MAX 是 19 位），
        // 所以这里不会溢出。BIGINT 的上限本身也是 19 位。
        return (int) $matches[1];
    }
}
