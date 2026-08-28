<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Random;

use App\Shared\Domain\Random\RandomnessInterface;

/**
 * 密码学安全的随机源（CSPRNG）。
 *
 * ⚠️ **只能是 `random_bytes` / `random_int`。**
 * 这个实现今天喂的是 UUIDv7 的随机位，将来还要喂 T-1xx 的 OTP 码与
 * T-005 的加密相关取值。`mt_rand` / `rand` 在那些场景里是安全事故 ——
 * 它们的状态可以从少量输出反推，OTP 码就此可预测。
 *
 * 两个函数在熵源不可用时抛 `\Random\RandomException`，**刻意不捕获**：
 * 没有可信随机数时继续跑比直接失败危险得多。
 */
final readonly class SecureRandomness implements RandomnessInterface
{
    public function bytes(int $length): string
    {
        return random_bytes($length);
    }

    public function int(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}
