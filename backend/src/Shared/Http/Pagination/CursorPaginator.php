<?php

declare(strict_types=1);

namespace App\Shared\Http\Pagination;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Pagination\Cursor;
use Symfony\Component\HttpFoundation\Request;

/**
 * 游标分页（§6.1：`?cursor=&limit=`，默认 50 / 最大 200，**不使用** offset）。
 *
 * ============================================================================
 * limit：高位夹取，非法拒绝
 * ============================================================================
 * `limit=500` → 静默夹到 200。这是个合理的请求，夹一下就好；而且**夹取意味着
 * 将来抬高 MAX_LIMIT 仍然向后兼容**（§13.6 禁止收紧校验、允许放宽）。
 * 客户端总能靠 `has_more` 察觉到被夹了。
 *
 * `limit=abc` / `limit=0` / `limit=-1` → 400。这些是客户端 bug，静默纠正只会
 * 让 bug 活得更久。
 *
 * ============================================================================
 * ⚠️ offset 是**主动拒绝**的，不是「没实现」
 * ============================================================================
 * §6.1 写的是「**不使用** offset」。如果只是忽略这个参数，一个发 `?offset=50`
 * 的客户端会永远收到第一页 —— 没有报错、没有日志、没有人发现，直到用户报障说
 * 「列表翻不动」。所以任何 offset 风格的参数一律 400，
 * 并用 {@see FieldErrorCode::UnsupportedParameter} 明说原因。
 */
final readonly class CursorPaginator
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    /** offset 风格的参数一律拒绝。列全一点，客户端换个名字试也要撞墙。 */
    public const FORBIDDEN_PARAMS = ['offset', 'page', 'skip', 'per_page', 'start'];

    /**
     * 从 query string 解析出分页入参。
     *
     * @throws DomainException `validation_failed`
     */
    public function pageRequest(Request $request): PageRequest
    {
        foreach (self::FORBIDDEN_PARAMS as $forbidden) {
            if ($request->query->has($forbidden)) {
                throw DomainException::validationFailed(new FieldError($forbidden, FieldErrorCode::UnsupportedParameter, 'Offset pagination is not supported; use the opaque cursor parameter.'));
            }
        }

        return new PageRequest(
            $this->limit($request),
            $this->cursor($request),
        );
    }

    /**
     * 把仓储多取一行的结果切成一页。
     *
     * @template T
     *
     * @param list<T>                           $rows     恰好按 `PageRequest::fetchLimit()` 取回的行
     * @param \Closure(T): array<string, mixed> $cursorOf 从一行算出它的游标载荷
     *
     * @return Page<T>
     */
    public function paginate(array $rows, PageRequest $request, \Closure $cursorOf): Page
    {
        $hasMore = \count($rows) > $request->limit;
        $items = $hasMore ? \array_slice($rows, 0, $request->limit) : $rows;

        // 游标取自**保留下来的最后一行**，不是被丢掉的那行 ——
        // 取错的话下一页会跳过一条记录，而且是静默跳过。
        $nextCursor = $hasMore && [] !== $items
            ? Cursor::encode($cursorOf($items[\count($items) - 1]))
            : null;

        return new Page($items, $nextCursor, $hasMore);
    }

    private function limit(Request $request): int
    {
        $raw = $request->query->get('limit');

        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LIMIT;
        }

        // $raw 到这里已经被 query->get() 与上面的守卫收窄成非空字符串。
        if (1 !== preg_match('/^\d+$/', $raw)) {
            throw DomainException::validationFailed(new FieldError('limit', FieldErrorCode::InvalidType, 'The limit parameter must be a positive integer.'));
        }

        $limit = (int) $raw;

        if ($limit < 1) {
            throw DomainException::validationFailed(new FieldError('limit', FieldErrorCode::OutOfRange, 'The limit parameter must be at least 1.'));
        }

        // 夹取而不是报错 —— 理由见类注释。
        return min($limit, self::MAX_LIMIT);
    }

    private function cursor(Request $request): ?Cursor
    {
        $raw = $request->query->get('cursor');

        if (null === $raw || '' === $raw) {
            return null;
        }

        // 解析失败时 Cursor::decode 自己抛 validation_failed。
        // /v1/sync 需要把它改成 409 full_resync_required（§5.4.1）—— 那是
        // T-202 的 SyncController 捕获后自己决定的事，不在这里。
        return Cursor::decode($raw);
    }
}
