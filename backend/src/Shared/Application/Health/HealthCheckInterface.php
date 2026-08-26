<?php

declare(strict_types=1);

namespace App\Shared\Application\Health;

/**
 * 一项就绪检查（`/health/ready` 的一个组成部分）。
 *
 * 实现放在 Shared\Infrastructure —— 探活要碰真实的连接（Doctrine / Redis / Vault），
 * 而按 §12.2 与 deptrac，只有 Infrastructure 层允许 import 这些框架类型。
 *
 * 实现类会被 config/services.yaml 的 `_instanceof` 自动打上 `app.health_check` 标签，
 * 由 {@see ReadinessProbe} 收集。新增一项检查 = 新增一个实现类，不需要改控制器。
 *
 * 待接入：
 *   - Redis（T-006 装限流时）
 *   - Vault（T-005 装 Transit 门面时）
 */
interface HealthCheckInterface
{
    /**
     * 组件名，只用于日志。
     *
     * **绝不会**出现在 HTTP 响应体里 —— §6.2 要求健康端点不暴露内部细节。
     */
    public function name(): string;

    /**
     * 检查通过则正常返回；失败则抛任意异常（消息会被记进日志，不外泄）。
     */
    public function check(): void;
}
