<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Notification;

use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailSenderInterface;
use App\Module\Notification\Application\SendMailCommand;
use App\Module\Notification\Application\SendMailHandler;
use App\Module\Notification\Infrastructure\Mail\SymfonyMailerTransport;
use App\Module\Notification\Infrastructure\Messenger\EncryptedMailSerializer;
use App\Module\Notification\Infrastructure\Messenger\QueueingMailSender;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

/**
 * T-102 的端到端：`MailSenderInterface::send()` → `messenger_messages` → worker → 一封信。
 *
 * ============================================================================
 * 这条用例就是本任务的第一个调用方
 * ============================================================================
 * T-102 交付的通道在本 PR 内**没有生产调用方**（第一个是 T-103 的
 * `POST /auth/otp/request`）。沿用 T-004 给 `/v1/_probe` 立下的做法：
 * 不留未被执行的代码路径 —— 否则「入队 → 消费 → 发信」这条链路要到
 * T-103 落地那天才第一次真的运行，而那时它的失败会被算在 T-103 头上。
 *
 * ============================================================================
 * 最重要的一条断言在 testQueuedBodyIsEncryptedAtRest()
 * ============================================================================
 * `messenger_messages.body` 是**真的落进 Postgres 的那一列**，而那张表会被
 * T-406 的备份 age 加密后推去 Hetzner Storage Box。§3.8「不存明文邮箱列」与
 * §7.1「不存明文码」如果在这一层破了，前面所有的加密都白做。
 *
 * 单元测试（EncryptedMailSerializerTest）已经断言过同一件事，但那里用的是
 * 替身；这一条走真 Vault + 真 Postgres，验的是「配置真的接上了」——
 * 少配一行 `serializer:`，单元测试照样全绿。
 */
#[CoversClass(QueueingMailSender::class)]
#[CoversClass(SymfonyMailerTransport::class)]
#[CoversClass(EncryptedMailSerializer::class)]
#[CoversClass(SendMailCommand::class)]
#[CoversClass(MailRequest::class)]
final class QueuedMailPipelineTest extends KernelTestCase
{
    use MailerAssertionsTrait;

    private const RECIPIENT = 'anna.beispiel@gmx.de';
    private const OTP_CODE = '481502';

    private Connection $connection;

    /**
     * setUp() 是否走完了全部前置检查。
     *
     * ⚠️ PHPUnit 在 `markTestSkipped()` 之后**依然会调用 tearDown()**。
     * 没有这个标志的话，裸机（没起 compose 栈）上每条 skip 都会在 tearDown 里
     * 再撞一次「连不上 Postgres」，于是 4 条 skip 变成 4 条 error ——
     * 而「裸机 composer test 全绿」是本仓库反复申明的不变量。
     */
    private bool $ready = false;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $this->connection = $connection;

        try {
            $this->connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        if (null === $this->connection->fetchOne("SELECT to_regclass('messenger_messages')")) {
            self::markTestSkipped(
                'messenger_messages 还没建 —— 先跑 `composer migration:check`'
                .'（或 `bin/console --env=test doctrine:migrations:migrate`）。',
            );
        }

        // 加密门面要打真 Vault。连不上就 skip —— 与 RequiresVault 同一口径。
        try {
            $this->crypto()->encrypt(CryptoKey::Pii, 'probe');
        } catch (\Throwable $e) {
            self::markTestSkipped('Vault 不可达或未初始化（'.$e->getMessage().'）。');
        }

        // 上一次跑剩下的消息会让「队列里恰好一条」的断言变得不可靠。
        $this->connection->executeStatement('DELETE FROM messenger_messages');

        $this->ready = true;
    }

    protected function tearDown(): void
    {
        if ($this->ready) {
            // 这张表不在 RequiresIdentitySchema 的事务回滚范围里 ——
            // transport 用自己的连接写，包在外层事务里也回滚不掉。显式清。
            $this->connection->executeStatement('DELETE FROM messenger_messages');
        }

        parent::tearDown();
    }

    /**
     * §3.8 / §7.1 在队列这一层的落点。**本任务最重要的一条断言。**.
     */
    public function testQueuedBodyIsEncryptedAtRest(): void
    {
        $this->sender()->send($this->request());

        $row = $this->connection->fetchAssociative('SELECT queue_name, body FROM messenger_messages');
        self::assertIsArray($row, '入队之后 messenger_messages 里应该恰好有一行。');

        $body = $row['body'];
        self::assertIsString($body);

        self::assertStringNotContainsString(self::RECIPIENT, $body, '明文收件邮箱落进了数据库（§3.8）。');
        self::assertStringNotContainsString(self::OTP_CODE, $body, '明文 OTP 码落进了数据库（§7.1）。');
        self::assertStringStartsWith('vault:v', $body, 'body 应该是 Vault Transit 密文。');
    }

    /**
     * 入队即返回，**不等 SMTP**（§3.8 防枚举的前提，见 MailSenderInterface 的类注释）。
     */
    public function testSendingDoesNotDeliverSynchronously(): void
    {
        $this->sender()->send($this->request());

        self::assertCount(0, self::getMailerMessages(), 'send() 必须只入队，投递归 worker。');
    }

    /**
     * 消费一条消息：解密收件人、渲染双语模板、交给 Mailer。
     */
    public function testConsumingTheQueueDeliversTheRenderedMail(): void
    {
        $this->sender()->send($this->request());

        $this->consumeOne();

        self::assertCount(1, self::getMailerMessages());

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        // 收件人是解密出来的 —— 全链路上明文只在 SymfonyMailerTransport::send() 里存在过。
        self::assertSame(self::RECIPIENT, $email->getTo()[0]->getAddress());

        // 主题来自 de/ 那份模板，且带着码（自动填充要用）。
        self::assertStringContainsString(self::OTP_CODE, $email->getSubject() ?? '');

        // text 与 html 两份正文都要有 —— BodyRenderer 若没被调用，两者都会是空的，
        // 而 MailerInterface::send() 对此不会报错（一封静默发出的空信）。
        self::assertStringContainsString(self::OTP_CODE, (string) $email->getTextBody());
        self::assertStringContainsString(self::OTP_CODE, (string) $email->getHtmlBody());
    }

    public function testConsumingRemovesTheMessageFromTheQueue(): void
    {
        $this->sender()->send($this->request());
        $this->consumeOne();

        self::assertSame(
            0,
            (int) $this->connection->fetchOne('SELECT count(*) FROM messenger_messages'),
        );
    }

    /**
     * 从 transport 取一条消息并交给 Handler —— 相当于 worker 的一轮循环，
     * 但不用起进程。
     */
    private function consumeOne(): void
    {
        /** @var TransportInterface $transport */
        $transport = self::getContainer()->get('messenger.transport.email');

        $envelopes = iterator_to_array($transport->get(), false);
        self::assertNotSame([], $envelopes, '队列里应该有一条待消费的消息。');

        $envelope = $envelopes[0];

        /** @var SendMailHandler $handler */
        $handler = self::getContainer()->get(SendMailHandler::class);

        $message = $envelope->getMessage();
        self::assertInstanceOf(SendMailCommand::class, $message);

        $handler($message);

        $transport->ack($envelope);
    }

    private function request(): MailRequest
    {
        return new MailRequest(
            MailTemplate::OtpCode,
            MailLocale::German,
            $this->crypto()->encrypt(CryptoKey::Pii, self::RECIPIENT),
            ['code' => self::OTP_CODE, 'expires_in_minutes' => '10'],
        );
    }

    private function sender(): MailSenderInterface
    {
        /** @var MailSenderInterface $sender */
        $sender = self::getContainer()->get(MailSenderInterface::class);

        return $sender;
    }

    private function crypto(): CryptoServiceInterface
    {
        /** @var CryptoServiceInterface $crypto */
        $crypto = self::getContainer()->get(CryptoServiceInterface::class);

        return $crypto;
    }
}
