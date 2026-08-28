<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Redis;

use App\Shared\Application\Idempotency\IdempotencyStoreUnavailable;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use App\Shared\Infrastructure\Redis\RedisIdempotencyStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 真实 Redis 上的幂等存储语义。
 *
 * 单测用的 InMemoryIdempotencyStore 验的是中间件的**状态机**；这里验的是
 * 那套状态机赖以成立的**存储保证** —— 尤其是 `SET NX EX` 的原子性。
 * 拆成 `EXISTS` + `SET` 的实现能通过全部单测，却会在生产的并发重试下
 * 让同一笔创建执行两次。
 *
 * 沿用 T-003 的 DatabaseHealthCheckTest 立下的规矩：连不上就 skip，
 * 裸机 `composer test` 保持全绿。
 */
#[CoversClass(RedisIdempotencyStore::class)]
#[CoversClass(RedisConnectionFactory::class)]
final class RedisIdempotencyStoreTest extends TestCase
{
    private RedisConnectionFactory $factory;
    private RedisIdempotencyStore $store;
    private string $key;

    protected function setUp(): void
    {
        $dsn = $_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL'] ?? null;

        if (!\is_string($dsn)) {
            self::markTestSkipped('REDIS_URL 未配置。');
        }

        $this->factory = new RedisConnectionFactory($dsn);

        try {
            $this->factory->create()->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 不可达（'.$e->getMessage().'）。起 compose 栈后再跑：docker compose -f infra/compose/docker-compose.base.yml up -d redis');
        }

        $this->store = new RedisIdempotencyStore($this->factory);
        // 每个用例一把新键，免得互相干扰，也免得残留污染开发机上的 Redis。
        $this->key = 'test:'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (isset($this->store)) {
            try {
                $this->store->release($this->key);
            } catch (\Throwable) {
                // 清理失败无所谓，键本来就有 TTL。
            }
        }
    }

    public function testFirstClaimSucceeds(): void
    {
        self::assertNull($this->store->claim($this->key, 'fp', 60), 'null = 抢到了');
    }

    /**
     * ⚠️ 这条测的是整套幂等的地基：并发的两个请求只能有一个抢到。
     */
    public function testSecondClaimSeesTheInProgressRecord(): void
    {
        $this->store->claim($this->key, 'fp', 60);

        $record = $this->store->claim($this->key, 'fp', 60);

        self::assertNotNull($record, '第二次 claim 必须拿到已有记录，而不是也抢到');
        self::assertSame('fp', $record->fingerprint);
        self::assertFalse($record->completed);
    }

    /**
     * 用**另一条独立连接**再抢一次 —— 更接近真实的两个进程并发。
     */
    public function testClaimIsAtomicAcrossConnections(): void
    {
        $this->store->claim($this->key, 'fp', 60);

        $dsn = (string) ($_ENV['REDIS_URL'] ?? $_SERVER['REDIS_URL']);
        $other = new RedisIdempotencyStore(new RedisConnectionFactory($dsn));

        self::assertNotNull($other->claim($this->key, 'fp', 60));
    }

    public function testCompleteMakesTheRecordReplayable(): void
    {
        $this->store->claim($this->key, 'fp', 60);
        $this->store->complete($this->key, 'fp', 201, ['content-type' => 'application/json'], '{"id":1}', 86400);

        $record = $this->store->claim($this->key, 'fp', 60);

        self::assertNotNull($record);
        self::assertTrue($record->completed);
        self::assertSame(201, $record->status);
        self::assertSame('{"id":1}', $record->body);
        self::assertSame(['content-type' => 'application/json'], $record->headers);
    }

    /**
     * ⚠️ complete 必须把在途记录里的指纹**原样保留**。
     *
     * 丢了指纹，「同键不同 body」的检测就废了 —— 第二次带着不同 body 的请求
     * 会被当成合法回放，直接拿回第一次的响应。
     */
    public function testCompletePreservesTheFingerprint(): void
    {
        $this->store->claim($this->key, 'original-fingerprint', 60);
        $this->store->complete($this->key, 'original-fingerprint', 200, [], '{}', 86400);

        $record = $this->store->claim($this->key, 'original-fingerprint', 60);

        self::assertNotNull($record);
        self::assertSame('original-fingerprint', $record->fingerprint);
    }

    /**
     * ⚠️ 在途锁**已经过期**时，complete 仍必须写出带正确指纹的已完成记录。
     *
     * 这是上一条测试盖不住的那半边：早先的实现在 complete 里回读 Redis 重建指纹，
     * 键还在时一切正常，因此所有测试都绿。但在途锁只有 60 秒 —— 一个耗时超过 60 秒的
     * 请求（慢 PG/Vault 调用、上游卡顿）走到这里时键已经没了，回读得到空指纹，
     * 已完成记录带着 `fp: ''` 落库。之后**同键同 body** 的正常重试会在中间件里
     * 被判成 `422 idempotency_key_reused`；而按 §5.4.3，Android 的 outbox 不重试
     * 409/429 之外的 4xx —— 客户端于是永久放弃一笔服务端其实已经成功的操作。
     *
     * 这里用 del 直接模拟锁过期。
     */
    public function testCompleteKeepsTheFingerprintAfterTheLockExpired(): void
    {
        $this->store->claim($this->key, 'original-fingerprint', 60);

        // 模拟「请求耗时超过 60 秒，在途锁自然过期」。
        $this->factory->create()->del([RedisIdempotencyStore::KEY_PREFIX.$this->key]);

        $this->store->complete($this->key, 'original-fingerprint', 201, [], '{"id":1}', 86400);

        $record = $this->store->claim($this->key, 'original-fingerprint', 60);

        self::assertNotNull($record, '锁过期后 complete 仍应留下一条可回放的记录');
        self::assertTrue($record->completed);
        self::assertSame(
            'original-fingerprint',
            $record->fingerprint,
            '指纹必须来自调用方传入的值，不能靠回读已经过期的键重建',
        );
    }

    public function testReleaseFreesTheKeyForRetry(): void
    {
        $this->store->claim($this->key, 'fp', 60);
        $this->store->release($this->key);

        self::assertNull($this->store->claim($this->key, 'fp', 60), '释放后必须能重新抢到');
    }

    /**
     * 在途锁是 60 秒而不是 24 小时 —— 进程半路死掉时锁要能自己过期。
     * 这里只验 TTL 确实被设上了且在合理范围内（不 sleep 60 秒）。
     */
    public function testClaimSetsTheShortLockTtl(): void
    {
        $this->store->claim($this->key, 'fp', 60);

        $ttl = $this->factory->create()->ttl(RedisIdempotencyStore::KEY_PREFIX.$this->key);

        self::assertGreaterThan(0, $ttl, '键必须有 TTL —— 没有的话崩溃会把它永久锁死');
        self::assertLessThanOrEqual(60, $ttl);
    }

    public function testCompleteExtendsTheTtlToTheRecordLifetime(): void
    {
        $this->store->claim($this->key, 'fp', 60);
        $this->store->complete($this->key, 'fp', 200, [], '{}', 86400);

        $ttl = $this->factory->create()->ttl(RedisIdempotencyStore::KEY_PREFIX.$this->key);

        self::assertGreaterThan(60, $ttl, '已完成记录应当续到 §6.1 的 24h');
        self::assertLessThanOrEqual(86400, $ttl);
    }

    /**
     * 连不上时必须抛我们自己的异常类型 —— 中间件靠它识别并 fail-open。
     * 裸的 Predis 异常会一路冒到异常监听器，变成一个 500。
     */
    public function testUnreachableRedisRaisesStoreUnavailable(): void
    {
        $broken = new RedisIdempotencyStore(new RedisConnectionFactory('redis://127.0.0.1:1'));

        $this->expectException(IdempotencyStoreUnavailable::class);

        $broken->claim('whatever', 'fp', 60);
    }
}
