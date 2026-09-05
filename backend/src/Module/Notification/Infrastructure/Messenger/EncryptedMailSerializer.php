<?php

declare(strict_types=1);

namespace App\Module\Notification\Infrastructure\Messenger;

use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * `email` / `email_failed` transport 的序列化器：**消息体整体走 Vault Transit**。
 *
 * ============================================================================
 * 它解决的问题
 * ============================================================================
 * 队列里那条消息含**收件人邮箱**与**6 位 OTP 码**。不加密的话：
 *
 *   - §3.8「`users` 不存明文邮箱列」在队列这一层直接失效 ——
 *     `messenger_messages.body` 就是一列明文邮箱，而且它没有 `email_hash`
 *     那样的查找约束，是纯粹的净损失。
 *   - §7.1「OTP 码存 `HMAC-SHA256(code, pepper)`，**不存明文**」同上。
 *     `otp_challenges.code_hash` 那一列的全部意义会被隔壁一张表抵消。
 *   - T-406 的备份会把整个库 age 加密后推去 Hetzner Storage Box。
 *     于是明文邮箱与 OTP 码离开主机 —— 而 §8.2 给这两类数据的保留期
 *     分别是「账号存续期」与「10 分钟」。
 *
 * 加密之后，落库的是 `vault:v1:…`，解密能力在 Vault 的
 * `transit/decrypt/ncards-pii`（§17.4 的 policy），不在数据库里。
 *
 * ============================================================================
 * ⚠️ 为什么是「装饰原生序列化器」而不是手写 JSON 编解码
 * ============================================================================
 * 手写 JSON 的初衷是躲开 `unserialize()` 的反序列化 gadget 面。落地时发现
 * 两条事实推翻了它：
 *
 * 1. **Messenger 的 stamp 全都在 body 里**（见 `PhpSerializer::encode()`：
 *    `serialize($envelope)`，整个 Envelope 连同 stamps 一起）。自己编解码
 *    就必须自己搬运 `RedeliveryStamp` —— 而它正是重投计数的载体。
 *    漏掉它的症状是 `retry_strategy: {max_retries: 3}` **永远达不到 3**：
 *    每次重投都从 0 开始，一封发不出去的信会无限重投，
 *    且看起来完全像是「transport 在正常工作」。
 *    `ErrorDetailsStamp` / `SentToFailureTransportStamp` 同理，
 *    少了它们 `messenger:failed:show` 什么也看不见。
 *
 * 2. **gadget 面本来就被 Transit 关掉了**。`aes256-gcm96` 是 AEAD：
 *    密文被改一个字节，`decrypt` 就失败。能产出一段「解得开」的密文的人，
 *    必须持有 `transit/encrypt/ncards-pii` 的能力 —— 也就是应用自己。
 *    仅有数据库写权限的攻击者（这正是本序列化器要防的那个人）
 *    构造不出任何会被 `unserialize()` 看到的字节。
 *    所以这里的 `unserialize()` 比裸用 PhpSerializer 更安全，不是更危险。
 *
 * 结论：装饰它，只对 `body` 做一次加解密。二十行，且 stamp 语义分毫不动。
 *
 * ============================================================================
 * 代价：worker 起来时 Vault 必须可达
 * ============================================================================
 * 消费一条消息 = 一次 `transit/decrypt`。Vault 封着或不可达时 worker 消费不了 ——
 * 但那本来就是全站状态（§14.4 把「Vault sealed / 不可达」列为 **P0**，
 * 因为读卡也要它）。此时消息**留在队列里**而不是丢失，
 * unseal 之后自然被消费掉，见 docs/runbooks/vault-unseal.md。
 */
final readonly class EncryptedMailSerializer implements SerializerInterface
{
    public function __construct(
        private SerializerInterface $inner,
        private CryptoServiceInterface $crypto,
    ) {
    }

    public function encode(Envelope $envelope): array
    {
        $encoded = $this->inner->encode($envelope);

        $encoded['body'] = $this->crypto
            ->encrypt(CryptoKey::Pii, $encoded['body'])
            ->toString();

        return $encoded;
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'];

        if ('' === $body) {
            throw new MessageDecodingFailedException('邮件消息的 body 为空。');
        }

        try {
            $ciphertext = Ciphertext::fromString($body);
        } catch (\Throwable $e) {
            // body 不是 `vault:vN:…` 的形态。最可能的来源是「本序列化器上线之前
            // 入队、之后才被消费」的那一批消息 —— 一次部署的窗口内是可能的。
            //
            // 抛 MessageDecodingFailedException 而不是让它冒泡：Messenger 对这个
            // 异常的处理是**直接进 failure transport 不重投**，正好是想要的
            // （重投一条永远解不开的消息只是把同一行错误写四遍）。
            //
            // ⚠️ 异常消息里不放 $body。它可能正是一条明文消息 ——
            // 也就是一个邮箱地址加一个 OTP 码，而异常会进日志与 Sentry。
            throw new MessageDecodingFailedException('邮件消息的 body 不是 Vault Transit 密文。', 0, $e);
        }

        try {
            $encodedEnvelope['body'] = $this->crypto->decrypt(CryptoKey::Pii, $ciphertext);
        } catch (CryptoUnavailable $e) {
            // ⚠️ 这个 catch 看着像空转，但**删不得**：`CryptoUnavailable extends
            // CryptoFailed`，而 PHP 的 catch 是首个匹配者胜。没有这一条的话，
            // 下面那条会把它一起收走，于是「Vault 暂时封着」被当成「密文坏了」。
            //
            // 后果很具体：MessageDecodingFailedException 会让消息**不重投、
            // 直接进 failure transport**。而 ADR-0004 的人工 Shamir 3-of-5 unseal
            // 是按分钟算的窗口 —— 那期间队列里的每一封 OTP 信都会变成死信，
            // unseal 完成后也不会自己回来。让它冒泡去重投才是对的。
            throw $e;
        } catch (CryptoFailed $e) {
            // 密文解不开：AEAD 校验失败（被改过）或 key 版本已被销毁。
            // 这是**不可恢复**的，重投三次只是把同一行错误写四遍 ——
            // 直接进 failure transport 等人来看。
            throw new MessageDecodingFailedException('邮件消息的密文无法解密。', 0, $e);
        }

        return $this->inner->decode($encodedEnvelope);
    }
}
