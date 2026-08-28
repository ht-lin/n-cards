<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Crypto;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Infrastructure\Crypto\VaultHmacHasher;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(VaultHmacHasher::class)]
#[CoversClass(VaultClient::class)]
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(StaticTokenProvider::class)]
final class VaultHmacHasherTest extends TestCase
{
    /** @var list<array{url: string, body: array<string, mixed>}> */
    private array $requests = [];

    /**
     * 返回 **32 字节裸摘要**，不是 `vault:v1:...` 字符串。
     *
     * 落库形态决定了这一点：§17.1 的 `users.email_hash` 与
     * `cards.barcode_value_fingerprint` 都是 BYTEA。存带前缀的字符串会让 UNIQUE
     * 约束比的是一个含版本号的字符串，而这把 key 永不轮换、版本号恒为 v1 ——
     * 除了浪费 9 个字节没有任何作用。
     */
    public function testReturnsTheRawThirtyTwoByteDigest(): void
    {
        $digest = random_bytes(32);
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode($digest))]);

        $result = $hasher->hash('anna@example.de');

        self::assertSame($digest, $result);
        self::assertSame(32, \strlen($result));
    }

    public function testSendsBase64InputAndPinsTheAlgorithm(): void
    {
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode(random_bytes(32)))]);

        $hasher->hash('anna@example.de');

        self::assertSame('http://vault.test:8200/v1/transit/hmac/ncards-hmac', $this->requests[0]['url']);
        self::assertSame(base64_encode('anna@example.de'), $this->requests[0]['body']['input']);

        // 算法写死而不是靠 Transit 的默认值：默认值是 Vault 的实现细节，
        // 一次升级就可能改 —— 而算法一变，全部既有 email_hash 就再也匹配不上，
        // 且 HMAC 不可逆，无从迁移。
        self::assertSame('sha2-256', $this->requests[0]['body']['algorithm']);
    }

    /**
     * 长度不对说明 Vault 侧的 key 类型不对（比如 bootstrap.sh 建成了别的 key_size）。
     * 让它响，而不是把一个长度不对的值写进 BYTEA 列。
     */
    public function testRejectsADigestOfTheWrongLength(): void
    {
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode(random_bytes(64)))]);

        $this->expectException(CryptoFailed::class);

        $hasher->hash('x');
    }

    public function testRejectsAnUnrecognisedDigestFormat(): void
    {
        $hasher = $this->hasher([$this->ok('no-colons-here')]);

        $this->expectException(CryptoFailed::class);

        $hasher->hash('x');
    }

    public function testRejectsAMissingDigest(): void
    {
        $hasher = $this->hasher([new MockResponse('{"data":{}}', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ])]);

        $this->expectException(CryptoFailed::class);

        $hasher->hash('x');
    }

    /**
     * verify() 只为算 $input 的摘要打**一次** Vault，比较在本地做。
     * 用 Transit 的 verify 端点要多一次往返，换不到任何东西 ——
     * 摘要不是秘密，比较发生在哪一侧不影响安全性。
     */
    public function testVerifyHashesOnceAndComparesLocally(): void
    {
        $digest = random_bytes(32);
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode($digest))]);

        self::assertTrue($hasher->verify('anna@example.de', $digest));
        self::assertCount(1, $this->requests);
        self::assertStringContainsString('transit/hmac/', $this->requests[0]['url'], 'verify 不该打 transit/verify —— 那是多余的一次往返。');
    }

    public function testVerifyRejectsAMismatch(): void
    {
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode(random_bytes(32)))]);

        self::assertFalse($hasher->verify('anna@example.de', random_bytes(32)));
    }

    /**
     * 长度不同的摘要也必须安全地返回 false，而不是抛异常 ——
     * `hash_equals` 对不等长输入返回 false，这条用例把那个行为钉住。
     */
    public function testVerifyRejectsADigestOfADifferentLength(): void
    {
        $hasher = $this->hasher([$this->ok('vault:v1:'.base64_encode(random_bytes(32)))]);

        self::assertFalse($hasher->verify('anna@example.de', 'short'));
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    private function ok(string $hmac): MockResponse
    {
        return new MockResponse(json_encode(['data' => ['hmac' => $hmac]], \JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function hasher(array $responses): VaultHmacHasher
    {
        $this->requests = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            /** @var array{body?: string} $options */
            /** @var array<string, mixed> $body */
            $body = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];

            $this->requests[] = ['url' => $url, 'body' => $body];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        });

        return new VaultHmacHasher(new VaultClient($http, new StaticTokenProvider('dev-token'), 'http://vault.test:8200'));
    }
}
