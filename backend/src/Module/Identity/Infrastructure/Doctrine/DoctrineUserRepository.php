<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Doctrine;

use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see UserRepositoryInterface} 的 Doctrine 实现。
 *
 * ============================================================================
 * `save()` 为什么直接 flush
 * ============================================================================
 * 换来的是一条明确的分工线：
 *
 *   - **单次写**（改个 locale、设个 username）：调用方只管 `save()`，
 *     不需要知道 UnitOfWork 存在。
 *   - **多次写必须原子**（T-104 的「建 user + 建 device + 建 session」）：
 *     调用方用 `Shared\Application\Transaction\TransactionRunnerInterface::run()`
 *     包住整段。DBAL 事务已经开着，里面这几次 flush 落在同一个事务里，
 *     任何一步抛异常都整体回滚。`doctrine.yaml` 的 `use_savepoints: true`
 *     让嵌套调用也安全（§4.2 唯一强制同事务的跨模块协作点依赖这个语义）。
 *
 * 反过来的设计（`save()` 只 persist、由某处统一 flush）在这个项目里更差：
 * 那个「某处」只能是 Http 层的一个监听器，而 §12.2 明令 Http 层不得碰 Doctrine。
 *
 * ⚠️ 本类**不做任何加解密**。`HashDigest` 与 `Ciphertext` 是调用方（Application 层）
 * 算好之后传进实体的 —— §5.3 的批量解密编排在 Application，塞进仓储就没法批了
 * （见 `CryptoServiceInterface` 的类注释）。
 */
final readonly class DoctrineUserRepository implements UserRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function save(User $user): void
    {
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    public function findById(Uuid $id): ?User
    {
        return $this->entityManager->find(User::class, $id);
    }

    public function findByEmailHash(HashDigest $emailHash): ?User
    {
        return $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['emailHash' => $emailHash]);
    }

    public function findByUsername(string $normalized): ?User
    {
        // 等值查询，走 uq_users_username。**不要**改成 LOWER(username) = ? ——
        // 那会绕开唯一索引全表扫，而且没有必要：库里存的就是归一化后的小写值（§17.1）。
        return $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['username' => $normalized]);
    }
}
