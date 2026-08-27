<?php

declare(strict_types=1);

namespace App\Shared\Domain\Random;

/**
 * 密码学安全的随机源。
 *
 * 直接调 `random_bytes()` 在 Domain 层是合法的（它是函数，不是框架类型），
 * 但那样 {@see \App\Shared\Domain\Identity\Uuid7Generator} 就没法写确定性测试 ——
 * 「给定这一毫秒和这串随机字节，应该产出这个确切的 UUID」是本任务里
 * 唯一能真正验证 RFC 9562 位布局的断言方式。
 *
 * 实现在 `Shared\Infrastructure\Random\SecureRandomness`；测试替身是
 * `tests/Double/Random/SequenceRandomness`。
 *
 * ⚠️ 实现**必须**是密码学安全的（`random_bytes` / `random_int`）。
 * 这个接口将来也会喂 T-005 的加密与 T-1xx 的 OTP 码生成 —— `mt_rand` 一类
 * 在那里是安全事故。
 */
interface RandomnessInterface
{
    /**
     * @param int<1, max> $length 字节数
     */
    public function bytes(int $length): string;

    /**
     * 闭区间 [$min, $max] 内的均匀随机整数。
     */
    public function int(int $min, int $max): int;
}
