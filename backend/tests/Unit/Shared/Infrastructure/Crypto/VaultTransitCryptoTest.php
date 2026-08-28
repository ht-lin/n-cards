<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * 单条加解密的**请求形状**与**错误映射**。
 *
 * 这些用例用 MockHttpClient，不需要真 Vault —— 它们验的是「我们怎么跟 Vault 说话」
 * 以及「Vault 说不时我们怎么翻译」。往返一致性由
 * tests/Integration/Shared/Crypto/VaultTransitCryptoTest 在真容器上验。
 */
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(VaultClient::class)]
#[CoversClass(StaticTokenProvider::class)]
final class VaultTransitCryptoTest extends TestCase
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>, raw: string, token: string}> */
    private array $requests = [];

    public function testEncryptSendsBase64AndReturnsTheCiphertext(): void
    {
        $crypto = $this->crypto([
            $this->ok(['ciphertext' => 'vault:v1:ZW5jcnlwdGVk']),
        ]);

        $ciphertext = $crypto->encrypt(CryptoKey::Card, '4012345678901');

        self::assertSame('vault:v1:ZW5jcnlwdGVk', $ciphertext->toString());

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('http://vault.test:8200/v1/transit/encrypt/ncards-card', $this->requests[0]['url']);
        self::assertSame('dev-token', $this->requests[0]['token']);

        // 载荷必须是 base64 —— 不是为了保密（base64 不提供保密性），
        // 而是因为条码 payload 可能含任意字节，直接塞进 JSON 会被拒或损坏。
        self::assertSame(base64_encode('4012345678901'), $this->requests[0]['body']['plaintext']);
    }

    /**
     * 二进制安全：§17.1 允许 1024 字节的 barcode payload，其中可能有 NUL 与
     * 非 UTF-8 字节。这条用例是「为什么要 base64」的可执行说明。
     */
    public function testEncryptIsBinarySafe(): void
    {
        $binary = "\x00\xff\xfe\x01 not utf-8 \x80";

        $crypto = $this->crypto([$this->ok(['ciphertext' => 'vault:v1:eA=='])]);
        $crypto->encrypt(CryptoKey::Card, $binary);

        self::assertSame($binary, base64_decode($this->requests[0]['body']['plaintext'], true));
    }

    public function testDecryptSendsTheCiphertextAndDecodesTheResult(): void
    {
        $crypto = $this->crypto([
            $this->ok(['plaintext' => base64_encode('4012345678901')]),
        ]);

        $plaintext = $crypto->decrypt(CryptoKey::Pii, Ciphertext::fromString('vault:v1:ZW5jcnlwdGVk'));

        self::assertSame('4012345678901', $plaintext);
        self::assertSame('http://vault.test:8200/v1/transit/decrypt/ncards-pii', $this->requests[0]['url']);
        self::assertSame('vault:v1:ZW5jcnlwdGVk', $this->requests[0]['body']['ciphertext']);
    }

    /**
     * Vault 回来的东西理应永远是合法密文。过一遍值对象的校验花不了什么，
     * 挡住的却是最坏情况：响应结构变了，我们把一段非密文写进了 _encrypted 列。
     */
    public function testEncryptRejectsAResponseThatIsNotACiphertext(): void
    {
        $crypto = $this->crypto([$this->ok(['ciphertext' => 'not-a-ciphertext'])]);

        $this->expectException(CryptoFailed::class);

        $crypto->encrypt(CryptoKey::Card, 'x');
    }

    public function testDecryptRejectsMalformedBase64(): void
    {
        // strict 模式的 base64_decode 返回 false，而不是静默跳过坏字符 ——
        // 静默跳过会产出一个「长度不对但看起来正常」的卡号，一路写进客户端数据库，
        // 直到收银台扫不出来才被发现。
        $crypto = $this->crypto([$this->ok(['plaintext' => '!!!not base64!!!'])]);

        $this->expectException(CryptoFailed::class);

        $crypto->decrypt(CryptoKey::Card, Ciphertext::fromString('vault:v1:x'));
    }

    public function testMissingFieldsAreRejected(): void
    {
        $crypto = $this->crypto([$this->ok(['unexpected' => 'shape'])]);

        $this->expectException(CryptoFailed::class);

        $crypto->encrypt(CryptoKey::Card, 'x');
    }

    // ========================================================================
    // 错误映射 —— 「什么算 503（可重试、不惊动告警），什么算 500（我们的 bug）」
    // ========================================================================

    /**
     * 503 封印与 501 未初始化都是「等人处理」，是生产每次重启后的正常中间态（Q6）。
     * 归 500 的话，§14.4 的 5xx 告警会在每次计划内的 unseal 窗口里误报。
     */
    #[DataProvider('unavailableStatuses')]
    public function testInfrastructureFailuresBecomeServiceUnavailable(int $status): void
    {
        $crypto = $this->crypto([new MockResponse('{"errors":["boom"]}', ['http_code' => $status])]);

        $this->expectException(CryptoUnavailable::class);

        $crypto->encrypt(CryptoKey::Card, 'x');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unavailableStatuses(): iterable
    {
        yield '封印' => [503];
        yield '未初始化' => [501];
        yield '内部错误' => [500];
        yield '网关错误' => [502];
    }

    /**
     * 400（密文损坏、参数不合法）与 404（key 不存在）是我们自己的 bug，
     * 重试没有意义 —— 500 而不是 503。
     */
    #[DataProvider('failedStatuses')]
    public function testClientErrorsBecomeCryptoFailed(int $status): void
    {
        $crypto = $this->crypto([new MockResponse('{"errors":["bad"]}', ['http_code' => $status])]);

        try {
            $crypto->encrypt(CryptoKey::Card, 'x');
            self::fail('应当抛出 CryptoFailed。');
        } catch (CryptoFailed $e) {
            self::assertNotInstanceOf(CryptoUnavailable::class, $e, \sprintf('HTTP %d 是我们的 bug，重试无用，不该归 503。', $status));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function failedStatuses(): iterable
    {
        yield '参数不合法' => [400];
        yield 'key 不存在' => [404];
        yield '方法不允许' => [405];
    }

    /**
     * 连不上 Vault —— fail-closed，且是可重试的 503。
     */
    public function testTransportFailureBecomesServiceUnavailable(): void
    {
        $crypto = $this->crypto([new MockResponse('', ['error' => 'connection refused'])]);

        $this->expectException(CryptoUnavailable::class);

        $crypto->encrypt(CryptoKey::Card, 'x');
    }

    /**
     * ⚠️ 明文绝不能出现在异常消息里 —— detail 会进日志与 Sentry，而 §8 的 ROPA
     * 没有把日志算作存储卡号的地方。这条用例覆盖加解密两侧的失败路径。
     */
    public function testFailuresNeverLeakPlaintext(): void
    {
        $plaintext = '4012345678901';

        $crypto = $this->crypto([new MockResponse('{"errors":["boom"]}', ['http_code' => 500])]);

        try {
            $crypto->encrypt(CryptoKey::Card, $plaintext);
            self::fail('应当抛出。');
        } catch (CryptoFailed $e) {
            // 整条 previous 链一起查：HttpClient 的异常消息里会带响应体片段。
            for ($e2 = $e; null !== $e2; $e2 = $e2->getPrevious()) {
                self::assertStringNotContainsString($plaintext, $e2->getMessage());
                self::assertStringNotContainsString(base64_encode($plaintext), $e2->getMessage());
            }
        }
    }

    /**
     * 403 先当作「token 过期」重试一次（生产常态：token TTL 1h）。
     * 第二次仍然 403 才是真的权限问题 —— 那时归 503，因为应用侧没 bug，
     * 是 Vault 的 policy 要修，而且修好即恢复。
     */
    public function testForbiddenIsRetriedOnceThenSurfacedAsUnavailable(): void
    {
        $crypto = $this->crypto([
            new MockResponse('{"errors":["permission denied"]}', ['http_code' => 403]),
            new MockResponse('{"errors":["permission denied"]}', ['http_code' => 403]),
        ]);

        try {
            $crypto->encrypt(CryptoKey::Card, 'x');
            self::fail('应当抛出 CryptoUnavailable。');
        } catch (CryptoUnavailable $e) {
            self::assertCount(2, $this->requests, '403 必须重试恰好一次 —— 不重试会让每小时的 token 过期变成一次用户可见的失败。');
            // 消息要指向 policy，因为重登之后仍然 403 的唯一剩余原因就是权限。
            self::assertStringContainsString('policy', $e->getMessage());
        }
    }

    /**
     * ⚠️ 回归守卫，与 AppRoleTokenProviderTest 里那条同源。
     *
     * 不带参数的端点（`auth/token/renew-self`、`transit/keys/<name>/rotate`、
     * `auth/approle/role/<name>/secret-id`）请求体必须是 `{}`；PHP 的
     * `json_encode([])` 出 `[]`，Vault 对它回 **400**。
     * 完整说明见 VaultClient::jsonBody() 的注释。
     *
     * 断言原始字符串 —— `{}` 与 `[]` json_decode 之后在 PHP 里都是空数组，
     * 分辨不出来，那正是当初没被发现的原因。
     */
    public function testEmptyPayloadIsSentAsAnObjectNotAnArray(): void
    {
        $client = $this->client([$this->ok([])]);

        $client->write('auth/token/renew-self', []);

        self::assertSame('{}', $this->requests[0]['raw'], 'Vault 对 `[]` 回 400。');
    }

    /**
     * GET 不带请求体 —— 别顺手给它塞一个 `{}`。
     */
    public function testReadSendsNoBody(): void
    {
        $client = $this->client([$this->ok(['role_id' => 'r'])]);

        self::assertSame(['role_id' => 'r'], $client->read('auth/approle/role/ncards-app/role-id'));
        self::assertSame('GET', $this->requests[0]['method']);
        self::assertSame('', $this->requests[0]['raw']);
    }

    public function testForbiddenThenSuccessRecovers(): void
    {
        $crypto = $this->crypto([
            new MockResponse('{"errors":["permission denied"]}', ['http_code' => 403]),
            $this->ok(['ciphertext' => 'vault:v1:eA==']),
        ]);

        self::assertSame('vault:v1:eA==', $crypto->encrypt(CryptoKey::Card, 'x')->toString());
        self::assertCount(2, $this->requests);
    }

    /**
     * 5xx **不**重试：那是 Vault 自己有问题，立刻 fail-closed 交给调用方
     * 比在这里堆重试更有用（§9.4）。
     */
    public function testServerErrorsAreNotRetried(): void
    {
        $crypto = $this->crypto([
            new MockResponse('{"errors":["boom"]}', ['http_code' => 500]),
            $this->ok(['ciphertext' => 'vault:v1:eA==']),
        ]);

        try {
            $crypto->encrypt(CryptoKey::Card, 'x');
        } catch (CryptoUnavailable) {
        }

        self::assertCount(1, $this->requests);
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): MockResponse
    {
        return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function crypto(array $responses): VaultTransitCrypto
    {
        return new VaultTransitCrypto($this->client($responses));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): VaultClient
    {
        $this->requests = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            /** @var array{headers?: list<string>, body?: string} $options */
            $token = '';

            foreach ($options['headers'] ?? [] as $header) {
                if (str_starts_with(strtolower($header), 'x-vault-token:')) {
                    $token = trim(substr($header, \strlen('x-vault-token:')));
                }
            }

            $raw = (string) ($options['body'] ?? '');

            /** @var array<string, mixed> $body */
            $body = json_decode('' === $raw ? '{}' : $raw, true) ?? [];

            $this->requests[] = ['method' => $method, 'url' => $url, 'body' => $body, 'raw' => $raw, 'token' => $token];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        });

        return new VaultClient($http, new StaticTokenProvider('dev-token'), 'http://vault.test:8200');
    }
}
