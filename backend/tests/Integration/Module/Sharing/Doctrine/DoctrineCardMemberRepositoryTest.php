<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Sharing\Doctrine;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Module\Sharing\Domain\ValueObject\CardRole;
use App\Module\Sharing\Infrastructure\Doctrine\DoctrineCardMemberRepository;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Integration\Support\RequiresSharingSchema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * {@see DoctrineCardMemberRepository} 对着真 Postgres（T-110）。
 *
 * 这里验三件替身证明不了的事：复合主键的往返、`role` 枚举 ↔ TEXT 的真列读写、
 * 以及 `uq_card_single_owner` 的违例**翻译**（含它刻意不翻的那一支）。
 */
#[CoversClass(DoctrineCardMemberRepository::class)]
final class DoctrineCardMemberRepositoryTest extends KernelTestCase
{
    use RequiresSharingSchema;

    private CardMemberRepositoryInterface $members;

    private Uuid $ownerId;

    private Uuid $cardId;

    protected function setUp(): void
    {
        $this->bootSharingSchema();

        /** @var CardMemberRepositoryInterface $members */
        $members = self::getContainer()->get(CardMemberRepositoryInterface::class);
        $this->members = $members;

        $this->ownerId = $this->seedOwner();
        $this->cardId = $this->seedCard($this->ownerId);
    }

    protected function tearDown(): void
    {
        $this->rollbackSharingSchema();

        parent::tearDown();
    }

    // ========================================================================
    // 往返
    // ========================================================================

    public function testAnOwnerRowRoundTrips(): void
    {
        $joinedAt = new \DateTimeImmutable('2026-09-09T10:00:00+00:00');

        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, $joinedAt));
        $this->entityManager->clear();

        $found = $this->members->findActiveFor($this->ownerId, [$this->cardId]);

        self::assertArrayHasKey($this->cardId->toString(), $found);

        $member = $found[$this->cardId->toString()];

        self::assertTrue($member->cardId()->equals($this->cardId));
        self::assertTrue($member->userId()->equals($this->ownerId));
        self::assertSame(CardRole::Owner, $member->role());
        self::assertSame(0, $member->sortOrder());
        self::assertFalse($member->isPinned());
        self::assertNull($member->addedBy());
        self::assertNull($member->leftAt());
        self::assertTrue($member->isActive());
        self::assertSame($joinedAt->getTimestamp(), $member->joinedAt()->getTimestamp());
    }

    /**
     * `role` 映射成 `enum-type` + TEXT 列。读**真列**而不是比枚举实例 ——
     * 后者对静态分析是恒真的（`WalletSchemaTest` 里 `encryption_scheme`
     * 那条记过同一个理由）。
     */
    public function testTheRoleEnumIsStoredAsItsBackingString(): void
    {
        $viewerId = $this->seedOwner();

        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));
        $this->members->save(CardMember::viewer($this->cardId, $viewerId, $this->ownerId, new \DateTimeImmutable()));

        $roles = $this->connection->fetchAllKeyValue(
            'SELECT user_id::text, role FROM card_members WHERE card_id = ? ORDER BY role',
            [$this->cardId->toString()],
        );

        self::assertSame('owner', $roles[$this->ownerId->toString()]);
        self::assertSame('viewer', $roles[$viewerId->toString()]);
    }

    public function testAViewerRowCarriesTheInviter(): void
    {
        $viewerId = $this->seedOwner();

        $this->members->save(CardMember::viewer($this->cardId, $viewerId, $this->ownerId, new \DateTimeImmutable()));
        $this->entityManager->clear();

        $member = $this->members->findActiveFor($viewerId, [$this->cardId])[$this->cardId->toString()];

        self::assertSame(CardRole::Viewer, $member->role());
        self::assertNotNull($member->addedBy());
        self::assertTrue($member->addedBy()->equals($this->ownerId));
    }

    public function testPlacementIsPersisted(): void
    {
        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));

        $member = $this->members->findActiveFor($this->ownerId, [$this->cardId])[$this->cardId->toString()];
        $member->place(42, true);
        $this->members->save($member);

        $this->entityManager->clear();

        $reloaded = $this->members->findActiveFor($this->ownerId, [$this->cardId])[$this->cardId->toString()];

        self::assertSame(42, $reloaded->sortOrder());
        self::assertTrue($reloaded->isPinned());
    }

    // ========================================================================
    // 「墓碑行不存在」—— 接口级不变量
    // ========================================================================

    public function testAMemberWhoLeftIsInvisible(): void
    {
        $member = CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable());
        $member->leave(new \DateTimeImmutable());

        $this->members->save($member);
        $this->entityManager->clear();

        // 行还在库里（§5.4 的墓碑同步要靠它），但仓储看不见它。
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT count(*) FROM card_members WHERE card_id = ?',
            [$this->cardId->toString()],
        ));
        self::assertSame([], $this->members->findActiveFor($this->ownerId, [$this->cardId]));
    }

    // ========================================================================
    // 批量读
    // ========================================================================

    public function testAnEmptyBatchDoesNotHitTheDatabase(): void
    {
        // DQL 的 `IN ()` 是一条语法错误的 SQL，所以这不是优化而是必需 ——
        // 短路没了的话这一条会抛，而不是返回空数组。
        self::assertSame([], $this->members->findActiveFor($this->ownerId, []));
    }

    public function testTheBatchIsKeyedByCardIdAndScopedToOneUser(): void
    {
        $otherUser = $this->seedOwner();
        $secondCard = $this->seedCard($this->ownerId);
        $thirdCard = $this->seedCard($otherUser);

        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));
        $this->members->save(CardMember::owner($secondCard, $this->ownerId, new \DateTimeImmutable()));
        $this->members->save(CardMember::owner($thirdCard, $otherUser, new \DateTimeImmutable()));

        $this->entityManager->clear();

        $found = $this->members->findActiveFor($this->ownerId, [$this->cardId, $secondCard, $thirdCard]);

        // 第三张卡是别人的 —— 结果里不该有它（C11：viewer 之间互不可见，
        // 而这里连成员关系都不存在）。
        self::assertSame(
            [$this->cardId->toString(), $secondCard->toString()],
            array_keys($found),
        );
    }

    public function testDuplicateCardIdsCollapse(): void
    {
        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));
        $this->entityManager->clear();

        $found = $this->members->findActiveFor($this->ownerId, [$this->cardId, $this->cardId]);

        self::assertCount(1, $found);
    }

    // ========================================================================
    // 违例翻译 —— 两条分支都要钉住
    // ========================================================================

    /**
     * ⚠️ 这是本仓库第二处「把唯一索引违例翻译成领域错误」的地方
     * （第一处是 `DoctrineUserRepository::saveNewUsername()`）。
     * 翻译必须发生在仓储里 —— deptrac 只允许 Infrastructure 看见 Doctrine。
     */
    public function testASecondOwnerIsTranslatedIntoIdConflict(): void
    {
        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));
        $this->entityManager->clear();

        $intruder = $this->seedOwner();

        try {
            $this->members->save(CardMember::owner($this->cardId, $intruder, new \DateTimeImmutable()));
            self::fail('Expected DomainException.');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::IdConflict, $e->errorCode());
            self::assertSame(409, $e->errorCode()->httpStatus());
            // 原异常挂在 previous 上供日志取用 —— 口径同 saveNewUsername()。
            self::assertInstanceOf(UniqueConstraintViolationException::class, $e->getPrevious());
        }
    }

    /**
     * ⚠️ **只翻译 `uq_card_single_owner`。** 复合主键冲突（同一个人被加进同一张
     * 卡两次）是调用方的 bug，不是客户端能修的东西 —— 它必须原样冒泡成 500。
     *
     * 没有这一条的话，一个过宽的 `catch` 会把主键冲突也报成
     * 「409 请重新生成 id」，而客户端照做之后仍然失败。
     */
    public function testOtherUniqueViolationsAreNotSwallowed(): void
    {
        $this->members->save(CardMember::owner($this->cardId, $this->ownerId, new \DateTimeImmutable()));
        $this->entityManager->clear();

        $this->expectException(UniqueConstraintViolationException::class);

        // 同一对 (card_id, user_id)，但角色不同 → 撞的是复合主键，
        // 不是 uq_card_single_owner。
        $this->members->save(CardMember::viewer($this->cardId, $this->ownerId, $this->ownerId, new \DateTimeImmutable()));
    }
}
