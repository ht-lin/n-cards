<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use Psr\Log\LoggerInterface;

/**
 * {@see MetricsInterface} 的 Redis 实现（T-102 的接缝，抓取端点归 T-405）。
 *
 * ============================================================================
 * 为什么必须是进程外的共享存储
 * ============================================================================
 * Prometheus 的计数器模型假定「一个进程 = 一个序列」，而 PHP-FPM 里每个请求
 * 都是新进程，worker 在 prod 还有 2 个副本（infra/compose/docker-compose.prod.yml）。
 * 进程内的计数器活不过一个请求，等于没有。
 *
 * 用一个共享的 Redis 哈希是 PHP 生态里的标准做法（`promphp` 的 Redis adapter
 * 也是这么干的）。T-405 要做的是加一个读同一批键、按 Prometheus 文本格式
 * 吐出来的端点 —— 不需要改任何调用点。
 *
 * ============================================================================
 * ⚠️ 计数器**绝不能**让业务失败
 * ============================================================================
 * 所有异常在这里被吞掉并降级成一行 warning。
 * 「Redis 抖了一下导致一封 OTP 信没发出去」是不可接受的因果链 ——
 * 指标是用来观察系统的，不是系统的一部分。
 *
 * 这与 `RedisMailVolumeCounter` 抛异常的做法**刻意相反**：那个计数器的值
 * 会决定一封信发不发（熔断判定），所以它的失败必须被调用方看见并显式处理；
 * 这里的值不决定任何事。
 *
 * ============================================================================
 * 没有 TTL
 * ============================================================================
 * counter 在 Prometheus 里是单调递增的，过期会被读成一次 reset
 * （`rate()` 会把回落当成计数器重置而少算一段）。键很小、基数有界
 * （见 {@see MetricsInterface::counter()} 对标签基数的约束），留着就是了。
 */
final readonly class RedisCounterMetrics implements MetricsInterface
{
    /** 一个指标名一个哈希；field 是序列化后的标签集。 */
    public const KEY_PREFIX = 'ncards:metrics:v1:';

    public function __construct(
        private RedisConnectionFactory $connections,
        private LoggerInterface $logger,
    ) {
    }

    public function counter(string $name, array $labels = [], int $by = 1): void
    {
        try {
            $this->connections->create()->hincrby(
                self::KEY_PREFIX.$name,
                self::field($labels),
                $by,
            );
        } catch (\Throwable $e) {
            // 吞掉。理由见类注释 —— 指标不该有能力让业务失败。
            // level 取 warning 而不是 error：这一条不该进 §14.4 的告警链路，
            // 否则一次 Redis 抖动会同时触发「指标写不进去」与它本该观测的那个告警。
            $this->logger->warning('指标写入失败，已忽略。', [
                'metric' => $name,
                'exception' => $e,
            ]);
        }
    }

    /**
     * 标签集 → 哈希的 field 名。
     *
     * 先按键排序：`{a=1,b=2}` 与 `{b=2,a=1}` 在 Prometheus 里是**同一个序列**，
     * 不排序的话调用点参数顺序一变就会分裂出第二个序列，
     * 而那种分裂在图上看起来像是「指标突然掉到一半」。
     *
     * @param array<non-empty-string, string> $labels
     */
    private static function field(array $labels): string
    {
        ksort($labels);

        $parts = [];

        foreach ($labels as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode(',', $parts);
    }
}
