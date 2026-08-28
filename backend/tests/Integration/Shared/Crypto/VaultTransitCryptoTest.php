<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Crypto\VaultHmacHasher;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Vault\VaultClient;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 真实 Vault 容器上的加解密往返（T-005 验收标准第一条）。
 *
 * 单测用 MockHttpClient 验的是「我们怎么跟 Vault 说话」；这里验的是那套说法
 * 在**真 Transit 引擎**上确实成立 —— 尤其是二进制安全与 base64 的来回，
 * 那是替身怎么写都能通过、但真跑起来最容易坏的一段。
 */
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(VaultHmacHasher::class)]
#[CoversClass(VaultClient::class)]
#[CoversClass(Ciphertext::class)]
final class VaultTransitCryptoTest extends TestCase
{
    use RequiresVault;

    private VaultClient $client;
    private VaultTransitCrypto $crypto;

    protected function setUp(): void
    {
        $this->client = $this->vaultClient();
        $this->assertVaultBootstrapped($this->client);
        $this->crypto = new VaultTransitCrypto($this->client);
    }

    /**
     * §5.3 那条链路的端到端验证：
     * 明文 → Transit encrypt → `vault:v1:BASE64` → Transit decrypt → 同一个明文。
     */
    #[DataProvider('payloads')]
    public function testRoundTripsThroughTransit(string $plaintext, string $why): void
    {
        $ciphertext = $this->crypto->encrypt(CryptoKey::Card, $plaintext);

        self::assertStringStartsWith('vault:v', $ciphertext->toString(), $why);
        self::assertSame($plaintext, $this->crypto->decrypt(CryptoKey::Card, $ciphertext), $why);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function payloads(): iterable
    {
        yield 'EAN-13 条码' => ['4012345678901', '最典型的 barcode_value'];
        yield '空串' => ['', 'note_encrypted 可以是空的'];
        yield 'UTF-8 德语' => ['Röslein auf der Heiden — ÄÖÜäöüß', '§11.1：德语是默认语言'];
        yield 'emoji' => ['🎫🇩🇪', '卡片备注里用户什么都可能写'];
        // §17.1 允许 1024 字节的 barcode payload，PDF417 的实际内容就可能是二进制。
        yield '含 NUL 的二进制' => ["\x00\x01\x02\xff\xfe", '二进制安全 —— 这正是要 base64 的原因'];
        yield '1024 字节上限' => [str_repeat('A', 1024), '§7.5 的 barcode payload 长度上限'];
        yield '2000 字符的 note' => [str_repeat('äö', 1000), '§7.5 的 note 长度上限，且是多字节'];
    }

    /**
     * 两把信封 key 都要能用，且**互不通用** —— 用 card 的 key 解 pii 的密文必须失败。
     *
     * 这条守的是 §5.3 「一把 key 一个爆炸半径」：如果两把 key 事实上可以互相解密，
     * 那分成两把就只是形式，事故时无法分别处置。
     */
    public function testKeysAreNotInterchangeable(): void
    {
        $ciphertext = $this->crypto->encrypt(CryptoKey::Pii, 'anna@example.de');

        self::assertSame('anna@example.de', $this->crypto->decrypt(CryptoKey::Pii, $ciphertext));

        $this->expectException(CryptoFailed::class);

        $this->crypto->decrypt(CryptoKey::Card, $ciphertext);
    }

    /**
     * 同一份明文两次加密得到**不同**的密文（AES-GCM 每次用新 nonce）。
     *
     * 这不是锦上添花：如果密文是确定性的，`cards.barcode_value_encrypted` 列本身
     * 就变成了一个可比较的指纹 —— 拿到数据库的人不用解密就能看出「这两个用户
     * 有同一张会员卡」。§3.3 承诺防护的正是「数据库文件泄露」这一档。
     *
     * （去重要用的是单独的 `barcode_value_fingerprint` 列，走 HMAC，见下一条。）
     */
    public function testEncryptionIsNotDeterministic(): void
    {
        $a = $this->crypto->encrypt(CryptoKey::Card, '4012345678901');
        $b = $this->crypto->encrypt(CryptoKey::Card, '4012345678901');

        self::assertNotSame($a->toString(), $b->toString(), '密文若是确定性的，密文列本身就成了可比对的指纹。');
        self::assertSame('4012345678901', $this->crypto->decrypt(CryptoKey::Card, $a));
        self::assertSame('4012345678901', $this->crypto->decrypt(CryptoKey::Card, $b));
    }

    public function testRejectsATamperedCiphertext(): void
    {
        $ciphertext = $this->crypto->encrypt(CryptoKey::Card, '4012345678901');

        // 改掉载荷的最后一个字符。AES-GCM 是带认证的，篡改必须被检出而不是
        // 悄悄解出一段垃圾。
        $tampered = Ciphertext::fromString(substr($ciphertext->toString(), 0, -2).'XY');

        $this->expectException(CryptoFailed::class);

        $this->crypto->decrypt(CryptoKey::Card, $tampered);
    }

    /**
     * bootstrap.sh 建的是 v1，且 min_decryption_version 默认为 1。
     * T-404 轮换之后这里会变成 v2 —— 那时这条用例会红，提醒有人确认迁移状态。
     */
    public function testFreshCiphertextsUseKeyVersionOne(): void
    {
        self::assertSame(1, $this->crypto->encrypt(CryptoKey::Card, 'x')->keyVersion());
    }

    // ========================================================================
    // HMAC（§3.8 的 email_hash / §5.3 的 pepper）
    // ========================================================================

    /**
     * HMAC **必须**是确定性的 —— 它是查找键。
     *
     * `users.email_hash` 上有 UNIQUE 约束，登录时按它查用户。同一个邮箱两次算出
     * 不同的值，登录就永远查不到人。
     */
    public function testHmacIsDeterministic(): void
    {
        $hasher = new VaultHmacHasher($this->client);

        $a = $hasher->hash('anna@example.de');
        $b = $hasher->hash('anna@example.de');

        self::assertSame(bin2hex($a), bin2hex($b));
    }

    /**
     * 32 字节裸摘要，直接进 §17.1 的 `email_hash BYTEA`。
     */
    public function testHmacReturnsThirtyTwoRawBytes(): void
    {
        $digest = (new VaultHmacHasher($this->client))->hash('anna@example.de');

        self::assertSame(32, \strlen($digest));
        self::assertStringStartsNotWith('vault:', $digest, '进 BYTEA 的是裸摘要，不是带版本前缀的字符串。');
    }

    public function testDifferentInputsGiveDifferentDigests(): void
    {
        $hasher = new VaultHmacHasher($this->client);

        self::assertNotSame(
            bin2hex($hasher->hash('anna@example.de')),
            bin2hex($hasher->hash('bob@example.de')),
        );
    }

    public function testHmacVerifyRoundTrips(): void
    {
        $hasher = new VaultHmacHasher($this->client);
        $digest = $hasher->hash('anna@example.de');

        self::assertTrue($hasher->verify('anna@example.de', $digest));
        self::assertFalse($hasher->verify('bob@example.de', $digest));
    }

    /**
     * HMAC 同样要二进制安全：`barcode_value_fingerprint` 算的是条码 payload，
     * 而那可能是任意字节（§17.1）。
     */
    public function testHmacIsBinarySafe(): void
    {
        $hasher = new VaultHmacHasher($this->client);
        $binary = "\x00\xff\xfe not utf-8 \x80";

        self::assertSame(32, \strlen($hasher->hash($binary)));
        self::assertTrue($hasher->verify($binary, $hasher->hash($binary)));
    }
}
