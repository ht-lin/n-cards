<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Sharing\Application\Port\CardPlacementWriterInterface;
use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;

/**
 * `PUT /v1/cards/{id}/placement`（§6.2，T-110）。
 *
 * ============================================================================
 * 这是 viewer 唯一的上行写入端点
 * ============================================================================
 * §5.2 的角色权限矩阵：viewer 只有两项权利 —— 看，和摆放自己钱包里的位置。
 * `sort_order` / `is_pinned` 是**每成员私有**的（存在 `card_members` 上），
 * 所以同一张共享卡，Anna 置顶、Bob 不置顶，互不影响。
 *
 * ============================================================================
 * ⚠️ 鉴权判据是**成员行**，不是 `$card->isOwnedBy()`
 * ============================================================================
 * 这是本服务与 {@see UpdateCardService} / {@see DeleteCardService} 的分水岭：
 * 那两个对非 owner 一律 `403 insufficient_role`，本服务对**任何**活跃成员放行。
 *
 * 判定由 {@see CardPlacementWriterInterface::updatePlacement()} 内联完成
 * （查不到活跃成员行就抛 `403 not_a_member`），本服务**不先查一次再写**：
 * 分成两步会开出一个 TOCTOU 窗口，而 M3 的级联撤销（T-303，解除好友 →
 * 同事务撤销共享）恰好会在那个窗口里把成员行改掉。
 *
 * 用 `not_a_member` 而不是 `insufficient_role` 也是有产品含义的：
 * 契约对前者的语义是「客户端应据此**从本地删除该卡**（说明共享已被撤销）」，
 * 而那正是一个 viewer 打这个端点却发现自己已被移除时该做的事。
 * 两个码不许合并，见 `DomainException::insufficientRole()` 的注释。
 *
 * ============================================================================
 * ⚠️ 检查顺序：404 早于 403
 * ============================================================================
 * ```
 * 1. 请求体格式         → 400 validation_failed   （控制器里，不查库）
 * 2. 卡查不到 / 已软删  → 404 not_found
 * 3. 不是活跃成员       → 403 not_a_member
 * ```
 *
 * 第 2 步必须在第 3 步之前：一个根本不存在的卡 id 不该回 403 ——
 * 那会告诉调用者「这张卡存在，只是你不在上面」，而他连它存不存在都不该知道。
 * 反过来（先判成员）也不安全：`card_members` 里可能有一行指向已被软删的卡
 * （T-110 的回填**刻意**包含软删的卡），那时会对一张已删除的卡返回 200。
 *
 * ============================================================================
 * ⚠️ 不碰 `Card`，所以不碰 `revision`
 * ============================================================================
 * 契约逐字：「不走 revision 锁，不需要 `If-Match`，也不会递增卡的 `revision`
 * —— 它改的根本不是卡本身。」
 *
 * 本服务只**读**那张卡（为了 404 判定与最后的组装），一个 setter 都不调，
 * 于是 Doctrine 不会给它发 UPDATE，version 字段自然不动。
 * 这是结构性成立的，不是靠记得别写那一行。
 *
 * 也**不需要** `TransactionRunnerInterface`：只有一次写。
 */
final readonly class UpdateCardPlacementService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardPlacementWriterInterface $placement,
        private CardViewAssembler $assembler,
    ) {
    }

    /**
     * @throws DomainException `not_found`（404）/ `not_a_member`（403）
     */
    public function update(AuthContext $auth, Uuid $cardId, CardPlacementPayload $payload): CardView
    {
        // find() 恒带 `deleted_at IS NULL`，所以软删的卡在这里就是 404。
        $card = $this->cards->find($cardId);

        if (!$card instanceof Card) {
            throw DomainException::notFound('No such card.');
        }

        $this->placement->updatePlacement($cardId, $auth->userId, $payload->sortOrder, $payload->isPinned);

        // 写完再组装 —— 响应里带的是**新**的 placement。
        // 单张卡也走批量入口，见 CardViewAssembler 的类注释。
        return $this->assembler->assemble([$card], $auth->userId)[0];
    }
}
