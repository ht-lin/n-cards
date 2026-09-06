<?php

declare(strict_types=1);

namespace App\Tests\Double\Metrics;

use App\Shared\Application\Metrics\MetricsInterface;

/**
 * 记下每一次计数，不碰 Redis。
 *
 * ⚠️ T-102 的 SendMailHandlerTest 里有一个同名的**文件内**类。那个不动：
 * 它带着一个只对 `email_send_total` 三个标签有意义的 `last()` 帮手，
 * 提上来会变成一个谁都要读一遍才知道用不用得上的公共类。
 * 这里这个只做最朴素的记账。
 */
final class RecordingMetrics implements MetricsInterface
{
    /** @var list<array{name: string, labels: array<string, string>, by: int}> */
    private array $calls = [];

    public function counter(string $name, array $labels = [], int $by = 1): void
    {
        $this->calls[] = ['name' => $name, 'labels' => $labels, 'by' => $by];
    }

    /**
     * @return list<array{name: string, labels: array<string, string>, by: int}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * 某个指标被记的全部标签组，按调用顺序。
     *
     * @return list<array<string, string>>
     */
    public function labelsFor(string $name): array
    {
        $labels = [];

        foreach ($this->calls as $call) {
            if ($call['name'] === $name) {
                $labels[] = $call['labels'];
            }
        }

        return $labels;
    }
}
