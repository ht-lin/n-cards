<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Notification\Infrastructure\Messenger;

use App\Module\Notification\Application\SendMailCommand;
use App\Module\Notification\Infrastructure\Messenger\EncryptedMailSerializer;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Tests\Double\Crypto\InMemoryCryptoService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * T-102：队列里那条消息不许含明文，且 stamp 必须原样活下来。
 */
#[CoversClass(EncryptedMailSerializer::class)]
final class EncryptedMailSerializerTest extends TestCase
{
    /** 一个真实形态的收件人与 OTP 码，下面几处断言都拿它们做「不许出现」的靶子。 */
    private const RECIPIENT_PLAINTEXT = 'anna.beispiel@gmx.de';
    private const OTP_CODE = '481502';

    private InMemoryCryptoService $crypto;
    private EncryptedMailSerializer $serializer;

    protected function setUp(): void
    {
        $this->crypto = new InMemoryCryptoService();
        $this->serializer = new EncryptedMailSerializer(new PhpSerializer(), $this->crypto);
    }

    public function testRoundTripPreservesTheMessage(): void
    {
        $envelope = new Envelope($this->command());

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));

        $message = $decoded->getMessage();
        self::assertInstanceOf(SendMailCommand::class, $message);
        self::assertSame('otp_code', $message->template);
        self::assertSame('de', $message->locale);
        self::assertSame(['code' => self::OTP_CODE, 'expires_in_minutes' => '10'], $message->variables);
    }

    /**
     * §3.8「不存明文邮箱列」与 §7.1「不存明文码」在队列这一层的落点。
     *
     * 这是本任务最重要的一条断言：`body` 是**原样写进 `messenger_messages`**
     * 的那个字符串，而那张表会被 T-406 的备份 age 加密后推去 Storage Box。
     */
    public function testEncodedBodyContainsNeitherTheRecipientNorTheCode(): void
    {
        $encoded = $this->serializer->encode(new Envelope($this->command()));

        self::assertStringNotContainsString(self::RECIPIENT_PLAINTEXT, $encoded['body']);
        self::assertStringNotContainsString(self::OTP_CODE, $encoded['body']);

        // 顺带确认它确实被加密过，而不是「碰巧没匹配上」——
        // 少了这一条，一个返回空字符串的 encode() 也能让上面两句通过。
        self::assertStringStartsWith('vault:v1:', $encoded['body']);
    }

    /**
     * ⚠️ 这条测的是「装饰 PhpSerializer 而不是自己编解码」这个决定本身。
     *
     * Messenger 的重投计数存在 `RedeliveryStamp` 里，而 stamp 全都序列化在 body 中。
     * 自己写 JSON 编解码会静默丢掉它们，症状是 `retry_strategy: {max_retries: 3}`
     * 永远达不到 3 —— 每次重投都从 0 开始，一封发不出去的信无限重投，
     * 且看起来完全像是 transport 在正常工作。
     */
    public function testRoundTripPreservesStamps(): void
    {
        $envelope = (new Envelope($this->command()))
            ->with(new RedeliveryStamp(2))
            ->with(new BusNameStamp('mail.bus'));

        $decoded = $this->serializer->decode($this->serializer->encode($envelope));

        $redelivery = $decoded->last(RedeliveryStamp::class);
        self::assertInstanceOf(RedeliveryStamp::class, $redelivery);
        self::assertSame(2, $redelivery->getRetryCount());

        $busName = $decoded->last(BusNameStamp::class);
        self::assertInstanceOf(BusNameStamp::class, $busName);
        self::assertSame('mail.bus', $busName->getBusName());
    }

    public function testDecodeRejectsAnEmptyBody(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => '']);
    }

    /**
     * 本序列化器上线**之前**入队、之后才被消费的消息（一次部署的窗口内是可能的）。
     *
     * 不重投直接进 failure transport 是对的 —— 重投一条永远解不开的消息
     * 只是把同一行错误写四遍。
     */
    public function testDecodeRejectsAPlaintextBodyWithoutRetrying(): void
    {
        $this->expectException(MessageDecodingFailedException::class);

        $this->serializer->decode(['body' => 'O:8:"stdClass":0:{}']);
    }

    /**
     * ⚠️ 异常消息里绝不能出现 body 的内容 —— 那可能正是一条明文消息，
     * 也就是一个邮箱地址加一个 OTP 码，而异常会进日志与 Sentry。
     */
    public function testDecodeFailureDoesNotLeakTheBodyIntoTheExceptionMessage(): void
    {
        $plaintextBody = \sprintf('to=%s;code=%s', self::RECIPIENT_PLAINTEXT, self::OTP_CODE);

        try {
            $this->serializer->decode(['body' => $plaintextBody]);
            self::fail('期望抛出 MessageDecodingFailedException。');
        } catch (MessageDecodingFailedException $e) {
            self::assertStringNotContainsString(self::RECIPIENT_PLAINTEXT, (string) $e);
            self::assertStringNotContainsString(self::OTP_CODE, (string) $e);
        }
    }

    /**
     * Vault 封着 / 不可达是**暂时**状况，必须让它冒泡去重投。
     *
     * 包成 MessageDecodingFailedException 的话，一次 unseal 窗口期
     * （ADR-0004 的人工 Shamir 3-of-5，按分钟算）会把那期间队列里的
     * 每一封 OTP 信都变成死信，且 unseal 完成后不会自己回来。
     *
     * `CryptoUnavailable extends CryptoFailed`，所以这条同时在守
     * EncryptedMailSerializer 里那两个 catch 的**顺序**。
     */
    public function testTransientVaultOutageIsNotTurnedIntoADeadLetter(): void
    {
        $encoded = $this->serializer->encode(new Envelope($this->command()));

        $this->crypto->unavailable();

        // 不是 MessageDecodingFailedException —— 那个会让消息不重投。
        $this->expectException(CryptoFailed::class);
        $this->serializer->decode($encoded);
    }

    private function command(): SendMailCommand
    {
        return new SendMailCommand(
            'otp_code',
            'de',
            $this->crypto->encrypt(\App\Shared\Domain\Crypto\CryptoKey::Pii, self::RECIPIENT_PLAINTEXT)->toString(),
            ['code' => self::OTP_CODE, 'expires_in_minutes' => '10'],
        );
    }
}
