<?php

declare(strict_types=1);

namespace App\Module\Sharing\Application\Dto;

use App\Shared\Domain\Identity\Uuid;

/**
 * 一批卡 → 调用者在它们上面的成员关系（T-110）。
 *
 * ============================================================================
 * 为什么是一个类型，而不是一个 `array<string, CardMembership>`
 * ============================================================================
 * {@see \App\Shared\Application\Crypto\BatchDecryptorInterface} 的类注释花了
 * 一整段警告这种「批量进、按键取回」的 API 是最经典的错位 bug 来源：
 * 「一条失败、数组被压缩，于是 A 的卡号显示成了 B 的」。
 *
 * 而 {@see \App\Module\Wallet\Application\Card\CardViewAssembler} 马上就要
 * **同时**握着两份按 cardId 键控的批量结果（解密的明文 + 这里的成员关系）。
 * 给第二份一个只能用 {@see Uuid} 索引的类型，第一份需要一段警告才能防住的事，
 * 第二份在结构上就出不了 —— 拿不到裸数组，也就写不出 `$results[$i]`。
 *
 * 附带的收益：「哪些卡没查到成员行」这个判断有了一个有名字的去处
 * （{@see missingFrom()}），而不是散在调用方的一段 array_diff。
 *
 * ============================================================================
 * 缺席**不是**错误
 * ============================================================================
 * 不是成员的卡不出现在这里，本类也不抛。「缺席意味着什么」由调用方决定：
 *
 *   - placement 端点：缺席 = `403 not_a_member`（他不在这张卡上）。
 *   - `CardViewAssembler`：缺席 = 数据破损（调用方已经判过权限了），
 *     一个 500。
 *
 * 把这个判断塞进本类，那两处就必须共用同一个答案 —— 而它们的答案不一样。
 */
final readonly class CardMembershipMap
{
    /**
     * @param array<string, CardMembership> $byCardId 键 = `cardId->toString()`
     */
    private function __construct(private array $byCardId)
    {
    }

    /**
     * @param array<string, CardMembership> $byCardId 键 = `cardId->toString()`
     */
    public static function of(array $byCardId): self
    {
        return new self($byCardId);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * 这张卡上的成员关系，没有则 `null`。
     *
     * ⚠️ 只能用 `Uuid` 索引 —— 这是本类存在的理由，见类注释。
     */
    public function for(Uuid $cardId): ?CardMembership
    {
        return $this->byCardId[$cardId->toString()] ?? null;
    }

    /**
     * `$cardIds` 里没有对应成员关系的那些。
     *
     * 给调用方做完整性断言用（`CardViewAssembler` 就靠它把「组装到一半发现
     * 没有成员行」变成一条指名道姓的错误，而不是一个 `null` 上的类型错误）。
     *
     * @param list<Uuid> $cardIds
     *
     * @return list<string> 缺失的 cardId，已去重，顺序同入参
     */
    public function missingFrom(array $cardIds): array
    {
        $missing = [];

        foreach ($cardIds as $cardId) {
            $key = $cardId->toString();

            if (!isset($this->byCardId[$key]) && !\in_array($key, $missing, true)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
