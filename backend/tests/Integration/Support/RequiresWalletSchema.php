<?php

declare(strict_types=1);

namespace App\Tests\Integration\Support;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Time\ClockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * 跑在**真的迁移过**的 `cards` 表上的集成用例共用的连接、跳过与清理逻辑。
 *
 * 形状与理由逐条同 {@see RequiresIdentitySchema}（为什么不是临时表、
 * 为什么每条用例包一个事务并回滚）——请连着读。
 *
 * ============================================================================
 * ⚠️ 多出来的一件事：`cards` 需要一个真的 `users` 行
 * ============================================================================
 * `cards.owner_id` 有一条**跨模块**外键指向 `users(id)`（ADR-0019），
 * 而它是 `ON DELETE RESTRICT` 且 `NOT NULL` —— 随手编一个 owner uuid 插进去
 * 会撞外键，症状是一条与被测代码无关的
 * `ForeignKeyConstraintViolationException`。
 *
 * 所以 {@see seedOwner()} 走**真实**的 `User::register()` 路径建一行。
 * 这也顺带让「那条外键真的在库里」成为每条用例的隐含断言：
 * 外键被误删的话，本 trait 不会失败，但
 * {@see \App\Tests\Integration\Module\Wallet\Doctrine\WalletSchemaTest} 会。
 */
trait RequiresWalletSchema
{
    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private function bootWalletSchema(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        if (null === $connection->fetchOne("SELECT to_regclass('cards')")) {
            self::markTestSkipped(
                'cards 表还没建 —— 先跑 `composer migration:check`'
                .'（或 `bin/console --env=test doctrine:migrations:migrate`）。',
            );
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->connection = $connection;
        $this->entityManager = $entityManager;

        $this->connection->beginTransaction();
    }

    private function rollbackWalletSchema(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }
    }

    /**
     * 建一个真的 `users` 行，返回它的 id 供 `cards.owner_id` 使用。
     *
     * ⚠️ 用真实的加密路径（Vault）而不是塞假密文：`users.email_encrypted`
     * 与 `email_hash` 都有类型闸门（`Ciphertext` / `HashDigest`），
     * 而 Vault 不可达时这里会抛 —— 那时候该 skip，不该以一个费解的形式红。
     */
    private function seedOwner(): Uuid
    {
        $container = self::getContainer();

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var UuidGeneratorInterface $uuids */
        $uuids = $container->get(UuidGeneratorInterface::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        /** @var UserRepositoryInterface $users */
        $users = $container->get(UserRepositoryInterface::class);

        $email = 'wallet-'.bin2hex(random_bytes(8)).'@example.de';

        try {
            $user = User::register(
                $uuids->generate(),
                HashDigest::fromRaw($hasher->hash($email)),
                $crypto->encrypt(CryptoKey::Pii, $email),
                Locale::German,
                $clock->now(),
            );
        } catch (\Throwable $e) {
            self::markTestSkipped('Vault 不可达或未初始化（'.$e->getMessage().'）。起 compose 栈后再跑。');
        }

        $users->save($user);

        return $user->id();
    }
}
