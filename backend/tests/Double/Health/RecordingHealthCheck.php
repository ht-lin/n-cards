<?php

declare(strict_types=1);

namespace App\Tests\Double\Health;

use App\Shared\Application\Health\HealthCheckInterface;

/**
 * 可控的就绪检查替身：记录被调次数，可配置成必失败。
 *
 * 用真替身而不是 PHPUnit mock，是因为多处用例要断言「调用次数」与「异常类型」，
 * mock 的期望写法会把这两条断言藏进 verify() 里，读起来不如显式断言清楚。
 */
final class RecordingHealthCheck implements HealthCheckInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $name,
        private readonly ?string $failWith = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(): void
    {
        ++$this->calls;

        if (null !== $this->failWith) {
            throw new \RuntimeException($this->failWith);
        }
    }
}
