<?php

declare(strict_types=1);

namespace App\Tests\Double\Wallet;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * Wallet 用例的实体构造糖，仿 {@see \App\Tests\Double\Identity\IdentityEntities}。
 *
 * 每个工厂都给全部参数配了默认值，用例只写它**关心**的那几个 ——
 * 一条测「软删之后查不到」的用例不该被迫编一个颜色和一个条码格式。
 */
final class WalletEntities
{
    /**
     * 可读、稳定、且是**合法 UUIDv7** 的 id。
     *
     * ⚠️ 版本位必须对：`CardCreatePayload` 会拒非 v7 的 id，而
     * `findOwnedPage()` 拿 id 当 keyset 游标键。随手写一串十六进制的话，
     * 一半的用例会以「id 格式非法」而不是它们想测的原因失败。
     *
     * `$n` 单调递增 → 生成的 id 也单调递增，于是分页用例可以直接假定顺序。
     */
    public static function id(int $n): Uuid
    {
        return Uuid::fromString(\sprintf('0192f3a1-b2c3-7d4e-8f01-%012x', $n));
    }

    /**
     * 与 {@see \App\Tests\Double\Crypto\InMemoryCryptoService} 同格式的假密文，
     * 好让它能被 {@see \App\Tests\Double\Crypto\InMemoryBatchDecryptor} 解回来。
     */
    public static function ciphertext(string $plaintext, string $key = 'ncards-card'): Ciphertext
    {
        return Ciphertext::fromString(\sprintf('vault:v1:fake.%s.%s', $key, base64_encode($plaintext)));
    }

    public static function digest(string $input): HashDigest
    {
        return HashDigest::fromRaw(hash('sha256', $input, true));
    }

    public static function now(string $at = '2026-09-08T12:00:00+00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at);
    }

    /**
     * 一张普通的卡。
     *
     * @param string $barcodeValue **明文** —— 工厂自己包成密文与指纹，
     *                             用例不必手工保持两者一致
     */
    public static function card(
        ?Uuid $id = null,
        ?Uuid $ownerId = null,
        string $title = 'REWE Payback',
        ?string $merchantLabel = 'REWE',
        string $color = 'blue_600',
        BarcodeFormat $barcodeFormat = BarcodeFormat::Ean13,
        string $barcodeValue = '4012345678901',
        ?string $note = null,
        ?\DateTimeImmutable $expiresOn = null,
        ?\DateTimeImmutable $now = null,
    ): Card {
        return Card::create(
            $id ?? self::id(1),
            $ownerId ?? self::id(0xA11A),
            $title,
            $merchantLabel,
            $color,
            $barcodeFormat,
            self::ciphertext($barcodeValue),
            self::digest($barcodeValue),
            null === $note ? null : self::ciphertext($note),
            $expiresOn,
            $now ?? self::now(),
        );
    }
}
