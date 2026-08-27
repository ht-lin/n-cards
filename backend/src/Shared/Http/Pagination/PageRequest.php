<?php

declare(strict_types=1);

namespace App\Shared\Http\Pagination;

use App\Shared\Domain\Pagination\Cursor;

/**
 * 一次分页查询的入参：取多少、从哪开始。
 */
final readonly class PageRequest
{
    public function __construct(
        public int $limit,
        public ?Cursor $cursor = null,
    ) {
    }

    /**
     * 仓储实际要取的行数 = limit + 1。
     *
     * 多取一行是判断 `has_more` 的手段，而且是**唯一**不需要第二次查询的手段。
     * 用 `COUNT(*)` 会把 offset 思维和一次全表扫描一起请回来（§6.1：不使用 offset）。
     * 多出来的那行由 {@see CursorPaginator::paginate()} 丢掉，不会出现在响应里。
     */
    public function fetchLimit(): int
    {
        return $this->limit + 1;
    }
}
