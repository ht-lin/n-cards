<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Shared\Domain\Error\DomainException;

/**
 * `PUT /v1/cards/{id}/placement` 的请求体（契约的 `CardPlacement`，T-110）。
 *
 * 形状照抄 {@see CardCreatePayload}：静态 `fromArray()`、先扫未知字段、
 * 逐字段累积 {@see \App\Shared\Domain\Error\FieldError}、最后一次性抛。
 *
 * ============================================================================
 * 两个字段都是必填
 * ============================================================================
 * 契约里 `CardPlacement` 的 `required` 是 `[sort_order, is_pinned]` ——
 * 这不是疏忽。placement 是一次**完整的摆放声明**，不是部分更新：
 * 客户端拖完一次列表就把两个值一起送上来。做成可选的话，
 * 「只发 `is_pinned` 意味着 `sort_order` 保持不变还是重置」就成了一个
 * 两端要各自记住的约定，而契约里没有地方写它。
 *
 * 所以这里**没有** `CardUpdatePayload` 那套 `*Present` 布尔。
 *
 * ============================================================================
 * ⚠️ `sort_order` 服务端**没有任何语义**
 * ============================================================================
 * 它不唯一、不归一化、不校验连续性 —— 两张卡可以都是 0。
 * `GET /v1/cards` 按 `id` 做 keyset 分页排序，**不是**按这个字段排。
 * 真正的排序发生在客户端：Android 从 Room 里读，按 `is_pinned DESC,
 * sort_order ASC` 渲染（T-153）。
 *
 * ⚠️ 别「顺手修好」`CardRepositoryInterface::findOwnedPage()` 让它按
 * `sort_order` 排。那个方法用 `id` 当游标键，靠的是 UUIDv7 单调递增；
 * 换成一个可重复、客户端可控的整数，游标就不再能唯一定位一行，
 * 分页会开始漏卡或重复发卡。而且那张表在 Sharing 模块里，
 * Wallet 的查询根本 JOIN 不到它（§4.2 规则 5）。
 */
final readonly class CardPlacementPayload
{
    /** 契约 `CardPlacement` 的全部属性。 */
    private const ALLOWED_FIELDS = ['sort_order', 'is_pinned'];

    public function __construct(
        public int $sortOrder,
        public bool $isPinned,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws DomainException `validation_failed`（400）
     */
    public static function fromArray(array $body): self
    {
        $errors = [];

        CardFields::rejectUnknown($body, self::ALLOWED_FIELDS, $errors);

        // ⚠️ int32 的范围检查在 requiredInt32() 里，不是可有可无的 ——
        // `card_members.sort_order` 是 PG 的 INTEGER，越界的值不挡住就是一个
        // 500 而不是契约写的 400。见那个方法的注释。
        $sortOrder = CardFields::requiredInt32($body, 'sort_order', $errors);
        $isPinned = CardFields::requiredBool($body, 'is_pinned', $errors);

        if ([] !== $errors) {
            throw DomainException::validationFailed(...$errors);
        }

        // 到这里两个必填项必然非 null（为 null 时上面已经记了错误）。
        // 断言只为让 PHPStan 收窄类型 —— 口径同 CardCreatePayload::fromArray()。
        \assert(null !== $sortOrder && null !== $isPinned);

        return new self($sortOrder, $isPinned);
    }
}
