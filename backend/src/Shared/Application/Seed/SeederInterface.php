<?php

declare(strict_types=1);

namespace App\Shared\Application\Seed;

/**
 * 一个模块的种子数据写入器（§14.1：local 环境用 `bin/console app:seed` 造数据）。
 *
 * 实现类会被 config/services.yaml 的 `_instanceof` 自动打上 `app.seeder` 标签，
 * 由 SeedCommand 收集。各模块在 M1 起各自提供实现，命令本身不再改动。
 *
 * ⚠️ 种子数据**只用于 local**。§14.1 与 infra/README.md 的硬性约束：staging 只用
 * 合成数据，production 绝不跑 seeder。实现类自己不必判环境 —— SeedCommand 统一拦。
 */
interface SeederInterface
{
    /**
     * 供输出使用的名字，惯例用模块名（`identity` / `wallet` / …）。
     */
    public function name(): string;

    /**
     * 写入种子数据，返回写入的行数。
     *
     * 必须是**幂等**的：重复跑 `app:seed` 不应产生重复数据，也不应报错。
     */
    public function seed(): int;
}
