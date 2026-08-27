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

    public function claim(string $key, string $fingerprint, int $lockTtlSeconds): ?IdempotencyRecord
    {
        $this->guard();

        if (isset($this->records[$key])) {
            return $this->records[$key];
        }

        $this->records[$key] = IdempotencyRecord::inProgress($fingerprint);

        return null;
    }

    public function complete(string $key, int $status, array $headers, string $body, int $ttlSeconds): void
    {
        $this->guard();

        $this->records[$key] = IdempotencyRecord::completed(
            $this->records[$key]->fingerprint ?? '',
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
