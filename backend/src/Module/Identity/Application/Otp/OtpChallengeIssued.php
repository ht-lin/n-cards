<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Shared\Domain\Identity\Uuid;

/**
 * `POST /v1/auth/otp/request` 的结果（契约里的 `OtpChallenge`）。
 *
 * ⚠️ **不要在这里加任何与「这个邮箱注册过吗」相关的字段** —— 那会把整套防枚举
 * 一次性作废（§3.8）。ADR-0014 之后服务端在这条路径上压根不知道答案
 * （`RequestOtpService` 不再注入 `UserRepositoryInterface`），所以今天想加也无从加起；
 * 这条注释是留给将来某个「顺手查一下用户存不存在」的改动的。
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
