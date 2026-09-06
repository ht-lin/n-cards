<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Token\VaultKvSigningKeyProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PEM → 32 字节的定长切片（{@see VaultKvSigningKeyProvider} 的类注释）。
 *
 * ============================================================================
 * 为什么夹具是 openssl **真的**生成的那一对，而不是拼出来的
 * ============================================================================
 * 如果用「固定前缀 + 任意 32 字节」拼一个 DER 出来喂给解析器，这组用例就是循环的：
 * 它验的是「我拼的东西符合我自己写的前缀常量」，而不是「openssl 写出来的东西
 * 能不能被读懂」。而 `infra/vault/bootstrap.sh` 用的正是 `openssl genpkey`。
 *
 * 所以下面两个常量是一次真实的 `openssl genpkey -algorithm ed25519` 的输出
 * （一把只存在于本文件里的测试密钥），配对关系由
 * {@see testTheParsedSeedDerivesTheMatchingPublicKey()} 独立验证 ——
 * 那条用例把「私钥解析对不对」这个问题转换成了「用它推出来的公钥等不等于
 * openssl 写下的那份公钥」，而后者不经过任何我们自己的代码。
 *
 * ⚠️ 真 Vault 上的往返在 tests/Integration 里另有一条。
 */
#[CoversClass(VaultKvSigningKeyProvider::class)]
final class VaultKvSigningKeyProviderTest extends TestCase
{
    /** `openssl genpkey -algorithm ed25519` 的输出。**仅用于测试**。 */
    private const PRIVATE_PEM = <<<'PEM'
        -----BEGIN PRIVATE KEY-----
        MC4CAQAwBQYDK2VwBCIEIF8jlXsvE6MwCvuPU6n9qCKd7qqMn73PBnA11jfQI4je
        -----END PRIVATE KEY-----
        PEM;

    /** 同一把密钥的 `openssl pkey -pubout`。 */
    private const PUBLIC_PEM = <<<'PEM'
        -----BEGIN PUBLIC KEY-----
        MCowBQYDK2VwAyEAegYXrtCYccvo//NUKsr7NVmdH2chQMMVFP3RtXXfKdM=
        -----END PUBLIC KEY-----
        PEM;

    public function testExtractsTheThirtyTwoByteSeedFromAPkcs8Pem(): void
    {
        self::assertSame(32, \strlen(VaultKvSigningKeyProvider::seedFromPkcs8Pem(self::PRIVATE_PEM)));
    }

    public function testExtractsTheThirtyTwoBytePublicKeyFromAnSpkiPem(): void
    {
        self::assertSame(32, \strlen(VaultKvSigningKeyProvider::publicKeyFromSpkiPem(self::PUBLIC_PEM)));
    }

    /**
     * ⚠️ 本文件最重要的一条：切出来的 32 字节**真的是那把私钥的 seed**。
     *
     * 切错位置（比如把内层 OCTET STRING 的两字节头也算进去）仍然会得到
     * 32 字节，`sodium_crypto_sign_seed_keypair()` 也照样接受它 ——
     * 只是推出来的公钥不同，于是签出来的 token 谁也验不过。
     * 这条用例把那种错误变成一次编译期就能发现的失败。
     */
    public function testTheParsedSeedDerivesTheMatchingPublicKey(): void
    {
        $seed = VaultKvSigningKeyProvider::seedFromPkcs8Pem(self::PRIVATE_PEM);

        $derived = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(self::nonEmpty($seed)));

        self::assertSame(
            bin2hex(VaultKvSigningKeyProvider::publicKeyFromSpkiPem(self::PUBLIC_PEM)),
            bin2hex($derived),
        );
    }

    /**
     * PEM 体里的换行、缩进与结尾空白都不该影响解析 —— KV 里那串 PEM
     * 经过了 jq、JSON 与 HTTP 三层，谁也不保证它逐字回来。
     */
    public function testToleratesWhitespaceAroundAndInsideTheBody(): void
    {
        // 结尾的空白与换行同样要容忍：PEM 文件末尾带换行是常态，
        // 而解析器只取 BEGIN 与 END 之间那一段，armour 之外的东西一概不看。
        $mangled = "\n\t".str_replace("\n", "\r\n  ", self::PRIVATE_PEM)."  \n";

        self::assertSame(
            bin2hex(VaultKvSigningKeyProvider::seedFromPkcs8Pem(self::PRIVATE_PEM)),
            bin2hex(VaultKvSigningKeyProvider::seedFromPkcs8Pem($mangled)),
        );
    }

    #[DataProvider('malformedPrivateKeys')]
    public function testRejectsAnythingThatIsNotAnEd25519Pkcs8Key(string $pem): void
    {
        $this->expectException(CryptoUnavailable::class);

        VaultKvSigningKeyProvider::seedFromPkcs8Pem($pem);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedPrivateKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'no armour' => ['MC4CAQAwBQYDK2VwBCIEIF8jlXsvE6MwCvuPU6n9qCKd7qqMn73PBnA11jfQI4je'];
        yield 'wrong label' => [str_replace('PRIVATE KEY', 'PUBLIC KEY', self::PRIVATE_PEM)];
        yield 'not base64' => ["-----BEGIN PRIVATE KEY-----\n!!!not base64!!!\n-----END PRIVATE KEY-----"];

        // 正确长度、正确 base64，但算法 OID 不是 Ed25519 —— 一把 RSA 或 X25519
        // 密钥会走到这里。前缀逐字节比对是拦住它的地方。
        yield 'right length wrong prefix' => [
            "-----BEGIN PRIVATE KEY-----\n".base64_encode(str_repeat("\x01", 48))."\n-----END PRIVATE KEY-----",
        ];

        // 少一个字节：切片会静默给出 31 字节，而 sodium 会在几层之外才炸。
        yield 'one byte short' => [
            "-----BEGIN PRIVATE KEY-----\n".base64_encode(substr(self::der(self::PRIVATE_PEM), 0, 47))."\n-----END PRIVATE KEY-----",
        ];

        // 多一个字节：同样必须拒绝 —— 尾部多出来的东西说明这不是我们以为的编码。
        yield 'one byte long' => [
            "-----BEGIN PRIVATE KEY-----\n".base64_encode(self::der(self::PRIVATE_PEM)."\x00")."\n-----END PRIVATE KEY-----",
        ];
    }

    /**
     * ⚠️ 异常文案里绝不能出现密钥片段 —— 它会进日志。
     * 所有失败共用同一句话，运维侧的处置本来也只有一个。
     */
    public function testTheFailureMessageLeaksNothingAboutTheKey(): void
    {
        try {
            // 正确长度、正确 base64，只是算法 OID 不对 —— 一把 RSA 密钥就长这样。
            VaultKvSigningKeyProvider::seedFromPkcs8Pem(
                "-----BEGIN PRIVATE KEY-----\n".base64_encode(str_repeat("\x01", 48))."\n-----END PRIVATE KEY-----",
            );
            self::fail('Expected a CryptoUnavailable.');
        } catch (CryptoUnavailable $exception) {
            self::assertSame('The JWT signing key could not be read.', $exception->detail());
            self::assertStringNotContainsString('MC4CAQAw', $exception->detail());
        }
    }

    /**
     * PHPStan 侧的收窄：`sodium_crypto_sign_*` 的签名要 `non-empty-string`，
     * 而 `base64_decode()` 与 PEM 解析返回的都是 `string`。
     *
     * 用一条断言而不是 `@var` 或强转：空串在这里是**真实的失败模式**
     * （base64 解不开、KV 里是空值），静默放过它会让 sodium 抛一个
     * 与根因无关的异常。
     *
     * @return non-empty-string
     */
    private static function nonEmpty(string $value): string
    {
        self::assertNotSame('', $value);

        return $value;
    }

    private static function der(string $pem): string
    {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);

        self::assertIsString($der);

        return $der;
    }
}
