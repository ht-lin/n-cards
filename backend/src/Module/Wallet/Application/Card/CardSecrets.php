<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;

/**
 * 写路径的加密（§5.3）：明文 → 密文 + 指纹。
 *
 * {@see CardViewAssembler} 的镜像 —— 那个只解密，这个只加密。
 *
 * ============================================================================
 * 为什么码值与指纹**只能一起算**
 * ============================================================================
 * `barcode_value_fingerprint` 是 `HMAC(码值)`（§5.2，重复卡检测用，不可逆）。
 * 分成两个方法的话就会出现「加密了新码值但忘了重算指纹」的行 ——
 * 而指纹不可逆，事后没有任何办法从库里发现它对不上。
 * {@see \App\Module\Wallet\Domain\Entity\Card::changeBarcodeValue()} 用同一条论证
 * 把它们收成一个方法的两个参数，这里是那条链的上游。
 *
 * ============================================================================
 * 每次建卡 3 次 Vault 往返，可接受
 * ============================================================================
 * 码值加密 1 次、备注加密 0–1 次、HMAC 1 次。这是**低频写路径**
 * （建卡是用户手动动作，不是批量），而 {@see HmacHasherInterface} 的类注释
 * 已经为同一件事论证过：「这几个调用点都在登录、建卡这类低频写路径上，
 * 不在钱包列表那种批量读路径上」。
 *
 * ⚠️ 别为了省往返把加密也做成批量：`CryptoServiceInterface` 没有批量加密方法，
 * 而加一个只为了给单张卡省一次往返，会引出一个「批量加密」的门面 ——
 * 然后它会被用在读路径上，而读路径上真正的约束是批量**解密**。
 */
final readonly class CardSecrets
{
    public function __construct(
        private CryptoServiceInterface $crypto,
        private HmacHasherInterface $hasher,
    ) {
    }

    /**
     * 码值的密文与指纹。
     *
     * @return array{Ciphertext, HashDigest}
     */
    public function barcode(string $plaintext): array
    {
        return [
            $this->crypto->encrypt(CryptoKey::Card, $plaintext),
            HashDigest::fromRaw($this->hasher->hash($plaintext)),
        ];
    }

    /**
     * 备注的密文。`null` 进 `null` 出 —— 不加密一个空备注：
     * 那会白花一次 Vault 往返，还会让 `note_encrypted IS NULL`
     * （「没有备注」）与一个「空字符串的密文」变成两种表示同一件事的方式。
     */
    public function note(?string $plaintext): ?Ciphertext
    {
        if (null === $plaintext) {
            return null;
        }

        return $this->crypto->encrypt(CryptoKey::Card, $plaintext);
    }
}
