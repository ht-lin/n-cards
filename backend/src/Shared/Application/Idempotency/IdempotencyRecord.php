<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

/**
 * 一个 `Idempotency-Key` 在存储里的状态（§6.1：所有 POST 支持该 header，Redis 存 24h）。
 *
 * 两种形态：
 *   - **在途**（`completed = false`）：某个请求抢到了这个键但还没跑完。
 *     并发的第二个请求撞上它 → `409 idempotency_in_progress`。
 *   - **已完成**（`completed = true`）：带着当时的响应，供回放。
 *
 * `fingerprint` 是请求体的 sha256。同一个键配上不同的 body 是**客户端 bug**
 * （性质同 §6.1 让客户端上报 Sentry 的 `username_immutable`），重试永远不会成功 ——
 * 所以是 `422 idempotency_key_reused` 而不是 409。
 */
final readonly class IdempotencyRecord
{
    /**
     * @param string                $fingerprint 首次请求体的 sha256
     * @param array<string, string> $headers     回放时要一并还原的响应头（白名单过的）
     * @param string|null           $body        已完成时的响应体；在途时为 null
     */
    private function __construct(
        public string $fingerprint,
        public bool $completed,
        public ?int $status = null,
        public array $headers = [],
        public ?string $body = null,
    ) {
    }

    public static function inProgress(string $fingerprint): self
    {
        return new self($fingerprint, false);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function completed(string $fingerprint, int $status, array $headers, string $body): self
    {
        return new self($fingerprint, true, $status, $headers, $body);
    }
}
