<?php

declare(strict_types=1);

namespace App\Tests\Double\Crypto;

use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * 进程内的加密替身。**不是加密**，只是一个可逆的编码。
 *
 * ============================================================================
 * 为什么现在才有这个替身
 * ============================================================================
 * T-005 起，所有涉及加解密的用例一律打真 Vault（`tests/Integration/Support/RequiresVault`），
 * 理由是「往返一致」这条验收标准只有真 Transit 能证明。那条规矩不变。
 *
 * T-102 需要一个替身，是因为它第一次出现了**与加密无关、却必须经过加密门面**的
 * 逻辑：{@see \App\Module\Notification\Infrastructure\Messenger\EncryptedMailSerializer}
 * 要验的是「stamp 有没有被保住」「明文有没有泄进 body」，
 * 而这些断言不该在没起 compose 栈的开发机上被 skip 掉 ——
 * 「队列里不许出现明文邮箱」是本任务最重要的一条不变量。
 *
 * 真 Vault 上的往返仍然有一条独立的集成用例（EncryptedMailSerializerVaultTest）。
 *
 * ============================================================================
 * ⚠️ 编码方式刻意「看得出是假的」
 * ============================================================================
 * base64 + 一个固定前缀，任何人扫一眼输出就知道这不是真加密。
 * 用一个看起来像密码学的东西（XOR、ROT）反而危险：它会让「这个替身安全吗」
 * 变成一个需要思考的问题，而答案永远是不安全。
 *
 * 但它**必须**满足一条真实性质：输出里不能含有明文的任何子串 ——
 * 否则 EncryptedMailSerializerTest 里那句「body 不含明文邮箱」会因为
 * 替身太弱而假绿。base64 满足这条。
 */
final class InMemoryCryptoService implements CryptoServiceInterface
{
    /** 让「这是测试替身」在任何一处输出里都一目了然。 */
    private const MARKER = 'fake';

    /** 下一次 encrypt/decrypt 是否抛异常，用来驱动降级分支。 */
    private ?CryptoFailed $failure = null;

    /**
     * 让调用方模拟 Vault 不可达（EncryptedMailSerializer 对
     * CryptoUnavailable 与 CryptoFailed 的处理**刻意不同**，两条都要测）。
     */
    public function failWith(?CryptoFailed $failure): void
    {
        $this->failure = $failure;
    }

    public function unavailable(): void
    {
        $this->failWith(new CryptoUnavailable('Vault unreachable (test double).'));
    }

    public function encrypt(CryptoKey $key, string $plaintext): Ciphertext
    {
        $this->maybeFail();

        // key 也编进去：解密时校验，于是「用 Card 的 key 解 Pii 的密文」
        // 这种错误在测试里会被抓住，而不是悄悄成功。
        return Ciphertext::fromString(\sprintf(
            'vault:v1:%s.%s.%s',
            self::MARKER,
            $key->value,
            base64_encode($plaintext),
        ));
    }

    public function decrypt(CryptoKey $key, Ciphertext $ciphertext): string
    {
        $this->maybeFail();

        $expectedPrefix = \sprintf('vault:v1:%s.%s.', self::MARKER, $key->value);
        $value = $ciphertext->toString();

        if (!str_starts_with($value, $expectedPrefix)) {
            throw new CryptoFailed('Ciphertext was not produced by this double, or the key does not match.');
        }

        $decoded = base64_decode(substr($value, \strlen($expectedPrefix)), true);

        if (false === $decoded) {
            throw new CryptoFailed('Malformed payload in test double ciphertext.');
        }

        return $decoded;
    }

    private function maybeFail(): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
    }
}
