<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Crypto;

use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Vault\VaultClient;

/**
 * §5.3 信封加密的实现：Vault Transit 的 `encrypt` / `decrypt`。
 *
 * ```
 * 明文 ──► base64 ──► transit/encrypt/ncards-card ──► "vault:v1:BASE64" ──► Postgres TEXT
 * ```
 *
 * ============================================================================
 * 为什么载荷要 base64
 * ============================================================================
 * 不是为了「加密」—— base64 不提供任何保密性。是因为 Transit 的 API 是 JSON，
 * 而 JSON 字符串必须是合法 UTF-8。条码 payload 可能含任意字节（§17.1 允许 1024 字节的
 * 二进制），直接塞进 JSON 会被 `json_encode` 拒绝或悄悄损坏。
 * base64 让整条路径**二进制安全**。Vault 侧也一样：解密结果同样是 base64 回来的。
 *
 * ============================================================================
 * 一次只解一条 —— 批量请用 VaultBatchDecryptor
 * ============================================================================
 * §5.3 明令禁止在循环里调本类的 `decrypt()`。
 * 那条禁令的强制点在 {@see VaultBatchDecryptor} 的单测里。
 *
 * ============================================================================
 * 不缓存明文（§5.3）
 * ============================================================================
 * 本类**没有**任何缓存，这是刻意的。若将来压测不达标，§5.3 只允许「单个 HTTP 请求
 * 生命周期内的进程内 memo，请求结束即销毁」—— 注意本类是容器里的**单例**，
 * 而 FrankenPHP 的 worker 是长驻的，所以一个朴素的 `private array $memo` 会**跨请求**
 * 存活，那正是 §5.3 禁止的东西。真要加，得挂在请求作用域的服务上。
 */
final readonly class VaultTransitCrypto implements CryptoServiceInterface
{
    public function __construct(private VaultClient $vault)
    {
    }

    public function encrypt(CryptoKey $key, string $plaintext): Ciphertext
    {
        $data = $this->vault->write('transit/encrypt/'.$key->keyName(), [
            'plaintext' => base64_encode($plaintext),
        ]);

        $ciphertext = $data['ciphertext'] ?? null;

        if (!\is_string($ciphertext)) {
            throw new CryptoFailed('Vault encrypt returned no ciphertext.');
        }

        // 过一遍值对象的校验：Vault 回来的东西理应永远合法，但这一层花不了什么，
        // 而它挡住的是「响应结构变了、我们把一段非密文写进了 _encrypted 列」这种最坏情况。
        return Ciphertext::fromString($ciphertext);
    }

    public function decrypt(CryptoKey $key, Ciphertext $ciphertext): string
    {
        $data = $this->vault->write('transit/decrypt/'.$key->keyName(), [
            'ciphertext' => $ciphertext->toString(),
        ]);

        $plaintext = $data['plaintext'] ?? null;

        if (!\is_string($plaintext)) {
            throw new CryptoFailed('Vault decrypt returned no plaintext.');
        }

        return self::decodeBase64($plaintext);
    }

    /**
     * @throws CryptoFailed
     */
    public static function decodeBase64(string $encoded): string
    {
        // strict 模式：非法 base64 返回 false 而不是静默跳过坏字符。
        // 静默跳过的后果是拿到一个**长度不对但看起来正常**的卡号 —— 那种数据会一路
        // 写进客户端数据库，等到收银台扫不出来才被发现。
        $decoded = base64_decode($encoded, true);

        if (false === $decoded) {
            throw new CryptoFailed('Vault returned a malformed base64 payload.');
        }

        return $decoded;
    }
}
