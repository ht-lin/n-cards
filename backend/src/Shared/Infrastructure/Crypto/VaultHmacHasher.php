<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Crypto;

use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Infrastructure\Vault\VaultClient;

/**
 * 带 Vault pepper 的 HMAC-SHA256（§3.8 / §5.3 的 `transit/ncards-hmac`）。
 *
 * Transit 的 `hmac` 端点返回的是 `vault:v1:<base64>` —— 和密文一样带版本前缀。
 * 但这里**不**用 {@see \App\Shared\Domain\Crypto\Ciphertext} 包装，
 * 而是剥掉前缀、解出 32 字节裸摘要返回。理由是落库形态：
 * §17.1 的 `users.email_hash` 与 `cards.barcode_value_fingerprint` 都是 **BYTEA**，
 * 存裸摘要。存带前缀的字符串会让 UNIQUE 约束比的是一个含版本号的字符串 ——
 * 而这把 key 永远不轮换（见 {@see CryptoKey::isRotatable()}），版本号恒为 v1，
 * 除了浪费 9 个字节没有任何作用。
 */
final readonly class VaultHmacHasher implements HmacHasherInterface
{
    /** SHA-256 摘要的字节数。 */
    private const DIGEST_BYTES = 32;

    public function __construct(private VaultClient $vault)
    {
    }

    public function hash(string $input): string
    {
        $data = $this->vault->write('transit/hmac/'.CryptoKey::Hmac->keyName(), [
            'input' => base64_encode($input),
            // 显式写死算法。Transit 的默认值当前就是 sha2-256，但「默认值」是
            // Vault 的实现细节，一次升级就可能改 —— 而这个算法一旦变了，
            // 全部既有 email_hash 就再也匹配不上（HMAC 不可逆，无从迁移）。
            // 见 CryptoKey::isRotatable() 里关于「换 key 的后果」的那段，同理。
            'algorithm' => 'sha2-256',
        ]);

        $hmac = $data['hmac'] ?? null;

        if (!\is_string($hmac)) {
            throw new CryptoFailed('Vault hmac returned no digest.');
        }

        return self::rawDigest($hmac);
    }

    public function verify(string $input, string $digest): bool
    {
        // 只为算 $input 的摘要打一次 Vault，比较在本地做（理由见接口注释）。
        // hash_equals 是**定长时间**比较：用 === 的话，比较耗时会随前缀匹配长度变化，
        // 于是攻击者可以逐字节试探出正确摘要。这里的输入是攻击者可控的
        // （比如「这个邮箱注册过吗」的探测），所以这不是纸上谈兵。
        return hash_equals($this->hash($input), $digest);
    }

    /**
     * `vault:v1:<base64>` → 32 字节裸摘要。
     *
     * @throws CryptoFailed
     */
    private static function rawDigest(string $prefixed): string
    {
        $lastColon = strrpos($prefixed, ':');

        if (false === $lastColon) {
            throw new CryptoFailed('Vault hmac returned an unrecognised digest format.');
        }

        $digest = VaultTransitCrypto::decodeBase64(substr($prefixed, $lastColon + 1));

        if (self::DIGEST_BYTES !== \strlen($digest)) {
            // 长度不对说明算法不是 sha2-256 —— 上面明明写死了，所以走到这里意味着
            // Vault 侧的 key 类型不对（比如 bootstrap.sh 建成了别的 key_size）。
            // 让它响，而不是把一个长度不对的值写进 BYTEA 列。
            throw new CryptoFailed('Vault hmac digest has an unexpected length.');
        }

        return $digest;
    }
}
