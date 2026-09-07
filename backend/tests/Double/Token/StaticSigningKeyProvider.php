<?php

declare(strict_types=1);

namespace App\Tests\Double\Token;

use App\Shared\Application\Token\SigningKeyProviderInterface;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\SigningKey;

/**
 * 固定的一把签名密钥，**并记下被问了几次**。
 *
 * 调用次数不是好奇：`VaultKvSigningKeyProvider` 会带 TTL 缓存，
 * 而「缓存到底生效了没有」在真实现里只能靠数 Vault 往返来断言。
 * 这个替身给签名器那一侧的用例提供同样的手段。
 */
final class StaticSigningKeyProvider implements SigningKeyProviderInterface
{
    private int $calls = 0;

    private ?CryptoUnavailable $failure = null;

    /** @var array<non-empty-string, non-empty-string> */
    private array $extraVerificationKeys = [];

    public function __construct(private readonly SigningKey $key)
    {
    }

    /**
     * 由一个种子派生一把确定的密钥 —— 同一个种子恒得同一个签名，
     * 于是「签名对不对」写得成断言。
     */
    public static function fromSeed(string $seed = 'test-signing-seed', string $kid = 'test-kid-0001'): self
    {
        return new self(new SigningKey(hash('sha256', $seed, true), $kid));
    }

    public function failWith(?CryptoUnavailable $failure): void
    {
        $this->failure = $failure;
    }

    public function currentKey(): SigningKey
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        ++$this->calls;

        return $this->key;
    }

    /**
     * §5.3 重叠期的验签集合：本替身持有的那把，加上显式挂上去的历史密钥。
     *
     * 公钥由 seed 现推 —— 与 `Ed25519AccessTokenSigner` 里
     * `sodium_crypto_sign_seed_keypair()` 的推导完全同源，
     * 于是「签名器签的能不能被验签器验过」是这两个替身天然一致的，
     * 不需要在用例里手工配一对公私钥（配错了的症状是全部用例一起红，最难查）。
     */
    public function verificationKeys(): array
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        ++$this->calls;

        $kid = $this->key->kid;

        \assert('' !== $kid);

        return [$kid => self::publicKeyOf($this->key)] + $this->extraVerificationKeys;
    }

    /**
     * 往验签集合里加一把**只验不签**的密钥（模拟轮换重叠期里的 `previous`）。
     */
    public function alsoVerifyWith(SigningKey $key): void
    {
        $kid = $key->kid;

        \assert('' !== $kid);

        $this->extraVerificationKeys[$kid] = self::publicKeyOf($key);
    }

    /**
     * @return non-empty-string
     */
    public static function publicKeyOf(SigningKey $key): string
    {
        return sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($key->seed));
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
