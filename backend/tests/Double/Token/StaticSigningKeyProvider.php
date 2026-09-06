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

    public function calls(): int
    {
        return $this->calls;
    }
}
