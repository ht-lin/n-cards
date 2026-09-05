<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Redis;

use App\Module\Notification\Application\Port\MailVolumeCounterInterface;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;

/**
 * {@see MailVolumeCounterInterface} 的 Redis 实现（§3.1 的全局熔断计数）。
 *
 * ============================================================================
 * 为什么放 Redis 而不是 Postgres
 * ============================================================================
 * 这是唯一一处「计数」类数据放 Redis 而不违反 §8.2 保留期的场景：
 * ROPA 给 Redis 的定位是「限流计数，保留 24 小时」，而这个计数器
 * **不含任何个人数据** —— 键里只有日期，值是一个整数。
 *
 * 对比 `config/packages/rate_limiter.yaml` 底部那段注释解释的两条「总计」：
 * 它们因为需要跨越账号生命周期而必须落持久层。这一条不需要，它按天归零。
 *
 * ============================================================================
 * TTL 取 48 小时而不是 24
 * ============================================================================
 * 键本身按 UTC 日历日切换，昨天那个键在今天已经没人读了。
 * 多留一天是为了事后取证：R1 真触发时（域名被拉黑），第一个问题一定是
 * 「昨天到底发了多少封」，而那时候去看已经晚了。
 * 两天的整数键在 §8.2 的口径下毫无负担（不是个人数据），也远在
 * ROPA 给 Redis 的 24 小时之内的**个人数据**约束之外。
 *
 * ============================================================================
 * 按 UTC 日切
 * ============================================================================
 * 不用欧洲/柏林是因为夏令时切换那两天会出现 23 或 25 小时的窗口，
 * 而阈值是按「一天大概多少封」定的 —— 25 小时的窗口会让告警在每年
 * 某一天无理由地更容易触发，而那种一年一次的假阳性最难被诊断出来。
 */
final readonly class RedisMailVolumeCounter implements MailVolumeCounterInterface
{
    /** 键前缀带版本号，与 `RedisIdempotencyStore::KEY_PREFIX` 同一套做法。 */
    public const KEY_PREFIX = 'ncards:mailvol:v1:';

    /** 48 小时。理由见类注释。 */
    public const TTL_SECONDS = 172800;

    public function __construct(
        private RedisConnectionFactory $connections,
        private ClockInterface $clock,
    ) {
    }

    public function incrementAndGet(): int
    {
        $key = self::KEY_PREFIX.$this->clock->now()
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d');

        try {
            $client = $this->connections->create();

            // INCR 的返回值就是加过之后的值 —— 一条命令，天然原子。
            // 拆成 GET + SET 的话，app 与两个 worker 副本并发时会各自读到
            // 同一个旧值，于是阈值判定在最需要它的时候（发信量突增）最不准。
            $volume = (int) $client->incr($key);

            // 只在**刚创建**这个键时设 TTL。每次都设的话，一个持续有流量的
            // 系统会把当天的键一直续到 48 小时之后，键就永远不过期了。
            if (1 === $volume) {
                $client->expire($key, self::TTL_SECONDS);
            }

            return $volume;
        } catch (\Throwable $e) {
            // 包成 RuntimeException 让调用方能按接口契约 catch。
            // 裸 Predis 异常冒上去的话，MailCircuitBreaker 的 fail-open 分支
            // 就接不住，一次 Redis 抖动会变成一封发不出去的 OTP 信 ——
            // 而那正是 fail-open 存在的理由。
            throw new \RuntimeException('邮件发信量计数器不可达。', 0, $e);
        }
    }
}
