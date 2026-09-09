<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Time\ClockInterface;

/**
 * 跑在**真的迁移过**的 `card_members` 表上的集成用例共用的装配（T-110）。
 *
 * 复用 {@see RequiresWalletSchema} 的连接、跳过与 `seedOwner()` —— 因为
 * `card_members` 的三条外键分别指向 `cards` 与 `users`，两张表都得有真行。
 * 形状与理由（为什么不是临时表、为什么每条用例包一个事务并回滚）
 * 逐条同 {@see RequiresIdentitySchema}，请连着读。
 *
 * ============================================================================
 * ⚠️ 种一行成员记录要**三样**东西都在
 * ============================================================================
 * `fk_card_members_card_id → cards(id)`、`fk_card_members_user_id → users(id)`、
 * `fk_card_members_added_by → users(id)`。随手编一个 uuid 插进去会撞外键，
 * 症状是一条与被测代码无关的 `ForeignKeyConstraintViolationException`。
 *
 * 所以 {@see seedCard()} 建的是一张**真卡**（走真实的 Vault 加密路径），
 * owner 用父 trait 的 `seedOwner()`。这也顺带让「那三条外键真的在库里」
 * 成为每条用例的隐含断言。
 */
trait RequiresSharingSchema
{
    use RequiresWalletSchema;

    private function bootSharingSchema(): void
    {
        $this->bootWalletSchema();

        if (null === $this->connection->fetchOne("SELECT to_regclass('card_members')")) {
            self::markTestSkipped(
                'card_members 表还没建 —— 先跑 `composer migration:check`'
                .'（或 `bin/console --env=test doctrine:migrations:migrate`）。',
            );
        }
    }

    private function rollbackSharingSchema(): void
    {
        $this->rollbackWalletSchema();
    }

    /**
     * 建一张真卡，返回它的 id 供 `card_members.card_id` 使用。
     *
     * ⚠️ **不写成员行** —— 那是被测对象。要一行 owner 的用例自己
     * `CardMember::owner(...)` 再 save，好让「谁写的这一行」在用例里看得见。
     */
    private function seedCard(Uuid $ownerId): Uuid
    {
        $container = self::getContainer();

        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CardRepositoryInterface $cards */
        $cards = $container->get(CardRepositoryInterface::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);

        // 客户端生成的 UUIDv7（§5.4.3）。随机后缀让同一条用例里可以建多张。
        $cardId = Uuid::fromString(\sprintf(
            '0192f3a1-b2c3-7d4e-8f01-%012s',
            bin2hex(random_bytes(6)),
        ));

        $plaintext = 'code-'.bin2hex(random_bytes(4));

        $cards->save(Card::create(
            $cardId,
            $ownerId,
            'Karte',
            null,
            'blue_600',
            BarcodeFormat::Ean13,
            $crypto->encrypt(CryptoKey::Card, $plaintext),
            HashDigest::fromRaw($hasher->hash($plaintext)),
            null,
            null,
            $clock->now(),
        ));

        return $cardId;
    }
}
