<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notification\Application;

use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\MailCircuitBreaker;
use App\Module\Notification\Application\Port\MailVolumeCounterInterface;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T-102：§3.1 的「阈值告警 + 自动熔断非关键邮件（**保留 OTP**）」。
 */
#[CoversClass(MailCircuitBreaker::class)]
final class MailCircuitBreakerTest extends TestCase
{
    private const WARN = 10;
    private const BREAKER = 20;

    /**
     * 三档阈值 × 全部四种模板 —— 全矩阵。
     *
     * 用 data provider 穷举 `MailTemplate::cases()` 而不是挑两个代表，
     * 是因为 `MailCircuitBreaker::criticalityOf()` 的 match 没有 default 分支：
     * 新增一个模板而忘了给它分类时，这里会抛 \UnhandledMatchError 而不是
     * 悄悄拿到某个默认值。那条保护只有穷举才能兑现。
     *
     * @return iterable<string, array{MailTemplate, int, bool}>
     */
    public static function volumeMatrix(): iterable
    {
        foreach (MailTemplate::cases() as $template) {
            $critical = MailTemplate::OtpCode === $template;

            yield $template->value.' 低于告警阈值 → 发' => [$template, 1, true];
            yield $template->value.' 越过告警阈值但未熔断 → 仍然发' => [$template, self::WARN, true];
            // 熔断之上：Critical 照发，Advisory 丢弃。这一行就是 §3.1 括号里那半句。
            yield $template->value.' 熔断之上 → 仅 Critical 通过' => [$template, self::BREAKER, $critical];
        }
    }

    #[DataProvider('volumeMatrix')]
    public function testCriticalMailSurvivesTheBreaker(MailTemplate $template, int $volume, bool $expected): void
    {
        $breaker = $this->breaker(new FixedVolumeCounter($volume));

        self::assertSame($expected, $breaker->allows($template));
    }

    /**
     * 越过告警阈值要记 **error**（不是 warning）——§14.4 的告警链路按 level 分流，
     * 而 R1「发信域名被拉黑」的影响是「致命」。
     */
    public function testCrossingTheWarnThresholdLogsAnError(): void
    {
        $logger = new RecordingLogger();
        $breaker = $this->breaker(new FixedVolumeCounter(self::WARN), $logger);

        $breaker->allows(MailTemplate::OtpCode);

        self::assertTrue(self::hasError($logger), '越过告警阈值必须留下一条 error 级日志。');
    }

    public function testStayingBelowTheWarnThresholdIsSilent(): void
    {
        $logger = new RecordingLogger();
        $breaker = $this->breaker(new FixedVolumeCounter(self::WARN - 1), $logger);

        $breaker->allows(MailTemplate::OtpCode);

        self::assertFalse(self::hasError($logger), '正常发信量不该产生告警噪声。');
    }

    /**
     * ⚠️ 计数器不可达时**放行** —— 与 §7.5 限流的 fail-closed 刻意相反。
     *
     * fail-closed 的话，Redis 一次重启就会让所有 Advisory 邮件（含新设备登录
     * 这类安全通知）静默消失。完整论证见 MailCircuitBreaker 的类注释。
     */
    public function testCounterOutageFailsOpen(): void
    {
        $logger = new RecordingLogger();
        $breaker = $this->breaker(new UnavailableVolumeCounter(), $logger);

        self::assertTrue($breaker->allows(MailTemplate::NewDeviceLogin), '计数器不可达时必须放行。');
        self::assertTrue(self::hasError($logger), '降级必须留下痕迹，否则「熔断失效了」没人知道。');
    }

    /**
     * 阈值配反了的话，告警永远不会先于熔断触发 —— 运维第一次知道出事
     * 会是从「用户说没收到提醒信」开始。构造期就炸。
     */
    public function testThresholdsMustBeOrdered(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MailCircuitBreaker(new FixedVolumeCounter(1), new RecordingLogger(), self::BREAKER, self::WARN);
    }

    /**
     * 断言的是 **level**，不是 message 的字面 —— 文案会改，
     * 「这条要不要进 Alertmanager」是由 level 决定的（§14.4 的链路按 level 分流）。
     */
    private static function hasError(RecordingLogger $logger): bool
    {
        foreach ($logger->records as $record) {
            if ('error' === $record['level']) {
                return true;
            }
        }

        return false;
    }

    private function breaker(MailVolumeCounterInterface $counter, ?RecordingLogger $logger = null): MailCircuitBreaker
    {
        return new MailCircuitBreaker($counter, $logger ?? new RecordingLogger(), self::WARN, self::BREAKER);
    }
}

/** 计数器恒返回同一个值 —— 让阈值判定成为用例里唯一的变量。 */
final class FixedVolumeCounter implements MailVolumeCounterInterface
{
    public function __construct(private readonly int $volume)
    {
    }

    public function incrementAndGet(): int
    {
        return $this->volume;
    }
}

/** Redis 不可达。驱动 fail-open 分支。 */
final class UnavailableVolumeCounter implements MailVolumeCounterInterface
{
    public function incrementAndGet(): int
    {
        throw new \RuntimeException('邮件发信量计数器不可达。');
    }
}
