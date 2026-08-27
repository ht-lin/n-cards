<?php

declare(strict_types=1);

namespace App\Shared\Http\Pagination;

use App\Shared\Domain\Pagination\Cursor;

/**
 * 一页结果 + 游标。序列化形态就是 §6.1 的通用列表信封：.
 *
 * ```json
 * { "items": [ ... ], "next_cursor": "eyJ2IjoxLC...", "has_more": true }
 * ```
 *
 * `next_cursor` / `has_more` 的名字沿用 §5.4.2 的同步响应 —— 唯一的新名字是 `items`。
 * 这个信封是 §6.1/§6.2 从未明说的规格缺口，T-004 一并补进规格与 docs/api/README.md，
 * 好让 T-007 只 schematise 一次而不是给七个列表端点各写一遍。
 *
 * @template T
 */
final readonly class Page implements \JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public ?Cursor $nextCursor,
        public bool $hasMore,
    ) {
    }

    /**
     * @return array{items: list<T>, next_cursor: string|null, has_more: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'items' => $this->items,
            // has_more 为 false 时恒为 null —— 客户端据此停止翻页。
            'next_cursor' => null === $this->nextCursor ? null : (string) $this->nextCursor,
            'has_more' => $this->hasMore,
        ];
    }
}
