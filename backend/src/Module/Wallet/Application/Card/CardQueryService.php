<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;

/**
 * `GET /v1/cards` 与 `GET /v1/cards/{id}`（§6.2，T-109）。
 *
 * 两个操作一个服务：它们读同一张表、共用同一个 {@see CardViewAssembler}，
 * 拆开只会得到两个各三行的类。口径同 `Identity\Application\Me\ProfileService`。
 *
 * ============================================================================
 * ⚠️ 非 owner 读一张卡是 `403 not_a_member`，不是 404
 * ============================================================================
 * 契约逐字：「非成员 → `403 not_a_member`，客户端应据此**从本地删除该卡**
 * （说明共享已被撤销）」。所以这个码是有产品含义的，不能为了「不泄露存在性」
 * 改成 404 —— 改了客户端就分不清「这张卡被撤销共享了」与「这张卡被删了」，
 * 而后者本来就该走 `/sync` 的墓碑。
 *
 * 泄露面很窄：要问出「这个 UUIDv7 存在吗」，得先猜中一个 122 位的 id。
 *
 * ⚠️ 与 `PATCH` / `DELETE` 的 `403 insufficient_role` 是**两个码**，别合并 ——
 * 见 {@see DomainException::insufficientRole()} 的注释。
 * T-109 阶段「非 owner」与「非成员」是同义的（没有成员表），
 * T-110 接上 `card_members` 后这里会分成两支。
 */
final readonly class CardQueryService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardViewAssembler $assembler,
    ) {
    }

    /**
     * 钱包列表的一页。
     *
     * ⚠️ **一次**批量解密，不管这一页有多少张卡 —— 这是 §5.3 的硬要求，
     * 强制点在 {@see CardViewAssembler}（它没有单条入口）。
     *
     * @param int $limit 仓储要取的行数（= 页大小 + 1，见 `PageRequest::fetchLimit()`）
     *
     * @return list<CardView>
     */
    public function page(AuthContext $auth, ?Uuid $after, int $limit): array
    {
        return $this->assembler->assemble(
            $this->cards->findOwnedPage($auth->userId, $after, $limit),
            $auth->userId,
        );
    }

    /**
     * @throws DomainException `not_found`（404）/ `not_a_member`（403）
     */
    public function get(AuthContext $auth, Uuid $id): CardView
    {
        $card = $this->cards->find($id);

        if (!$card instanceof Card) {
            throw DomainException::notFound('No such card.');
        }

        if (!$card->isOwnedBy($auth->userId)) {
            throw new DomainException(ErrorCode::NotAMember, 'You are not a member of this card; drop your local copy.');
        }

        return $this->assembler->assemble([$card], $auth->userId)[0];
    }
}
