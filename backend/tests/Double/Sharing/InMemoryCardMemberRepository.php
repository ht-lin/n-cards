<?php

declare(strict_types=1);

namespace App\Tests\Double\Sharing;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `card_members` 仓储。
 *
 * 真 Postgres 上的行为由 `tests/Integration/Module/Sharing/Doctrine` 覆盖
 * （那里验列类型、三条外键、两条部分索引与违例翻译）；这里验的是**编排** ——
 * 哪个服务在什么条件下调了什么。
 *
 * ⚠️ 两条不变量在这里也必须成立，否则用例会在替身上绿、在真库上红
 * （`InMemoryCardRepository` 的类注释记过同一个坑）：
 *
 *   1. **「墓碑行不存在」** —— {@see findActiveFor()} 过滤 `leftAt`，
 *      与 `DoctrineCardMemberRepository` 的 `left_at IS NULL` 逐条对应。
 *      漏掉它的症状是被移除的成员仍然查得到，也就是 §7.2 的 T20。
 *   2. **`uq_card_single_owner`** —— {@see save()} 自己算一遍「这张卡还有没有
 *      别的活跃 owner」。不模拟这条的话，「第二个 owner 被拒」那条用例
 *      在单测层永远绿，而它是 T-110 的验收标准第一条。
 */
final class InMemoryCardMemberRepository implements CardMemberRepositoryInterface
{
    /** @var array<string, CardMember> 键是 `<cardId>:<userId>`（复合主键） */
    private array $members = [];

    public function __construct(CardMember ...$members)
    {
        foreach ($members as $member) {
            $this->members[self::key($member->cardId(), $member->userId())] = $member;
        }
    }

    public function save(CardMember $member): void
    {
        $this->assertSingleActiveOwner($member);

        $this->members[self::key($member->cardId(), $member->userId())] = $member;
    }

    public function findActiveFor(Uuid $userId, array $cardIds): array
    {
        if ([] === $cardIds) {
            return [];
        }

        $wanted = [];

        foreach ($cardIds as $cardId) {
            $wanted[$cardId->toString()] = true;
        }

        $found = [];

        foreach ($this->members as $member) {
            $cardId = $member->cardId()->toString();

            if (!$member->userId()->equals($userId) || !isset($wanted[$cardId])) {
                continue;
            }

            // 墓碑行不存在 —— 见类注释。
            if (!$member->isActive()) {
                continue;
            }

            $found[$cardId] = $member;
        }

        return $found;
    }

    /**
     * 用例直接读，用来断言「建卡确实写了 owner 行」这类事实。
     *
     * @return list<CardMember>
     */
    public function all(): array
    {
        return array_values($this->members);
    }

    /**
     * 模拟 `uq_card_single_owner`：一张卡最多一行 `role='owner'` 且 `left_at IS NULL`。
     *
     * ⚠️ 谓词与迁移里那条**逐字对应**（`role = 'owner' AND left_at IS NULL`）。
     * 真库上撞它抛 `UniqueConstraintViolationException`，由
     * `DoctrineCardMemberRepository` 翻成 `409 id_conflict`；这里直接抛翻译后的
     * 那个，因为单测这一层要断言的是**服务怎么处置它**，不是 DBAL 长什么样。
     */
    private function assertSingleActiveOwner(CardMember $incoming): void
    {
        if (!$incoming->isOwner() || !$incoming->isActive()) {
            return;
        }

        $key = self::key($incoming->cardId(), $incoming->userId());

        foreach ($this->members as $existingKey => $existing) {
            if ($existingKey === $key) {
                // 同一行的更新（比如 place()），不是第二个 owner。
                continue;
            }

            if ($existing->cardId()->equals($incoming->cardId()) && $existing->isOwner() && $existing->isActive()) {
                throw new DomainException(ErrorCode::IdConflict, 'The supplied id already belongs to another user; generate a new one and retry.');
            }
        }
    }

    private static function key(Uuid $cardId, Uuid $userId): string
    {
        return $cardId->toString().':'.$userId->toString();
    }
}
