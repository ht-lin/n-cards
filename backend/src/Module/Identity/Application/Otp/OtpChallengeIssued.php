<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Shared\Domain\Identity\Uuid;

/**
 * `POST /v1/auth/otp/request` 的结果（契约里的 `OtpChallenge`）。
 *
 * ⚠️ **这个形状对「已注册」与「未注册」两条路径完全相同**，这正是 §3.8 的要求。
 * 不要在这里加任何字段来告诉客户端「这是不是一条哑挑战」—— 那会把整套
 * 防枚举一次性作废。`is_decoy` 只存在于库里，且只有 T-104 的验证逻辑读它。
 *
 * 返回本 DTO 而不是 {@see \App\Module\Identity\Domain\Entity\OtpChallenge}：
 * deptrac 里 `Identity.Http` **看不到** `Identity.Domain`，控制器没法从实体上取值。
 */
final readonly class OtpChallengeIssued
{
    public function __construct(
        public Uuid $challengeId,
        public \DateTimeImmutable $expiresAt,
        public int $resendAfterSeconds,
    ) {
    }
}
