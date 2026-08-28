<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use App\Shared\Application\Idempotency\IdempotencyRecord;
use App\Shared\Application\Idempotency\IdempotencyStoreInterface;
use App\Shared\Application\Idempotency\IdempotencyStoreUnavailable;

/**
 * {@see IdempotencyStoreInterface} 的 Redis 实现（§6.1：Redis 存 24h）。
 *
 * ============================================================================
 * claim 必须是原子的
 * ============================================================================
 * `SET key value NX EX ttl` 一条命令完成「不存在才写」。
 * 拆成 `EXISTS` + `SET` 会留下一个竞态窗口 —— 两个并发的重试**都**会认为自己
 * 抢到了键，于是那笔创建被执行两次，而幂等的全部意义就是防这个。
 *
 * ============================================================================
 * 所有 Predis 异常都包成 IdempotencyStoreUnavailable
 * ============================================================================
 * 调用方据此走 fail-open（理由见 {@see IdempotencyStoreUnavailable} 的类注释）。
 * 不包的话，一次 Redis 连接超时会以裸 `Predis\...\Exception` 冒到
 * ApiProblemExceptionListener，变成一个 500 —— 那正是 fail-open 想避免的。
 */
final readonly class RedisIdempotencyStore implements IdempotencyStoreInterface
{
    /** 键前缀带版本号：将来改记录格式时可以换 `v2` 并让旧键自然过期。 */
    public const KEY_PREFIX = 'ncards:idem:v1:';

    public function __construct(private RedisConnectionFactory $connections)
    {
    }

    public function claim(string $key, string $fingerprint, int $lockTtlSeconds): ?IdempotencyRecord
    {
        $payload = self::encode(['fp' => $fingerprint, 'done' => false]);

        try {
            $client = $this->connections->create();

            // NX = 只在键不存在时设置；EX = 过期秒数。原子。
            $acquired = $client->set(self::KEY_PREFIX.$key, $payload, 'EX', $lockTtlSeconds, 'NX');

            if (null !== $acquired && 'OK' === (string) $acquired) {
                return null;
            }

            // 没抢到 —— 把已有记录读回来，让调用方决定回放还是报错。
            $existing = $client->get(self::KEY_PREFIX.$key);

            if (!\is_string($existing)) {
                // 抢占失败但又读不到：说明在这两条命令之间键刚好过期了。
                // 当作「没有记录」处理，调用方会照常处理请求 —— 比抛异常温和，
                // 且最坏后果只是一次本可去重的重复请求。
                return null;
            }

            return self::decode($existing);
        } catch (IdempotencyStoreUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new IdempotencyStoreUnavailable('Failed to claim an idempotency key.', 0, $e);
        }
    }

    public function complete(string $key, string $fingerprint, int $status, array $headers, string $body, int $ttlSeconds): void
    {
        $payload = self::encode([
            // ⚠️ 指纹用调用方传进来的，**不**回读 Redis 重建 —— 理由见接口注释：
            // 在途锁只有 60 秒，慢请求走到这里时键可能已经没了，回读会得到空指纹。
            'fp' => $fingerprint,
            'done' => true,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
        ]);

        try {
            // 无 NX：这里是**覆盖**在途记录。键通常已经存在（是我们自己占的），
            // 但请求耗时超过 60 秒时在途锁已经过期 —— 那种情况下这条命令等于重新建键，
            // 已完成记录照样落库，回放窗口不受影响。
            // TTL 在此刻重置为 24h —— §6.1 要的就是这个。
            $this->connections->create()->set(self::KEY_PREFIX.$key, $payload, 'EX', $ttlSeconds);
        } catch (\Throwable $e) {
            throw new IdempotencyStoreUnavailable('Failed to store an idempotent response.', 0, $e);
        }
    }

    public function release(string $key): void
    {
        try {
            $this->connections->create()->del([self::KEY_PREFIX.$key]);
        } catch (\Throwable $e) {
            throw new IdempotencyStoreUnavailable('Failed to release an idempotency key.', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR);
    }

    private static function decode(string $raw): IdempotencyRecord
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new IdempotencyStoreUnavailable('Stored idempotency record is corrupt.', 0, $e);
        }

        $fingerprint = \is_string($data['fp'] ?? null) ? $data['fp'] : '';

        if (true !== ($data['done'] ?? false)) {
            return IdempotencyRecord::inProgress($fingerprint);
        }

        /** @var array<string, string> $headers */
        $headers = \is_array($data['headers'] ?? null) ? $data['headers'] : [];

        return IdempotencyRecord::completed(
            $fingerprint,
            \is_int($data['status'] ?? null) ? $data['status'] : 200,
            $headers,
            \is_string($data['body'] ?? null) ? $data['body'] : '',
        );
    }
}
