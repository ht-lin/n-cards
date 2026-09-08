<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `DELETE /v1/cards/{id}` —— 软删（§5.2 / §5.4.3，T-109）。
 *
 * ============================================================================
 * ⚠️ 没有 `If-Match`
 * ============================================================================
 * 契约给 `DELETE` 的参数里**只有** `X-Client` 与 `cardId` —— 没有 `IfMatch`。
 * 这是有道理的：乐观锁防的是「你基于旧版本做的**修改**覆盖了别人的新版本」，
 * 而删除不带任何来自客户端的内容，没有什么可覆盖的。
 * 而且这张卡只有一个写入者（owner，§5.2 的角色矩阵），并发修改的对手只能是
 * 他自己的另一台设备 —— 那种情况下「删掉」几乎总是他想要的最终结果。
 *
 * 别顺手给它加 `If-Match`：那会让离线队列里一个删除条目在卡被改过之后
 * 永久失败（409），而客户端对删除的重试逻辑里没有「重新读取再重试」这一步。
 *
 * ============================================================================
 * 软删，不是物删
 * ============================================================================
 * 写 `deleted_at`，90 天后由清理任务硬删（§5.2）。行留着是因为：
 *
 *   - §5.4.3 的墓碑同步要靠它 —— 其他设备（M3 之后还有 viewer）
 *     只有在 `/sync` 里收到这一行才知道该删本地副本（T-201）。
 *   - `id` 仍然算被占用，于是重放一个建卡请求得到的是 `200` 而不是主键冲突
 *     （见 {@see CreateCardService} 的类注释）。
 *
 * ⚠️ 墓碑记录本身（`change_log`）是 **T-201** 的 `ChangeLogSubscriber` 的事，
 * 不在本卡。契约里 `DELETE` 的描述提到「墓碑的 audience 取**删除前**的成员快照」
 * —— 那条约束要等 `card_members`（T-110）才有意义，这里没有可快照的成员。
 */
final readonly class DeleteCardService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainException `not_found`（404）/ `insufficient_role`（403）
     */
    public function delete(AuthContext $auth, Uuid $id): void
    {
        $card = $this->cards->find($id);

        if (!$card instanceof Card) {
            // 已经删过的卡走的也是这一支：`find()` 只返回未删除的行。
            // 于是重复 DELETE 是 404 而不是 204 —— 契约没有规定这一条，
            // 而 404 更诚实：客户端手里那个 id 已经不指向任何可操作的东西了。
            throw DomainException::notFound('No such card.');
        }

        if (!$card->isOwnedBy($auth->userId)) {
            // 与 PATCH 同一个码、同一个位置（早于任何其他判断）——
            // 见 UpdateCardService 的类注释。
            throw DomainException::insufficientRole('Only the owner of a card may delete it.');
        }

        $card->softDelete($this->clock->now());

        $this->cards->save($card);
    }
}
