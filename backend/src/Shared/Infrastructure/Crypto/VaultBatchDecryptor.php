<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Crypto;

use App\Shared\Application\Crypto\BatchDecryptorInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Vault\VaultClient;

/**
 * §5.3 的批量解密：Transit `decrypt` 的 `batch_input`。
 *
 * ============================================================================
 * 「一次请求解一批」的实现要点
 * ============================================================================
 * Vault 的批量协议是**按下标对齐**的：`batch_input` 是一个数组，
 * `batch_results` 是等长的数组，第 i 个结果对应第 i 个输入，
 * 失败的条目在自己的位置上带一个 `error` 字段（**整个请求仍然是 HTTP 200**）。
 *
 * 所以这里要做三件事，缺一不可：
 *   1. 把调用方的键（通常是 cardId）记下来，按位置还原回去；
 *   2. 校验 `batch_results` 与输入**等长** —— 不等长说明协议假设塌了，
 *      此时按位置还原会把 A 的卡号安到 B 头上，必须整批失败而不是继续；
 *   3. 任一条目带 `error` 就整批抛（理由见 BatchDecryptorInterface 的类注释）。
 *
 * ============================================================================
 * 为什么还要分块
 * ============================================================================
 * §9.1 的预算是 200 条，而 §7.5 的「每用户卡数 500」是硬上限，
 * 所以单次调用最多可能来 500 条。Vault 对 batch 大小没有硬限制，但请求体会随之涨
 * （500 × 约 100 字节密文 ≈ 50 KB），而超时是按**整个请求**算的
 * （`VaultClient::MAX_DURATION_SECONDS`）。分块让单次请求的耗时有上界。
 *
 * 500 这个值是「§7.5 的单用户上限」，也就是说正常路径上**永远只发一次请求** ——
 * 分块是为异常规模兜底的，不是常态。
 */
final readonly class VaultBatchDecryptor implements BatchDecryptorInterface
{
    /**
     * 单次请求最多解多少条。
     *
     * 与 §7.5 的「每用户卡数 500」对齐：正常业务路径一次也不会超过它，
     * 于是钱包列表恒为一次往返。
     */
    public const MAX_BATCH_SIZE = 500;

    public function __construct(private VaultClient $vault)
    {
    }

    public function decryptAll(CryptoKey $key, array $ciphertexts): array
    {
        if ([] === $ciphertexts) {
            // 空批次不打 Vault。看着像微优化，实则是必需的：Transit 对空的
            // batch_input 会返回 400，于是「用户还没有任何卡」会变成一个 500。
            return [];
        }

        $plaintexts = [];

        foreach (array_chunk($ciphertexts, self::MAX_BATCH_SIZE, true) as $chunk) {
            foreach ($this->decryptChunk($key, $chunk) as $originalKey => $plaintext) {
                $plaintexts[$originalKey] = $plaintext;
            }
        }

        return $plaintexts;
    }

    /**
     * @param array<array-key, Ciphertext> $chunk 非空，且不超过 MAX_BATCH_SIZE
     *
     * @return array<array-key, string>
     *
     * @throws CryptoFailed
     */
    private function decryptChunk(CryptoKey $key, array $chunk): array
    {
        // array_keys 与 array_values 是同一次遍历的两半，顺序天然一致 ——
        // 这就是第 1 点「按位置还原」的依据。
        $originalKeys = array_keys($chunk);

        $batchInput = array_map(
            static fn (Ciphertext $ciphertext): array => ['ciphertext' => $ciphertext->toString()],
            array_values($chunk),
        );

        $data = $this->vault->write('transit/decrypt/'.$key->keyName(), [
            'batch_input' => $batchInput,
        ]);

        $results = $data['batch_results'] ?? null;

        if (!\is_array($results) || \count($results) !== \count($originalKeys)) {
            // 第 2 点。宁可整批失败，也不能按错位的下标还原。
            throw new CryptoFailed('Vault batch decrypt returned a mismatched result set.');
        }

        $plaintexts = [];

        foreach (array_values($results) as $index => $result) {
            $plaintexts[$originalKeys[$index]] = self::plaintextOf($result);
        }

        return $plaintexts;
    }

    /**
     * @param mixed $result `batch_results` 里的一项
     *
     * @throws CryptoFailed
     */
    private static function plaintextOf(mixed $result): string
    {
        if (!\is_array($result)) {
            throw new CryptoFailed('Vault batch decrypt returned a malformed result entry.');
        }

        // 第 3 点。⚠️ 这里**只**说「有一条失败了」，绝不把 Vault 的 error 文本
        // 或条目下标拼进 detail —— detail 会进日志，而下标能反查到是哪张卡。
        // 真要定位是哪一条，去查那一批的 cardId 集合，不要从错误消息里泄出来。
        if (isset($result['error'])) {
            throw new CryptoFailed('Vault failed to decrypt at least one item in the batch.');
        }

        $plaintext = $result['plaintext'] ?? null;

        if (!\is_string($plaintext)) {
            throw new CryptoFailed('Vault batch decrypt returned an item without plaintext.');
        }

        return VaultTransitCrypto::decodeBase64($plaintext);
    }
}
