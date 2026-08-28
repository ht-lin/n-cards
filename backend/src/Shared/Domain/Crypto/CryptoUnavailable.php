<?php

declare(strict_types=1);

namespace App\Shared\Domain\Crypto;

use App\Shared\Domain\Error\ErrorCode;

/**
 * Vault 不可达、被封印，或认证失败 —— 加解密**能力本身**暂时没了。
 *
 * ============================================================================
 * 这个异常存在的意义：让「fail-closed」成为一个显式的决定
 * ============================================================================
 * 与 T-004 的 {@see \App\Shared\Application\Idempotency\IdempotencyStoreUnavailable}
 * 是一对**刻意相反**的选择，两边的类注释请对照着读。
 *
 * 幂等存储挂了可以 fail-open（当作没带幂等键继续处理），因为 §5.4.3 还提供了
 * 一层与存储无关的幂等保证，Redis 只是第二道保险。加密门面**没有**这种退路：
 *
 *   1. 解不了密就没有可返回的正确数据。fail-open 在这里的字面含义是
 *      「把 `vault:v1:…` 当明文返回给客户端」，那不是降级，是返回垃圾。
 *   2. 加密侧更糟：fail-open 等于把明文卡号写进 `barcode_value_encrypted`。
 *      §5.3 的整套信封加密会在那一刻失效，而且**没有告警会响** ——
 *      写入成功了，只是写错了东西。等到发现时表里已经是明文与密文混着的了。
 *
 * 所以这里没有可选项：Vault 出问题，请求就得失败。
 *
 * ============================================================================
 * 为什么是 503 而不是 500
 * ============================================================================
 * 这是**可重试**的，且通常不是 bug —— 生产每次重启后 Vault 都是封印状态，
 * 必须人工 unseal（Q6 / ADR-0004），此期间的失败是**期望行为**。
 *
 * 用 500 会有两个具体后果：客户端（T-010 的 `ApiError`）不会重试；
 * §14.4 的「API 5xx 率高 > 1% 持续 5min」告警会在每次计划内的 unseal 窗口里
 * 误报，而误报几次之后就没人看它了。
 * 503 走 `ErrorCode::logLevel()` 的 warning 分支，正是「看得见但不是故障」。
 *
 * 运维侧的可见性由 {@see \App\Shared\Infrastructure\Health\VaultHealthCheck}
 * 提供：封印时 `/health/ready` 返回 503，Caddy 与 §14.3 的部署健康检查都看得到。
 */
final class CryptoUnavailable extends CryptoFailed
{
    public function __construct(
        string $detail = 'The cryptography service is temporarily unavailable.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail, $previous, ErrorCode::ServiceUnavailable);
    }
}
