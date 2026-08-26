<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * 聚合全部 {@see HealthCheckInterface}，回答「这个实例现在能不能接流量」。
 *
 * 刻意**不**短路：一次探活把所有检查都跑一遍，这样一次 503 的日志里能看到全部
 * 失败组件，而不是只看到第一个。就绪探活的调用频率很低（compose / Caddy / Ansible），
 * 多跑几条 `SELECT 1` 的代价可以忽略。
 *
 * 返回值刻意只有一个 bool：失败组件名与异常消息只进日志，绝不进 HTTP 响应体
 * （§6.2：健康端点不暴露内部细节）。
 */
final readonly class ReadinessProbe
{
    /**
     * @param iterable<HealthCheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('app.health_check')]
        private iterable $checks,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function isReady(): bool
    {
        $ready = true;

        foreach ($this->checks as $check) {
            try {
                $check->check();
            } catch (\Throwable $e) {
                $ready = false;
                $this->logger?->error('Readiness check failed', [
                    'component' => $check->name(),
                    'exception' => $e,
                ]);
            }
        }

        return $ready;
    }
}
