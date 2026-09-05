<?php

declare(strict_types=1);

namespace App\Module\Notification\Application;

use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailTransportInterface;
use App\Module\Notification\Domain\MailResult;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use Psr\Log\LoggerInterface;

/**
 * `email` transport 的消费端（跑在 worker 里，T-102）。
 *
 * ============================================================================
 * ⚠️ 没有 `#[AsMessageHandler]`
 * ============================================================================
 * 那个属性是 `Symfony\Component\Messenger\Attribute\AsMessageHandler`，
 * 落在 deptrac 的 `Framework.Messaging` 图层，而该层只对 `*.Infrastructure` 开放 ——
 * 在 Application 层写它是 violation。
 *
 * 注册改成 `config/services.yaml` 里一条显式的
 * `tags: [{name: messenger.message_handler, bus: mail.bus}]`。
 * 这与本仓库「接口 → 实现别名显式写出来，不依赖容器的自动行为」的既有做法
 * 一致（见 services.yaml 里那段解释）。
 *
 * 把 Handler 留在 Application 而不是挪进 Infrastructure 换掉这个麻烦，
 * 是因为熔断判定与指标记录是**编排逻辑**：它们要可单测，
 * 也要计入 `Module/<M>/Application` 的 85% 覆盖率门槛（tools/coverage-check.php）。
 *
 * ============================================================================
 * 异常一律冒泡
 * ============================================================================
 * 除了下面那条「消息本身解不出来」的死信路径，任何投递失败都**不 catch**：
 * Messenger 的 `retry_strategy` 会重投三次，三次都失败进 `email_failed`。
 * 在这里吞掉异常等于把一封没发出去的 OTP 信记成成功，
 * 而 §14.4 的「邮件发送失败率 > 5%」P1 告警从此永远不会触发。
 */
final readonly class SendMailHandler
{
    /**
     * @param non-empty-string $provider §14.4 `email_send_total` 的 provider 标签。
     *                                   Q3 已决（ADR-0013）：`dogado`，即
     *                                   `n-cards.de` 的域名邮箱
     */
    public function __construct(
        private MailTransportInterface $transport,
        private MailCircuitBreaker $circuitBreaker,
        private MetricsInterface $metrics,
        private LoggerInterface $logger,
        private string $provider,
    ) {
    }

    public function __invoke(SendMailCommand $command): void
    {
        $template = MailTemplate::tryFrom($command->template);
        $locale = MailLocale::tryFrom($command->locale);

        if (null === $template || null === $locale) {
            // 这条消息是别的版本的代码入队的，且那个版本认识我们不认识的取值
            // （回滚场景：ADR-0010 规定回滚只换镜像 tag，队列不清空）。
            //
            // **丢弃而不是抛**：抛出去会让 Messenger 重投三次再进 email_failed，
            // 而重投一条永远解不出来的消息只是把同一行错误日志写四遍。
            // 记 error 让它在 §14.4 的告警链路里可见即可。
            $this->logger->error('收到无法识别的邮件消息，已丢弃。可能是回滚后队列里的旧消息（ADR-0010）。', [
                'template' => $command->template,
                'locale' => $command->locale,
            ]);

            return;
        }

        if (!$this->circuitBreaker->allows($template)) {
            // 熔断已生效且这是一封 Advisory 信（§3.1）。
            // 计数已经在 allows() 里加过了，这里只记指标与日志。
            $this->logger->warning('全局熔断生效，已丢弃一封非关键邮件。', [
                'template' => $template->basename(),
            ]);
            $this->record($template, MailResult::Suppressed);

            return;
        }

        // ⚠️ 到这里为止，收件人一直是密文。解密发生在 transport 实现内部、
        // 构造 MIME 消息的前一行 —— 明文地址的作用域是一个方法体（§3.8）。
        $request = new MailRequest(
            $template,
            $locale,
            Ciphertext::fromString($command->recipient),
            $command->variables,
        );

        try {
            $this->transport->send($request);
        } catch (\Throwable $e) {
            // 记完指标**再抛**。不记的话，失败率分母涨、分子不涨 ——
            // §14.4 那条 P1 告警会在通道彻底坏掉时反而显示得更健康。
            $this->record($template, MailResult::Failed);

            throw $e;
        }

        $this->record($template, MailResult::Sent);
    }

    private function record(MailTemplate $template, MailResult $result): void
    {
        $this->metrics->counter('email_send_total', [
            'provider' => $this->provider,
            'template' => $template->basename(),
            'result' => $result->value,
        ]);
    }
}
