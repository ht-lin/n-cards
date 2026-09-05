<?php

declare(strict_types=1);

namespace App\Shared\Application\Metrics;

/**
 * §14.4 指标表的**接缝**。T-102 只交付这一层与一个 Redis 实现。
 *
 * ============================================================================
 * 边界：这里没有 /metrics 端点
 * ============================================================================
 * §14.4 的整套可观测性（Prometheus 抓取、Grafana 看板、Alertmanager 规则）
 * 归 **T-405**，`infra/compose` 里那几个服务还注释着。
 *
 * T-102 之所以现在就要这个接口，是因为任务卡把
 * `email_send_total{provider,template,result}` 列为交付物，而一个**带标签的
 * 计数器**没法用日志代替：`logger->info('mail sent', [...])` 之后，
 * 「过去 10 分钟失败率是否 > 5%」（§14.4 的 P1 告警条件）要靠 LogQL 现算，
 * 而那条告警恰恰是 R1 的唯一探测手段。
 *
 * 反过来，现在就把 Prometheus 客户端库拉进来是 T-405 的范围
 * （CONTRIBUTING §9）。所以这里只定义「怎么记一个数」，
 * 「怎么被抓走」留给 T-405 —— 它要做的是加一个读同一批 Redis 键的导出端点，
 * 不需要改任何调用点。
 *
 * ============================================================================
 * 为什么在 Shared\Application 而不是 Shared\Infrastructure
 * ============================================================================
 * 与 `Shared\Application\Crypto\CryptoServiceInterface` 同一套论证（见那里的
 * 类注释）：deptrac 里每个模块的 Application 层允许 `Shared.Application`，
 * 但不允许 `Shared.Infrastructure`。照 §12.2 的字面把接口放进 Infrastructure，
 * 结果会是任何模块的 Handler 都记不了指标。
 */
interface MetricsInterface
{
    /**
     * 给一个计数器加数。
     *
     * ⚠️ **标签基数必须是有界的**。传 user_id、email、card_id 这类值会让
     * Prometheus 的时间序列数量随用户数增长（cardinality explosion），
     * 那是 T-405 接上抓取之后才会显形、且届时很难回收的问题。
     *
     * 本仓库目前用到的标签值域都是闭合的：`provider` 来自一个环境变量，
     * `template` 与 `result` 来自 Notification 模块的两个 enum
     * （`MailTemplate` / `MailResult`——**不在这里 import**，
     * Shared 不得依赖任何模块，§4.2 规则 4）。
     *
     * 记指标**绝不能**让业务失败：实现要吞掉自己的存储异常并转成一行日志。
     * 「Redis 抖了一下导致一封 OTP 信没发出去」是不可接受的因果链。
     *
     * @param non-empty-string                $name   指标名，§14.4 表格里的那一列
     * @param array<non-empty-string, string> $labels 标签，值域必须闭合
     * @param positive-int                    $by     增量
     */
    public function counter(string $name, array $labels = [], int $by = 1): void;
}
