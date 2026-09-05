<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notification\Application;

use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\MailCircuitBreaker;
use App\Module\Notification\Application\Port\MailTransportInterface;
use App\Module\Notification\Application\Port\MailVolumeCounterInterface;
use App\Module\Notification\Application\SendMailCommand;
use App\Module\Notification\Application\SendMailHandler;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Tests\Double\Crypto\InMemoryCryptoService;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * T-102：worker 侧的编排 —— 熔断判定、指标记录、异常传播。
 */
#[CoversClass(SendMailHandler::class)]
final class SendMailHandlerTest extends TestCase
{
    private const PROVIDER = 'unset';

    private InMemoryCryptoService $crypto;
    private RecordingTransport $transport;
    private RecordingMetrics $metrics;

    protected function setUp(): void
    {
        $this->crypto = new InMemoryCryptoService();
        $this->transport = new RecordingTransport();
        $this->metrics = new RecordingMetrics();
    }

    public function testSendsAndCountsSuccess(): void
    {
        $this->handler(volume: 1)($this->command());

        self::assertCount(1, $this->transport->sent);
        self::assertSame([self::PROVIDER, 'otp_code', 'sent'], $this->metrics->last());
    }

    /**
     * 熔断丢弃要记 `result="suppressed"`，**不能**记成 failed。
     *
     * 混进失败率的话，§14.4 那条「邮件发送失败率 > 5% → P1」在熔断生效期间
     * 必然触发 —— 于是「域名要被拉黑了」与「通道坏了」两个完全不同的事故
     * 长得一模一样。
     */
    public function testSuppressedAdvisoryMailIsNotCountedAsAFailure(): void
    {
        // 熔断阈值之上 + Advisory 模板 → 丢弃。
        $this->handler(volume: 999)($this->command(template: 'new_device_login', variables: [
            'device_model' => 'Pixel 7a',
            'occurred_at' => '2026-09-05 18:30 UTC',
            'approximate_region' => 'Bayern, DE',
            'revoke_url' => 'https://app.n-cards.de/l/revoke/abc',
        ]));

        self::assertSame([], $this->transport->sent, '熔断生效时不该真的投递。');
        self::assertSame([self::PROVIDER, 'new_device_login', 'suppressed'], $this->metrics->last());
    }

    /**
     * 投递失败要**先记指标再抛**。不记的话失败率分母涨、分子不涨，
     * §14.4 的 P1 告警会在通道彻底坏掉时反而显示得更健康。
     */
    public function testDeliveryFailureIsCountedBeforeItPropagates(): void
    {
        $this->transport->failure = new \RuntimeException('SMTP 535');

        try {
            $this->handler(volume: 1)($this->command());
            self::fail('投递失败必须冒泡，否则 Messenger 不会重投。');
        } catch (\RuntimeException) {
            // 期望的。
        }

        self::assertSame([self::PROVIDER, 'otp_code', 'failed'], $this->metrics->last());
    }

    /**
     * 队列里躺着一条本版本不认识的消息（ADR-0010：回滚只换镜像 tag，队列不清空）。
     *
     * 丢弃而不是抛 —— 重投一条永远解不出来的消息只是把同一行错误写四遍。
     */
    public function testUnknownTemplateIsDroppedWithoutRetrying(): void
    {
        $logger = new RecordingLogger();

        $this->handler(volume: 1, logger: $logger)(new SendMailCommand(
            'a_template_from_the_future',
            'de',
            $this->recipient(),
            [],
        ));

        self::assertSame([], $this->transport->sent);
        self::assertSame([], $this->metrics->calls, '解不出来的消息不该污染 email_send_total。');
        self::assertNotSame([], $logger->records, '丢弃必须留下痕迹。');
    }

    public function testUnknownLocaleIsDroppedToo(): void
    {
        $this->handler(volume: 1)(new SendMailCommand(
            'otp_code',
            'fr',
            $this->recipient(),
            ['code' => '123456', 'expires_in_minutes' => '10'],
        ));

        self::assertSame([], $this->transport->sent);
    }

    /**
     * @param array<string, string> $variables
     */
    private function command(string $template = 'otp_code', ?array $variables = null): SendMailCommand
    {
        return new SendMailCommand(
            $template,
            'de',
            $this->recipient(),
            $variables ?? ['code' => '481502', 'expires_in_minutes' => '10'],
        );
    }

    private function recipient(): string
    {
        return $this->crypto->encrypt(CryptoKey::Pii, 'anna.beispiel@gmx.de')->toString();
    }

    private function handler(int $volume, ?RecordingLogger $logger = null): SendMailHandler
    {
        $breaker = new MailCircuitBreaker(
            new StubVolumeCounter($volume),
            new RecordingLogger(),
            100,
            500,
        );

        return new SendMailHandler(
            $this->transport,
            $breaker,
            $this->metrics,
            $logger ?? new RecordingLogger(),
            self::PROVIDER,
        );
    }
}

final class StubVolumeCounter implements MailVolumeCounterInterface
{
    public function __construct(private readonly int $volume)
    {
    }

    public function incrementAndGet(): int
    {
        return $this->volume;
    }
}

final class RecordingTransport implements MailTransportInterface
{
    /** @var list<MailRequest> */
    public array $sent = [];

    public ?\Throwable $failure = null;

    public function send(MailRequest $request): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->sent[] = $request;
    }
}

final class RecordingMetrics implements MetricsInterface
{
    /** @var list<array{string, array<string, string>, int}> */
    public array $calls = [];

    public function counter(string $name, array $labels = [], int $by = 1): void
    {
        $this->calls[] = [$name, $labels, $by];
    }

    /**
     * 最后一次 `email_send_total` 的三个标签，按 provider / template / result 排好。
     *
     * @return list<string>
     */
    public function last(): array
    {
        $call = $this->calls[array_key_last($this->calls)] ?? null;

        if (null === $call) {
            return [];
        }

        return [$call[1]['provider'] ?? '', $call[1]['template'] ?? '', $call[1]['result'] ?? ''];
    }
}
