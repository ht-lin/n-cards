<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

/**
 * `Idempotency-Key` 的存储（§6.1：Redis 存 24h，覆盖所有 POST）。
 *
 * 纯接口放在 Application 层：`IdempotencyMiddleware` 在 Infrastructure，但把状态机
 * 与具体的 Redis 命令分开，是为了让中间件的单测不需要 Redis
 * （替身见 `tests/Double/Idempotency/InMemoryIdempotencyStore`）。
 * 真实实现是 `Shared\Infrastructure\Redis\RedisIdempotencyStore`。
 *
 * ============================================================================
 * 两段 TTL，别混
 * ============================================================================
 * - **claim 的 lockTtl = 60 秒**：进程半路死掉时 `kernel.response` 不会触发，
 *   `complete()`/`release()` 都不会被调用。锁一分钟后自然过期，客户端可以重试。
 *   若这里直接用 24 小时，一次崩溃会把那个键**锁死一整天**。
 * - **complete 的 ttl = 24 小时**：这才是 §6.1 说的那个 24h，只作用于已完成的记录。
 */
interface IdempotencyStoreInterface
{
    /**
     * 尝试占用 `$key`。
     *
     * 原子操作（Redis 侧是 `SET key value NX EX lockTtl`）—— 并发的两个请求
     * 必须只有一个拿到 null。
     *
     * @param string $fingerprint    请求体的 sha256
     * @param int    $lockTtlSeconds 在途锁的存活时间，见类注释
     *
     * @return IdempotencyRecord|null null = 抢到了，调用方可以继续处理请求；
     *                                非 null = 这个键已被占用，调用方按记录状态决定回放还是报错
     *
     * @throws IdempotencyStoreUnavailable 存储不可达 —— 调用方 fail-open
     */
    public function claim(string $key, string $fingerprint, int $lockTtlSeconds): ?IdempotencyRecord;

    /**
     * 把在途记录换成已完成记录，供后续回放。
     *
     * 只对 **2xx** 响应调用。理由见 `IdempotencyMiddleware` 的类注释 ——
     * 回放一个 `401 token_expired` 会永久打死 §6.1 规定的「静默刷新后重试一次」。
     *
     * ⚠️ `$fingerprint` 必须由调用方**原样传回** claim 时用的那个值，实现**不得**
     * 自己回读存储去重建它。在途锁只有 60 秒（见类注释），一个耗时超过 60 秒的请求
     * 走到这里时锁已经过期 —— 回读只会得到空值，于是已完成记录带着空指纹落库，
     * 之后**同键同 body** 的正常重试会被判成 `422 idempotency_key_reused`。
     * 而按 §5.4.3，Android 的 outbox 不重试 409/429 之外的 4xx：客户端会永久放弃
     * 一笔服务端其实已经成功的操作。
     *
     * @param string                $fingerprint 与 {@see claim()} 同一个值
     * @param array<string, string> $headers     白名单过的响应头
     *
     * @throws IdempotencyStoreUnavailable
     */
    public function complete(string $key, string $fingerprint, int $status, array $headers, string $body, int $ttlSeconds): void;

    /**
     * 释放在途锁，让客户端可以用同一个键重试。
     *
     * 非 2xx 响应走这条路 —— 那些错误要么是客户端可纠正的（改字段重提），
     * 要么是确定性可重现的（数据库会再给一次同样的 409）。
     *
     * @throws IdempotencyStoreUnavailable
     */
    public function release(string $key): void;
}
