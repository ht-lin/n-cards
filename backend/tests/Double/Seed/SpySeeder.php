<?php

declare(strict_types=1);

namespace App\Tests\Double\Seed;

use App\Shared\Application\Seed\SeederInterface;

/**
 * 记录被调次数的 seeder 替身。`calls` 用来断言 prod 下一次都没被调到。
 */
final class SpySeeder implements SeederInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $name,
        private readonly int $rows,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function seed(): int
    {
        ++$this->calls;

        return $this->rows;
    }
}
