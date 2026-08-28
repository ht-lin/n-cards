<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Redis;

use Predis\Client;
use Predis\ClientInterface;
use Predis\Connection\Parameters;
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
    /**
     * 建连超时（秒）。Predis 不设时默认 5.0。
     *
     * Redis 是同一个 docker 网络里的容器（§14.2：单容器、无 HA），握手是亚毫秒级的。
     */
    public const CONNECT_TIMEOUT_SECONDS = 1.0;

    /**
     * 读写超时（秒）。
     *
     * ⚠️⚠️ 这一项**不设就等于 60 秒**，而且是无声的：Predis 只在参数存在时才调
     * `stream_set_timeout()`（见 Connection/Resource/StreamFactory::createStreamSocket），
     * 否则流沿用 php.ini 的 `default_socket_timeout`（默认 60）。
     *
     * 那个默认值会直接推翻幂等 fail-open 的前提。fail-open 的整个论证
     * （见 IdempotencyStoreUnavailable 的类注释）建立在「Redis 故障是廉价的」之上 ——
     * 连不上时 TCP 立刻拒绝，几毫秒就落到 fail-open 分支。但**接受连接却不回包**
     * 的 Redis（BGSAVE 期间的 fork 停顿、网络被黑洞、容器 OOM 后半死不活）不是拒绝，
     * 是挂起：每个带 `Idempotency-Key` 的 POST 会卡满 60 秒才 fail-open，
     * `/health/ready` 同样卡 60 秒 —— 于是「Redis 故障对运维可见」这条也一起失效，
     * 探针不是返回 503，而是超时。
     *
     * 1 秒对我们的负载是三个数量级的余量：§9.3 峰值约 15 req/s，每个写请求 2–3 条
     * O(1) 命令。它同时也是「一个卡死的 Redis 最多能拖慢一个请求多久」的上限。
     */
    public const READ_WRITE_TIMEOUT_SECONDS = 1.0;

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
        // ⚠️ 超时是**连接参数**（第一个入参），不是**客户端选项**（第二个入参）。
        // 写进 options 里不会报错，只是被静默忽略 —— 于是 60 秒的默认值原封不动地留着。
        // 所以先把 DSN 解析成数组再合并，而不是往 URL 后面拼 query string
        // （那样遇到本身带 query 的 DSN 就会拼坏）。
        //
        // 合并方向是 DSN 覆盖默认值：运维要临时放宽超时，改 REDIS_URL 就行
        // （`redis://redis:6379?read_write_timeout=5`），不用改代码重新发布。
        $parameters = array_merge([
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'read_write_timeout' => self::READ_WRITE_TIMEOUT_SECONDS,
        ], Parameters::parse($this->dsn));

        return $this->client ??= new Client($parameters, [
            // 抛异常而不是返回错误对象 —— 静默的错误响应会被误当成「键不存在」，
            // 于是 Redis 挂掉时幂等中间件会以为每个键都是新的，而不是走 fail-open 分支。
            'exceptions' => true,
        ]);
    }
}
