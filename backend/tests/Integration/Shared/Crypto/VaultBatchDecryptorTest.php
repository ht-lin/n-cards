<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Crypto;

use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Crypto\VaultBatchDecryptor;
use App\Shared\Infrastructure\Crypto\VaultTransitCrypto;
use App\Shared\Infrastructure\Vault\VaultClient;
use App\Tests\Integration\Support\RequiresVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 真实 Vault 上的批量解密 + **基准测试**（T-005 验收标准第二条）。
 *
 * §9.1 的性能预算：`Vault batch decrypt（200 条）P95 ≤ 80 ms`（同主机容器内网）。
 */
#[CoversClass(VaultBatchDecryptor::class)]
#[CoversClass(VaultTransitCrypto::class)]
#[CoversClass(VaultClient::class)]
final class VaultBatchDecryptorTest extends TestCase
{
    use RequiresVault;
    /** §9.1 的目标值（毫秒）。达标与否只影响记录，不影响用例是否通过 —— 见下方说明。 */
    private const TARGET_P95_MS = 80.0;

    /**
     * 用例失败的门槛（毫秒），远高于 §9.1 的目标。
     *
     * ⚠️ 这里**刻意不**拿 80 ms 当断言值。开发机与 GitHub runner 的负载、
     * Docker 网络栈、是否有并发的其他用例都会让单次测量抖动几倍，
     * 而一条会随机变红的用例最终只会被人加上 `markTestSkipped`。
     *
     * 所以分工是：
     *   - **断言**一个宽松上限，抓的是「有人把实现改回循环」这种数量级的回归
     *     （200 次往返在任何环境下都会超）
     *   - **记录**真实耗时到 var/vault-benchmark.txt，由人在 PR 里看
     *     （§9.1 的达标判定属于 T-203 的压测，不是单元 CI 的事）
     */
    private const CEILING_MS = 2000.0;

    /** 采样次数。取 P95 需要足够样本，但也不能让 CI 等太久。 */
    private const SAMPLES = 20;

    private VaultClient $client;
    private VaultTransitCrypto $crypto;
    private VaultBatchDecryptor $decryptor;

    protected function setUp(): void
    {
        $this->client = $this->vaultClient();
        $this->assertVaultBootstrapped($this->client);
        $this->crypto = new VaultTransitCrypto($this->client);
        $this->decryptor = new VaultBatchDecryptor($this->client);
    }

    /**
     * 200 条批量解密的往返一致性 + 耗时记录。
     *
     * 200 是 §9.1 与 §5.3 反复用的那个数（钱包列表 50–200 张卡）。
     */
    public function testDecryptsTwoHundredCardsAndRecordsTheTiming(): void
    {
        $plaintexts = [];
        $ciphertexts = [];

        for ($i = 0; $i < 200; ++$i) {
            $cardId = \sprintf('card-%03d', $i);
            // 长度贴近真实条码（§17.1：< 100 字节的居多）。
            $plaintexts[$cardId] = \sprintf('40123456%05d', $i);
            $ciphertexts[$cardId] = $this->crypto->encrypt(CryptoKey::Card, $plaintexts[$cardId]);
        }

        // 先验正确性 —— 快而错没有意义。
        self::assertSame($plaintexts, $this->decryptor->decryptAll(CryptoKey::Card, $ciphertexts));

        $timings = [];

        for ($sample = 0; $sample < self::SAMPLES; ++$sample) {
            $startedAt = hrtime(true);
            $this->decryptor->decryptAll(CryptoKey::Card, $ciphertexts);
            $timings[] = (hrtime(true) - $startedAt) / 1_000_000;
        }

        sort($timings);
        $p95 = $timings[(int) ceil(0.95 * \count($timings)) - 1];
        $median = $timings[intdiv(\count($timings), 2)];

        $this->recordBenchmark($p95, $median, min($timings), max($timings));

        self::assertLessThan(
            self::CEILING_MS,
            $p95,
            \sprintf(
                "200 条 batch decrypt 的 P95 是 %.1f ms，超过了 %.0f ms 的兜底上限。\n".
                '最可能的原因是有人把 VaultBatchDecryptor 改回了「循环里逐条调 Vault」—— §5.3 明令禁止。',
                $p95,
                self::CEILING_MS,
            ),
        );
    }

    /**
     * §7.5 的「每用户卡数 500」是硬上限，也是 MAX_BATCH_SIZE 的取值依据：
     * 正常业务路径永远只发一次请求。这条验 500 条（恰好等于上限，不触发分块）
     * 与 501 条（触发分块）都能正确往返。
     */
    public function testHandlesTheMaximumCardsPerUser(): void
    {
        $plaintexts = [];
        $ciphertexts = [];

        // 501 = MAX_BATCH_SIZE + 1，跨过分块边界。
        for ($i = 0; $i <= VaultBatchDecryptor::MAX_BATCH_SIZE; ++$i) {
            $key = 'card-'.$i;
            $plaintexts[$key] = 'payload-'.$i;
            $ciphertexts[$key] = $this->crypto->encrypt(CryptoKey::Card, $plaintexts[$key]);
        }

        self::assertSame($plaintexts, $this->decryptor->decryptAll(CryptoKey::Card, $ciphertexts));
    }

    public function testEmptyBatchReturnsEmpty(): void
    {
        self::assertSame([], $this->decryptor->decryptAll(CryptoKey::Card, []));
    }

    /**
     * 批量路径也不能把两把 key 混起来用。
     */
    public function testBatchFailsWhenTheKeyDoesNotMatch(): void
    {
        $ciphertexts = ['a' => $this->crypto->encrypt(CryptoKey::Pii, 'anna@example.de')];

        $this->expectException(\App\Shared\Domain\Crypto\CryptoFailed::class);

        $this->decryptor->decryptAll(CryptoKey::Card, $ciphertexts);
    }

    /**
     * ⚠️ **不能用 echo 输出耗时**：phpunit.xml.dist 开了
     * `beStrictAboutOutputDuringTests="true"` 加 `failOnRisky="true"`，
     * 测试期间的任何输出都会让用例被判为 risky，进而失败。
     *
     * 所以写文件。var/ 已在根 .gitignore 里。
     */
    private function recordBenchmark(float $p95, float $median, float $min, float $max): void
    {
        $directory = \dirname(__DIR__, 4).'/var';

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return;
        }

        file_put_contents($directory.'/vault-benchmark.txt', \sprintf(
            "Vault batch decrypt —— 200 条（§9.1 目标：P95 <= %.0f ms）\n".
            "记录于 %s\n\n".
            "  样本数   %d\n".
            "  P95      %.2f ms   %s\n".
            "  中位数   %.2f ms\n".
            "  最快     %.2f ms\n".
            "  最慢     %.2f ms\n\n".
            "注：这是单机开发环境/CI runner 的测量值，不是 §9.1 的达标判定。\n".
            "§9.1 说的是「同主机容器内网」的生产形态，正式压测归 T-203。\n",
            self::TARGET_P95_MS,
            date('c'),
            self::SAMPLES,
            $p95,
            $p95 <= self::TARGET_P95_MS ? '✓ 达标' : '⚠ 高于 §9.1 目标，见上方注释',
            $median,
            $min,
            $max,
        ));
    }
}
