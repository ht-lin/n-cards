<?php

declare(strict_types=1);

namespace App\Shared\Application\Idempotency;

/**
 * 幂等存储（Redis）不可达。
 *
 * ============================================================================
 * 这个异常存在的意义：让「fail-open」成为一个**显式**的决定
 * ============================================================================
 * `IdempotencyMiddleware` 捕获它之后记一条 error 日志，然后**当作没带幂等键继续处理**。
 * 理由（同样写在 ADR-0003 里）：
 *
 * 1. fail-closed 会把一次 Redis 抖动放大成 **100% 写入不可用**，含登录。
 *    §9.2 的 99.5% 可用性在 30 天里只有 3.6 小时预算，而 §14.2 的 Redis 是
 *    单容器、无 HA。
 * 2. 正确性代价有界：§5.4.3 已经给创建类端点提供了更强、且与存储无关的幂等保证
 *    （客户端生成 id，重复 → `200` + 现有实体）。Redis 只是第二道保险。
 * 3. 故障不是静默的：`RedisHealthCheck` 会把 `/health/ready` 翻成 503，
 *    Caddy / Ansible / §14.3 的部署健康检查都看得到。
 *
 * ⚠️ **T-006 必须做相反的选择。** 限流 fail-open 等于在故障期间关掉 §7.5 的
 * OTP 与 username 枚举防线 —— 那是安全控制，不是便利功能。
 * 这个不对称是刻意的，不是疏漏。
 */
final class IdempotencyStoreUnavailable extends \RuntimeException
{
}
