<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Crypto\VaultBatchDecryptor;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Shared\Infrastructure\Vault\VaultClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * §5.3 的硬要求：批量解密**一次请求解一批**，禁止在循环里逐条调 Vault。
 *
 * ⚠️ 本文件里最重要的一条是 {@see testDecryptsTwoHundredItemsInASingleRequest()} ——
 * 它是那条禁令在 CI 里的**唯一**强制点。谁把实现改回循环，那条用例立刻红。
 */
#[CoversClass(VaultBatchDecryptor::class)]
#[CoversClass(VaultClient::class)]
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(StaticTokenProvider::class)]
final class VaultBatchDecryptorTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $requestBodies = [];

    /**
     * §9.1 的性能预算就是按 200 条一次往返算的。逐条调的话是 200 次 HTTP 往返，
     * 会把 T-203 的 `GET /v1/sync/bootstrap` P95 ≤ 700 ms 预算吃掉大半。
     */
    public function testDecryptsTwoHundredItemsInASingleRequest(): void
    {
        $ciphertexts = [];
        $results = [];

        for ($i = 0; $i < 200; ++$i) {
            $ciphertexts['card-'.$i] = Ciphertext::fromString('vault:v1:'.base64_encode('cipher-'.$i));
            $results[] = ['plaintext' => base64_encode('plain-'.$i)];
        }

        $decryptor = $this->decryptor([$this->ok($results)]);

        $plaintexts = $decryptor->decryptAll(CryptoKey::Card, $ciphertexts);

        self::assertCount(
            1,
            $this->requestBodies,
            '200 条必须是**一次**请求。禁止在循环里逐条调 Vault —— §5.3 的硬要求，也是 §9.1 性能预算的前提。',
        );
        self::assertCount(200, $this->requestBodies[0]['batch_input']);
        self::assertCount(200, $plaintexts);
        self::assertSame('plain-0', $plaintexts['card-0']);
        self::assertSame('plain-199', $plaintexts['card-199']);
    }

    /**
     * 调用方传 `[cardId => Ciphertext]` 拿回 `[cardId => plaintext]`。
     *
     * 按下标对齐是这类批量 API 最经典的错位 bug 来源 —— 错位的后果是
     * A 的卡号显示成了 B 的，而那是一次数据泄露，不是一个显示 bug。
     */
    public function testPreservesTheCallersKeys(): void
    {
        $decryptor = $this->decryptor([
            $this->ok([
                ['plaintext' => base64_encode('anna')],
                ['plaintext' => base64_encode('bob')],
                ['plaintext' => base64_encode('chris')],
            ]),
        ]);

        $plaintexts = $decryptor->decryptAll(CryptoKey::Card, [
            'anna-card' => Ciphertext::fromString('vault:v1:YQ=='),
            'bob-card' => Ciphertext::fromString('vault:v1:Yg=='),
            'chris-card' => Ciphertext::fromString('vault:v1:Yw=='),
        ]);

        self::assertSame(['anna-card' => 'anna', 'bob-card' => 'bob', 'chris-card' => 'chris'], $plaintexts);
    }

    /**
     * 非连续、非零起始的整数键同样要还原对 —— 这是 array_keys/array_values
     * 那对「同一次遍历的两半」在实现里真正被依赖的地方。
     */
    public function testPreservesSparseIntegerKeys(): void
    {
        $decryptor = $this->decryptor([
            $this->ok([
                ['plaintext' => base64_encode('first')],
                ['plaintext' => base64_encode('second')],
            ]),
        ]);

        $plaintexts = $decryptor->decryptAll(CryptoKey::Card, [
            7 => Ciphertext::fromString('vault:v1:YQ=='),
            42 => Ciphertext::fromString('vault:v1:Yg=='),
        ]);

        self::assertSame([7 => 'first', 42 => 'second'], $plaintexts);
    }

    /**
     * 空批次不打 Vault。看着像微优化，实则必需：Transit 对空的 batch_input 回 400，
     * 于是「用户还没有任何卡」会变成一个 500。
     */
    public function testEmptyBatchDoesNotCallVault(): void
    {
        $decryptor = $this->decryptor([]);

        self::assertSame([], $decryptor->decryptAll(CryptoKey::Card, []));
        self::assertSame([], $this->requestBodies);
    }

    /**
     * 单条失败 → 整批抛。理由见 BatchDecryptorInterface 的类注释：
     * 返回一个比入参短的数组，调用方几乎必然不会比对长度，
     * 于是钱包列表会静默少一张卡 —— 用户看到的是「我的卡不见了」。
     */
    public function testAnyItemErrorFailsTheWholeBatch(): void
    {
        $decryptor = $this->decryptor([
            $this->ok([
                ['plaintext' => base64_encode('fine')],
                ['error' => 'invalid ciphertext: unable to decrypt'],
                ['plaintext' => base64_encode('also fine')],
            ]),
        ]);

        $this->expectException(CryptoFailed::class);

        $decryptor->decryptAll(CryptoKey::Card, [
            'a' => Ciphertext::fromString('vault:v1:YQ=='),
            'b' => Ciphertext::fromString('vault:v1:Yg=='),
            'c' => Ciphertext::fromString('vault:v1:Yw=='),
        ]);
    }

    /**
     * ⚠️ 失败消息不能泄露是**哪一条**失败了 —— 下标能反查到 cardId，
     * 而 detail 会进日志。要定位就去查那一批的 cardId 集合。
     */
    public function testItemErrorDoesNotLeakTheFailingIndexOrVaultMessage(): void
    {
        $decryptor = $this->decryptor([
            $this->ok([
                ['plaintext' => base64_encode('fine')],
                ['error' => 'invalid ciphertext for card 42'],
            ]),
        ]);

        try {
            $decryptor->decryptAll(CryptoKey::Card, [
                'a' => Ciphertext::fromString('vault:v1:YQ=='),
                'b' => Ciphertext::fromString('vault:v1:Yg=='),
            ]);
            self::fail('应当抛出 CryptoFailed。');
        } catch (CryptoFailed $e) {
            self::assertStringNotContainsString('42', $e->getMessage());
            self::assertStringNotContainsString('invalid ciphertext', $e->getMessage());
        }
    }

    /**
     * 结果条数与输入对不上 = 协议假设塌了。此时**绝不能**继续按位置还原 ——
     * 那会把 A 的卡号安到 B 头上。整批失败是唯一安全的选择。
     */
    public function testMismatchedResultCountFailsTheBatch(): void
    {
        $decryptor = $this->decryptor([
            $this->ok([['plaintext' => base64_encode('only one')]]),
        ]);

        $this->expectException(CryptoFailed::class);

        $decryptor->decryptAll(CryptoKey::Card, [
            'a' => Ciphertext::fromString('vault:v1:YQ=='),
            'b' => Ciphertext::fromString('vault:v1:Yg=='),
        ]);
    }

    public function testMissingBatchResultsFailsTheBatch(): void
    {
        $decryptor = $this->decryptor([$this->ok(null)]);

        $this->expectException(CryptoFailed::class);

        $decryptor->decryptAll(CryptoKey::Card, ['a' => Ciphertext::fromString('vault:v1:YQ==')]);
    }

    /**
     * 超过 MAX_BATCH_SIZE 才分块。上限取 §7.5 的「每用户卡数 500」——
     * 正常业务路径一次也不会超过它，于是钱包列表恒为一次往返。
     */
    public function testChunksBeyondTheBatchLimit(): void
    {
        $total = VaultBatchDecryptor::MAX_BATCH_SIZE + 10;

        $ciphertexts = [];
        $firstChunk = [];
        $secondChunk = [];

        for ($i = 0; $i < $total; ++$i) {
            $ciphertexts['k'.$i] = Ciphertext::fromString('vault:v1:'.base64_encode('c'.$i));

            $entry = ['plaintext' => base64_encode('p'.$i)];

            if ($i < VaultBatchDecryptor::MAX_BATCH_SIZE) {
                $firstChunk[] = $entry;
            } else {
                $secondChunk[] = $entry;
            }
        }

        $decryptor = $this->decryptor([$this->ok($firstChunk), $this->ok($secondChunk)]);

        $plaintexts = $decryptor->decryptAll(CryptoKey::Card, $ciphertexts);

        self::assertCount(2, $this->requestBodies);
        self::assertCount(VaultBatchDecryptor::MAX_BATCH_SIZE, $this->requestBodies[0]['batch_input']);
        self::assertCount(10, $this->requestBodies[1]['batch_input']);
        self::assertCount($total, $plaintexts);
        // 分块之后键仍然要对得上 —— 这是分块实现最容易错的地方。
        self::assertSame('p0', $plaintexts['k0']);
        self::assertSame('p'.($total - 1), $plaintexts['k'.($total - 1)]);
    }

    public function testSendsBatchInputToTheRightKeyPath(): void
    {
        $decryptor = $this->decryptor([$this->ok([['plaintext' => base64_encode('x')]])]);

        $decryptor->decryptAll(CryptoKey::Pii, ['a' => Ciphertext::fromString('vault:v1:YQ==')]);

        self::assertSame([['ciphertext' => 'vault:v1:YQ==']], $this->requestBodies[0]['batch_input']);
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    /**
     * @param list<array<string, mixed>>|null $batchResults
     */
    private function ok(?array $batchResults): MockResponse
    {
        $data = null === $batchResults ? [] : ['batch_results' => $batchResults];

        return new MockResponse(json_encode(['data' => $data], \JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function decryptor(array $responses): VaultBatchDecryptor
    {
        $this->requestBodies = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            /** @var array{body?: string} $options */
            /** @var array<string, mixed> $body */
            $body = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];

            $this->requestBodies[] = $body;

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 200]);
        });

        return new VaultBatchDecryptor(new VaultClient($http, new StaticTokenProvider('dev-token'), 'http://vault.test:8200'));
    }
}
