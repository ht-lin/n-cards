<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identity;

/**
 * UUID 生成器。
 *
 * 业务代码依赖这个接口而不是 {@see Uuid7Generator} 具体类，这样测试里换一个
 * 产出可预测序列的替身就不用碰被测代码。容器里的实现见 config/services.yaml 的显式 alias。
 */
interface UuidGeneratorInterface
{
    public function generate(): Uuid;
}
