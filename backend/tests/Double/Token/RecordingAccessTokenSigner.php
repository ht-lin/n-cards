<?php

declare(strict_types=1);

namespace App\Tests\Double\Token;

use App\Shared\Application\Token\AccessTokenSignerInterface;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Domain\Token\IssuedAccessToken;

/**
 * 不真签名的 access token 替身，**并留下收到的 claim 集**。
 *
 * 真签名（Ed25519、header 的 kid、base64url）由
 * `tests/Unit/Shared/Infrastructure/Token/Ed25519AccessTokenSignerTest` 覆盖，
 * 真密钥的往返由 `tests/Integration/.../VaultKvSigningKeyProviderTest` 覆盖。
 *
 * 这里要断言的是**编排**：`sub` / `sid` / `did` 分别取自哪三个对象。
 * 那三个 id 极易接错（都是 UUID，编译器分不出），而接错的症状是
 * 「T-105 的远程登出撤销了错误的会话」—— 一个在单测里几乎不可能被偶然发现的 bug。
 */
final class RecordingAccessTokenSigner implements AccessTokenSignerInterface
{
    /** 签出来的 token 长这样，一眼看得出是假的。 */
    public const PREFIX = 'fake-jwt.';

    /** @var list<AccessTokenClaims> */
    private array $signed = [];

    private ?CryptoUnavailable $failure = null;

    /**
     * 让调用方模拟「Vault 读不到签名密钥」——
     * 那发生在挑战已被消费、用户与会话已经建好之后。
     */
    public function failWith(?CryptoUnavailable $failure): void
    {
        $this->failure = $failure;
    }

    public function sign(AccessTokenClaims $claims): IssuedAccessToken
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->signed[] = $claims;

        $expiresIn = $claims->expiresInSeconds();

        \assert($expiresIn > 0);

        return new IssuedAccessToken(self::PREFIX.$claims->tokenId->toString(), $expiresIn);
    }

    /**
     * 唯一一次签名的 claim 集 —— 一次成功的登录恰好签一枚 token。
     */
    public function only(): AccessTokenClaims
    {
        if (1 !== \count($this->signed)) {
            throw new \LogicException(\sprintf('Expected exactly one signed token, got %d.', \count($this->signed)));
        }

        return $this->signed[0];
    }

    public function count(): int
    {
        return \count($this->signed);
    }
}
