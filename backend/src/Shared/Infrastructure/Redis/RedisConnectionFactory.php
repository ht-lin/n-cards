<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use Predis\Client;
use Predis\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * 全仓库**唯一**的 Redis 连接构造点。
 *
 * T-004 的幂等键与 T-006 的限流都从这里拿连接 —— 一处配置、一处超时策略、
 * 一处将来换客户端时要改的地方。
 *
 * ============================================================================
 * 为什么是 predis 而不是 ext-redis
 * ============================================================================
 * 1. **裸机 `composer install` / `composer test` 零配置保持全绿**，这是本仓库
 *    反复申明的不变量（见 .env 的注释、DatabaseHealthCheckTest 的 skip 模式）。
 *    换成 ext-redis 就得在 composer.json 的 `require` 里写 `ext-redis`，
 *    每台没装该扩展的开发机 `composer install` 直接失败。
 * 2. 于是 `backend/Dockerfile` 与 CI 的 `setup-php` extensions **都不用改**。
 * 3. phpstan level 8 拿到一个具体类型。`RedisAdapter::createConnection()` 的返回类型是
 *    `\Redis|\RedisArray|\RedisCluster|\Predis\ClientInterface|Relay|RelayCluster`
 *    六路联合，在每个调用点收窄它是实打实的摩擦。
 * 4. 性能无关：§9.3 的峰值约 15 req/s，每个写请求 2–3 条 Redis 命令。
 *
 * 将来真需要 ext-redis 的性能，改这一个类的实现即可，调用方一行不动。
 *
 * ⚠️ 刻意**不**用 symfony/cache 的 RedisAdapter：PSR-6 缓存池表达不了原子的
 * `SET key value NX EX`，而幂等的 claim 恰恰只能是原子的。我们要的是裸连接。
 */
final class RedisConnectionFactory
{
    private ?ClientInterface $client = null;

    public function __construct(
        #[Autowire('%env(REDIS_URL)%')]
        private readonly string $dsn,
    ) {
    }

    /**
     * 惰性建连并复用。
     *
     * 惰性很重要：容器里几乎所有请求都不碰 Redis（GET 类端点不带幂等键），
     * 在构造期就连会给每个请求加一次无谓的握手。
     */
    public function create(): ClientInterface
    {
        return $this->client ??= new Client($this->dsn, [
            // 抛异常而不是返回错误对象 —— 静默的错误响应会被误当成「键不存在」，
            // 于是 Redis 挂掉时幂等中间件会以为每个键都是新的，而不是走 fail-open 分支。
            'exceptions' => true,
        ]);
    }
}
