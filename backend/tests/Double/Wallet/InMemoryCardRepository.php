<?php

declare(strict_types=1);

namespace App\Tests\Double\Wallet;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `cards` 仓储。
 *
 * 真 Postgres 上的行为由 `tests/Integration/Module/Wallet/Doctrine` 覆盖
 * （那里验列类型、约束与乐观锁的 SQL）；这里验的是**编排** ——
 * 哪个服务在什么条件下调了什么。
 *
 * ⚠️ 「软删的行不存在」这条不变量在这里也要成立，否则用例会在替身上绿、
 * 在真库上红。{@see find()} / {@see findOwnedPage()} / {@see countOwnedBy()}
 * 都过滤 `deletedAt`，与 `DoctrineCardRepository` 逐条对应。
 */
final class InMemoryCardRepository implements CardRepositoryInterface
{
    /** @var array<string, Card> 键是 id 的字符串形式 */
    private array $cards = [];

    /**
     * 下一次 {@see saveWithRevision()} 无条件抛 `revision_conflict`。
     *
     * 用来造「载入之后、flush 之前被人抢先改了」那一支 —— 真库上它由
     * Doctrine 自动加的 `WHERE revision = :old` 命中 0 行触发，
     * 进程内没有并发，只能显式打开。
     */
    private bool $forceConflict = false;

    public function __construct(Card ...$cards)
    {
        foreach ($cards as $card) {
            $this->cards[$card->id()->toString()] = $card;
        }
    }

    public function forceRevisionConflict(): void
    {
        $this->forceConflict = true;
    }

    public function save(Card $card): void
    {
        $this->cards[$card->id()->toString()] = $card;
    }

    public function saveWithRevision(Card $card, int $expectedRevision): void
    {
        if ($this->forceConflict || $card->revision() !== $expectedRevision) {
            throw DomainException::revisionConflict(['revision' => $card->revision(), 'updated_at' => $card->updatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z')]);
        }

        $this->save($card);
    }

    public function find(Uuid $id): ?Card
    {
        $card = $this->cards[$id->toString()] ?? null;

        return null !== $card && !$card->isDeleted() ? $card : null;
    }

    public function findIncludingDeleted(Uuid $id): ?Card
    {
        return $this->cards[$id->toString()] ?? null;
    }

    public function findOwnedPage(Uuid $ownerId, ?Uuid $after, int $limit): array
    {
        $rows = array_values(array_filter(
            $this->cards,
            static fn (Card $card): bool => $card->isOwnedBy($ownerId)
                && !$card->isDeleted()
                && (null === $after || $card->id()->toString() > $after->toString()),
        ));

        // 按 id 升序 —— 与 `DoctrineCardRepository` 的 `ORDER BY c.id ASC` 一致。
        // 字符串比较对规范化的小写 UUID 等价于字节序比较。
        usort($rows, static fn (Card $a, Card $b): int => strcmp($a->id()->toString(), $b->id()->toString()));

        return \array_slice($rows, 0, $limit);
    }

    public function countOwnedBy(Uuid $ownerId): int
    {
        return \count(array_filter(
            $this->cards,
            static fn (Card $card): bool => $card->isOwnedBy($ownerId) && !$card->isDeleted(),
        ));
    }
}
