<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Sharing\Application\Dto\CardMembershipMap;
use App\Module\Sharing\Application\Port\CardMembershipReaderInterface;
use App\Module\Wallet\Domain\Entity\Card;
use App\Shared\Application\Crypto\BatchDecryptorInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Identity\Uuid;

/**
 * `Card` 实体 → {@see CardView}，**一次批量解密**（§5.3 的硬要求）。
 *
 * ============================================================================
 * ⚠️ 这是全模块唯一解密卡片的地方，而且它没有单条版本
 * ============================================================================
 * §5.3 逐字：「必须使用 Vault Transit 的 batch 接口，一次请求解密全部。
 * **禁止在循环中逐条调用 Vault**。」§9.1 的预算说明了为什么：
 * `GET /v1/sync/bootstrap`（200 张卡）P95 ≤ 700 ms，而 200 次 HTTP 往返
 * 光是往返就能吃掉大半。
 *
 * 所以本类**只有** {@see assemble()} 一个收数组的入口 ——
 * `GET /v1/cards/{id}` 那种单卡场景也走它（传一个只有一张卡的数组）。
 * 留一个 `assembleOne()` 就等于留了一个会被抄进 foreach 的示范，
 * 而那正是上面那条禁令针对的写法。
 *
 * ============================================================================
 * 键的形状
 * ============================================================================
 * {@see BatchDecryptorInterface} 明说「键关联由调用方决定，入参与出参同键」——
 * 它的类注释还专门警告过按下标对齐是这类批量 API 最经典的错位 bug
 * （一条失败、数组被压缩，于是 A 的卡号显示成了 B 的）。
 *
 * 一张卡有**两个**密文列，所以键不能只是卡 id。这里用
 * `b:<cardId>` / `n:<cardId>` 两个前缀。前缀而不是二维数组：
 * `decryptAll()` 收的是一个平数组，而把两批分开调等于两次往返。
 *
 * ============================================================================
 * 空批次不打 Vault
 * ============================================================================
 * `decryptAll([])` 按接口约定返回空数组且不发请求，所以「一页 0 张卡」
 * 不需要在这里特判。成员查询同样（`membershipsFor()` 对空数组不查库）。
 *
 * ============================================================================
 * ⚠️ T-110：第二份批量查找，同一条纪律
 * ============================================================================
 * `my_role` / `can_edit` / `sort_order` / `is_pinned` 都来自 `card_members`，
 * 而那张表属 **Sharing** 模块（§4.2），所以这里经
 * {@see CardMembershipReaderInterface} 跨模块取 —— 它和 Vault 一样是
 * **一次批量查询**，与卡数无关。
 *
 * 理由与上面那段解密的逐字相同：§9.1 给 `GET /v1/sync/bootstrap`（200 张卡）
 * 的预算是 P95 ≤ 700 ms。200 次主键查找不会当场把预算吃光，但「加一张表就多
 * 一轮 N+1」是会被复制的形状，加到第三张表就晚了。那个接口因此也**没有**
 * 单条版本。
 *
 * 于是本类的不变量从「一个入口、一次批量解密」变成
 * 「一个入口、**两次**批量查找」。真正要守的那条没变：不许 per-row 远程调用。
 *
 * ⚠️ 成员查询排在解密**之前**：它便宜得多，而且结果不完整时能在花掉一次
 * Vault 往返之前就失败。
 */
final readonly class CardViewAssembler
{
    private const BARCODE_PREFIX = 'b:';
    private const NOTE_PREFIX = 'n:';

    public function __construct(
        private BatchDecryptorInterface $decryptor,
        private CardMembershipReaderInterface $memberships,
    ) {
    }

    /**
     * @param list<Card> $cards
     * @param Uuid       $viewerId 调用者 —— `my_role` / `can_edit` 随他而变
     *
     * @return list<CardView>
     */
    public function assemble(array $cards, Uuid $viewerId): array
    {
        $cardIds = array_map(static fn (Card $card): Uuid => $card->id(), $cards);

        // 一次跨模块批量查询，先于 Vault —— 见类注释。
        $memberships = $this->memberships->membershipsFor($viewerId, $cardIds);

        self::assertComplete($memberships, $cardIds);

        $plaintexts = $this->decryptor->decryptAll(CryptoKey::Card, $this->ciphertexts($cards));

        $views = [];

        foreach ($cards as $card) {
            $id = $card->id()->toString();

            // assertComplete() 已经保证它不是 null。
            $membership = $memberships->for($card->id());
            \assert(null !== $membership);

            $views[] = new CardView(
                $card->id(),
                $card->title(),
                $card->merchantLabel(),
                $card->color(),
                $card->barcodeFormat()->value,
                $plaintexts[self::BARCODE_PREFIX.$id],
                $plaintexts[self::NOTE_PREFIX.$id] ?? null,
                $card->expiresOn(),
                $card->ownerId(),
                // T-110：这四个全部来自 `card_members` 上调用者自己那一行。
                // `canEdit` 是 Sharing 算好的（CardRole::canEdit()）——
                // 这里**不写**任何角色比较，那正是把它放在 DTO 里的目的。
                $membership->role,
                $membership->canEdit,
                $membership->sortOrder,
                $membership->isPinned,
                $card->revision(),
                $card->updatedAt(),
            );
        }

        return $views;
    }

    /**
     * 每张卡都必须有调用者的成员行，否则**抛**。
     *
     * ============================================================================
     * ⚠️ 为什么不回退到一个默认值
     * ============================================================================
     * 走到这里说明调用方**已经判过权限**了（`CardQueryService::get()` 的
     * `not_a_member`、`UpdateCardService` / `DeleteCardService` 的
     * `insufficient_role` 都在前面，placement 的鉴权就是那次写本身）。
     * 所以「卡在、成员行不在」不是一种输入，是数据破损。
     *
     * 三条备选都更糟：
     *
     *   - 回退成 `viewer` / `false` → 一张卡的 owner 悄悄看到只读界面，
     *     Android 把编辑入口灰掉，用户报「我的卡变成只读了」——
     *     而没有测试覆盖、没有日志、没有任何东西是红的。
     *   - 回退成 `owner` / `true` → 从**缺失的**数据里推导出一个授权结论。
     *     M3 有 viewer 之后这是一个越权。
     *   - 跳过那张卡 → 卡从 `GET /v1/cards` 里凭空消失，而游标照样越过了它，
     *     客户端连「少了一张」都看不出来。
     *
     * `LogicException` 而不是 `DomainException`：没有任何客户端输入能造出这个
     * 状态，也就没有客户端可以分支的 code。它经
     * `ApiProblemExceptionListener` 变成 500 `internal_error` 并进日志。
     *
     * M1 里这一支走不到 —— T-110 的迁移把 `cards` 全表回填了 owner 行
     * （含软删的），此后每张新卡的 owner 行与卡在同一个事务里。
     * 正因为走不到，它必须很响：一个安静的回退会让破损在生产里躺几个月。
     *
     * @param list<Uuid> $cardIds
     */
    private static function assertComplete(CardMembershipMap $memberships, array $cardIds): void
    {
        $missing = $memberships->missingFrom($cardIds);

        if ([] !== $missing) {
            throw new \LogicException(\sprintf('Card(s) without a card_members row for the current viewer: %s. Every card must have an owner member row (T-110); this is data corruption, not a permission problem — the caller already authorised this read.', implode(', ', $missing)));
        }
    }

    /**
     * 这一批卡的全部密文，键已经带好前缀。
     *
     * @param list<Card> $cards
     *
     * @return array<string, Ciphertext>
     */
    private function ciphertexts(array $cards): array
    {
        $ciphertexts = [];

        foreach ($cards as $card) {
            $id = $card->id()->toString();

            $ciphertexts[self::BARCODE_PREFIX.$id] = $card->barcodeValueEncrypted();

            // 备注可空。null 的那些**不进批次** —— 塞一个假密文进去，
            // Vault 会整批失败（BatchDecryptorInterface：任意一条失败就整批抛）。
            $note = $card->noteEncrypted();

            if (null !== $note) {
                $ciphertexts[self::NOTE_PREFIX.$id] = $note;
            }
        }

        return $ciphertexts;
    }
}
