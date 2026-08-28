<?php

declare(strict_types=1);

namespace App\Tests\Double\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyRecord;
use App\Shared\Application\Idempotency\IdempotencyStoreInterface;
use App\Shared\Application\Idempotency\IdempotencyStoreUnavailable;

/**
 * 数组实现的幂等存储。
 *
 * 让 IdempotencyMiddleware 的状态机（抢占 / 回放 / 指纹不符 / 在途）能在
 * 不起 Redis 的情况下被完整测到。真实的 Redis 语义（`SET NX EX` 的原子性、TTL）
 * 由 tests/Integration/Shared/Redis/RedisIdempotencyStoreTest 用真容器覆盖。
 */
final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string, IdempotencyRecord> */
    public array $records = [];

    /** @var list<string> */
    public array $released = [];

    /** 非 null 时所有方法都抛这个异常 —— 用来验 fail-open 分支。 */
    public ?IdempotencyStoreUnavailable $failWith = null;

    /**
     * true 时 `complete()` 会先把在途记录抹掉，模拟「请求耗时超过 60 秒的
     * LOCK_TTL_SECONDS，在途锁已经自然过期」。
     *
     * 真实 Redis 里这是个纯时间条件，测试没法等 60 秒 —— 但它决定了
     * complete 能不能拿到正确的指纹，所以必须可模拟。
     */
    public bool $lockExpiresBeforeComplete = false;

    public function claim(string $key, string $fingerprint, int $lockTtlSeconds): ?IdempotencyRecord
    {
        $this->guard();

        if (isset($this->records[$key])) {
            return $this->records[$key];
        }

        $this->records[$key] = IdempotencyRecord::inProgress($fingerprint);

        return null;
    }

    public function complete(string $key, string $fingerprint, int $status, array $headers, string $body, int $ttlSeconds): void
    {
        $this->guard();

        if ($this->lockExpiresBeforeComplete) {
            unset($this->records[$key]);
        }

        // ⚠️ 指纹取参数，**不**从 $this->records 里捞。
        // 之前这里写的是 `$this->records[$key]->fingerprint ?? ''`，恰好复制了
        // RedisIdempotencyStore 回读重建指纹的那个 bug —— 于是替身和真实现同时错，
        // 中间件的单测一条都测不出来。替身可以简化存储，但不能复制它的错误假设。
        $this->records[$key] = IdempotencyRecord::completed(
            $fingerprint,
            $status,
            $headers,
            $body,
        );
    }

    public function release(string $key): void
    {
        $this->guard();

        unset($this->records[$key]);
        $this->released[] = $key;
    }

    /**
     * 直接种一条已完成记录，省得测试为了造回放场景先跑一次完整请求。
     *
     * @param array<string, string> $headers
     */
    public function seedCompleted(string $key, string $fingerprint, int $status, array $headers, string $body): void
    {
        $this->records[$key] = IdempotencyRecord::completed($fingerprint, $status, $headers, $body);
    }

    public function seedInProgress(string $key, string $fingerprint): void
    {
        $this->records[$key] = IdempotencyRecord::inProgress($fingerprint);
    }

    private function guard(): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
    }
}
