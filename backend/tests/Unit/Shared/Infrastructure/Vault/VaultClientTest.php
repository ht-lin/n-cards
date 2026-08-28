<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * VaultClient 的**响应解码**分支。
 *
 * 上层的 VaultTransitCrypto / VaultHmacHasher / VaultBatchDecryptor 三个测试
 * 都顺带覆盖了 VaultClient，但它们打的全是 transit 那几个「200 + 有 data」的端点。
 * 这里补的是那三个测试碰不到、而 T-404 与运维路径一进来就会碰到的形态：
 * 204 空 body、非 JSON 的 body、以及 JSON 顶层不是对象。
 *
 * @see VaultClient::decode() 里的注释
 */
#[CoversClass(VaultClient::class)]
final class VaultClientTest extends TestCase
{
    /**
     * ⚠️ 回归守卫：**204 No Content 不能变成 500**。
     *
     * Vault 的写入端点有一大类回 204、body 是空串，而 `json_decode('')` 抛
     * JsonException —— 那不是 DomainException，会绕过本类全部的错误映射，
     * 一路冒到 ApiProblemExceptionListener 之外，被记成 CRITICAL 的 500。
     *
     * 实测（Vault 1.18）回 204 的端点：`transit/keys/<name>/config`、
     * `auth/approle/role/<name>`、`sys/policies/acl/<name>`。
     * 第一个就是 T-404 轮换要写 min_decryption_version 的那条。
     */
    public function testNoContentDecodesToAnEmptyArray(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 204])]);

        self::assertSame([], $client->write('transit/keys/ncards-card/config', ['min_decryption_version' => 2]));
    }

    /**
     * 200 但 body 是空的（Vault 不这么回，反向代理会）—— 同样不该抛 JsonException。
     */
    public function testAnEmptyBodyOnA200AlsoDecodesToAnEmptyArray(): void
    {
        $client = $this->client([new MockResponse('   ', ['http_code' => 200])]);

        self::assertSame([], $client->read('transit/keys/ncards-card'));
    }

    /**
     * 2xx 但 body 不是 JSON —— 现实里是中间挡了个反向代理/门户，回的是 HTML。
     * 必须落在 CryptoFailed 这套映射里，而不是让 JsonException 裸奔出去。
     */
    public function testANonJsonBodyBecomesCryptoFailed(): void
    {
        $client = $this->client([new MockResponse('<html><body>502 Bad Gateway</body></html>', ['http_code' => 200])]);

        try {
            $client->read('transit/keys/ncards-card');
            self::fail('应当抛出 CryptoFailed。');
        } catch (CryptoFailed $e) {
            // CryptoFailed 是 DomainException，所以 ApiProblemExceptionListener 认得它；
            // 裸的 JsonException 不是，那正是当初会变成 CRITICAL 500 的原因。
            // 原始异常只挂在 previous 上，进日志、不进响应体。
            self::assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    /**
     * ⚠️ 响应体在 decrypt 路径上**就是明文**，绝不能进异常消息（见类注释第一段）。
     */
    public function testANonJsonBodyNeverLeaksIntoTheMessage(): void
    {
        $client = $this->client([new MockResponse('not json: c2VjcmV0LWNhcmQtbnVtYmVy', ['http_code' => 200])]);

        try {
            $client->read('transit/decrypt/ncards-card');
            self::fail('应当抛出 CryptoFailed。');
        } catch (CryptoFailed $e) {
            self::assertStringNotContainsString('c2VjcmV0LWNhcmQtbnVtYmVy', $e->getMessage());
            self::assertStringContainsString('transit/decrypt/ncards-card', $e->getMessage());
        }
    }

    /**
     * 合法 JSON，但顶层是 `null` 或标量。
     * 老代码在这里会对一个 null / string 做数组下标访问 —— PHP 只发个 warning，
     * 表达式求值成 null，`?? []` 再把它兜成空数组，于是调用方拿不到 ciphertext
     * 却以为成功了。宁可抛。
     *
     * （顶层是 JSON **数组** 的情况不在这里：`json_decode('{}', true)` 出的也是
     * PHP 空数组，两者在解码后分不开，所以 `[1,2,3]` 只能和 `{}` 一样退化成 []。
     * Vault 不会那么回，这个缺口没有实际入口。）
     */
    public function testANonObjectJsonBodyBecomesCryptoFailed(): void
    {
        foreach (['null', '"just a string"', '42'] as $body) {
            $client = $this->client([new MockResponse($body, ['http_code' => 200])]);

            try {
                $client->read('transit/keys/ncards-card');
                self::fail(\sprintf('body 为 %s 时应当抛出 CryptoFailed。', $body));
            } catch (CryptoFailed $e) {
                self::assertStringContainsString('malformed', $e->getMessage());
            }
        }
    }

    /**
     * `data` 存在但不是对象 —— 已有的分支，一并钉住，免得上面的重构把它推翻。
     */
    public function testANonObjectDataBecomesCryptoFailed(): void
    {
        $client = $this->client([new MockResponse('{"data":"nope"}', ['http_code' => 200])]);

        $this->expectException(CryptoFailed::class);

        $client->read('transit/keys/ncards-card');
    }

    public function testAMissingDataObjectDecodesToAnEmptyArray(): void
    {
        $client = $this->client([new MockResponse('{"request_id":"abc","warnings":null}', ['http_code' => 200])]);

        self::assertSame([], $client->read('transit/keys/ncards-card'));
    }

    /**
     * ⚠️ 空 body 的放行只对 2xx 生效。5xx 配空 body（网关超时的典型形态）
     * 仍然必须是 CryptoUnavailable，不能因为「body 是空的」就当成功返回 []。
     */
    public function testAnEmptyBodyOnAnErrorStatusStillFails(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 503])]);

        $this->expectException(CryptoUnavailable::class);

        $client->read('transit/keys/ncards-card');
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): VaultClient
    {
        $http = new MockHttpClient(
            static function () use (&$responses): MockResponse {
                return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
            },
        );

        return new VaultClient($http, new StaticTokenProvider('dev-token'), 'http://vault.test:8200');
    }
}
