<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\HealthCheckInterface;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;

/**
 * Redis 就绪检查。
 *
 * **零配置**：`config/services.yaml` 的 `_instanceof` 会自动给它打上
 * `app.health_check` 标签，`ReadinessProbe` 的 `#[AutowireIterator]` 自动收集 ——
 * T-003 设计的那个「实现接口即注册」扩展点在这里兑现，一行 yaml 都不用加。
 * （`HealthCheckInterface` 的类注释里那句「待接入：Redis（T-006 装限流时）」
 * 提前到了 T-004。）
 *
 * ⚠️ 这个检查是幂等 fail-open 策略的**配套**，不是可选项。
 * Redis 挂掉时 IdempotencyMiddleware 会静默放行（见 IdempotencyStoreUnavailable
 * 的类注释），使得故障对客户端不可见 —— 那么故障就必须对**运维**可见。
 * 这里把 `/health/ready` 翻成 503，Caddy / Ansible / §14.3 的部署健康检查都能看到。
 * 没有这个类，fail-open 就变成了「静默降级」，那是另一回事。
 */
final readonly class RedisHealthCheck implements HealthCheckInterface
{
    public function __construct(private RedisConnectionFactory $connections)
    {
    }

    public function name(): string
    {
        return 'redis';
    }

    public function check(): void
    {
        $response = $this->connections->create()->ping();

        // Predis 的 ping() 在不同版本里返回 Status 对象或字符串，统一转字符串比。
        if ('PONG' !== strtoupper((string) $response)) {
            throw new \RuntimeException('Redis did not answer PING with PONG.');
        }
    }
}
