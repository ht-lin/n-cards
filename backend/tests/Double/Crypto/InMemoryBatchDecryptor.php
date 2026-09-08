<?php

declare(strict_types=1);

namespace App\Tests\Double\Crypto;

use App\Shared\Application\Crypto\BatchDecryptorInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * 进程内的批量解密，**并记下调用次数**。
 *
 * ============================================================================
 * 调用次数就是被测的东西
 * ============================================================================
 * §5.3 的硬要求是「一次请求解一批，禁止在循环里逐条调 Vault」，而那条要求
 * 在单元测试里唯一能被观察到的形式就是 `decryptAll()` 被调了几次。
 * {@see calls()} 让「列出 N 张卡只发一次解密」变成一条可断言的事实 ——
 * 口径同 `VaultBatchDecryptorTest::testChunksBeyondTheBatchLimit()`
 * 与 {@see RecordingHmacHasher} 记 Vault 往返。
 *
 * 密文格式与 {@see InMemoryCryptoService} 完全一致（同一个 `fake:` 包装 +
 * 把 key 烤进密文），所以两个替身可以在同一条用例里配合：
 * 一个负责加密、一个负责解密，而用错 key 会在解密侧炸出来。
 */
final class InMemoryBatchDecryptor implements BatchDecryptorInterface
{
    /** 与 {@see InMemoryCryptoService} 的同名常量必须一致。 */
    private const MARKER = 'fake';

    private int $calls = 0;

    /** @var list<int> 每次调用的批次大小 */
    private array $batchSizes = [];

    private ?CryptoUnavailable $unavailable = null;

    private ?CryptoFailed $failure = null;

    /**
     * 让下一次调用抛 `CryptoUnavailable`（→ 503）。
     */
    public function unavailable(): void
    {
        $this->unavailable = new CryptoUnavailable('Vault is sealed (test double).');
    }

    /**
     * 让下一次调用整批抛 `CryptoFailed`（→ 500）。
     *
     * ⚠️ 替身**不提供**「只失败其中一条」的模式，因为接口本身不允许部分结果：
     * `BatchDecryptorInterface` 的类注释论证过，返回一个比入参短的数组
     * 会变成一次静默的数据丢失。能造出来的失败只有整批失败。
     */
    public function failWith(CryptoFailed $failure): void
    {
        $this->failure = $failure;
    }

    public function decryptAll(CryptoKey $key, array $ciphertexts): array
    {
        // 空批次不算一次调用，也不打 Vault —— 接口约定如此。
        if ([] === $ciphertexts) {
            return [];
        }

        ++$this->calls;
        $this->batchSizes[] = \count($ciphertexts);

        if (null !== $this->unavailable) {
            throw $this->unavailable;
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $plaintexts = [];

        foreach ($ciphertexts as $handle => $ciphertext) {
            $plaintexts[$handle] = self::unwrap($key, $ciphertext);
        }

        // 与入参**同键、同顺序** —— 接口逐字要求这一条，而按下标对齐是
        // 这类批量 API 最经典的错位 bug（A 的卡号显示成 B 的）。
        return $plaintexts;
    }

    /**
     * `decryptAll()` 被调了几次。**列表端点上它必须是 1**，与卡片数无关。
     */
    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * @return list<int>
     */
    public function batchSizes(): array
    {
        return $this->batchSizes;
    }

    /**
     * ⚠️ 前缀必须与 {@see InMemoryCryptoService::encrypt()} 生成的**逐字相同** ——
     * 两个替身经常在同一条用例里配合（一个加密、一个解密）。
     * 那边改了格式而这里没跟上的话，症状是一堆看不懂的 `CryptoFailed`。
     */
    private static function unwrap(CryptoKey $key, Ciphertext $ciphertext): string
    {
        $expectedPrefix = \sprintf('vault:v1:%s.%s.', self::MARKER, $key->value);
        $value = $ciphertext->toString();

        if (!str_starts_with($value, $expectedPrefix)) {
            // 用错 key 时必须炸，不能给出一个看起来正常的明文 ——
            // 否则「卡片用 PII 的 key 解开了」这种 bug 会一路绿到生产。
            throw new CryptoFailed('Ciphertext was not produced by the paired double, or the key does not match.');
        }

        $decoded = base64_decode(substr($value, \strlen($expectedPrefix)), true);

        if (false === $decoded) {
            throw new CryptoFailed('Malformed payload in test double ciphertext.');
        }

        return $decoded;
    }
}
