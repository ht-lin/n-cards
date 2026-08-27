<?php

declare(strict_types=1);

namespace App\Shared\Domain\Error;

/**
 * Problem Details 的 `errors[]` 里的一项（§6.1）。
 *
 * ```json
 * { "field": "title", "code": "too_long", "message": "Title must be at most 100 characters." }
 * ```
 *
 * `message` 是**英文开发者文案**。§6.1：面向用户的文案一律由客户端本地化生成，
 * 服务端绝不返回可展示的德语文案。这条约束由 ApiProblemFactoryTest 的
 * ASCII-only 断言机械化强制 —— 德语的 ä/ö/ü/ß 都是非 ASCII，泄露一个就 CI 红。
 */
final readonly class FieldError implements \JsonSerializable
{
    /**
     * @param string $field   出错的字段名（snake_case，或 header 名如 `X-Client`）
     * @param string $message 英文开发者文案
     */
    public function __construct(
        public string $field,
        public FieldErrorCode $code,
        public string $message,
    ) {
    }

    /**
     * @return array{field: string, code: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'field' => $this->field,
            'code' => $this->code->value,
            'message' => $this->message,
        ];
    }
}
