<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Shared\Domain\Limit\SystemLimit;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `PATCH /v1/cards/{id}` —— 乐观锁下的部分更新（§5.4.3，T-109）。
 *
 * ============================================================================
 * ⚠️⚠️ 检查顺序是**语义的一部分**，不是随手排的
 * ============================================================================
 * 任务卡逐字：「`PATCH`/`DELETE` 对非 owner **一律** `403 insufficient_role`，
 * **即使请求体合法、revision 正确**」。于是：
 *
 * ```
 * 1. 查不到 / 已软删            → 404 not_found
 * 2. 不是 owner                 → 403 insufficient_role     ← 早于 3、4
 * 3. 长度超限                   → 422 limit_exceeded
 * 4. revision 不匹配            → 409 revision_conflict
 * ```
 *
 * 把 2 排在 4 后面的后果是**信息泄露**：一个非成员可以拿不同的 `If-Match`
 * 反复试，从「409 带 current」里读出这张卡的 revision 与 updated_at，
 * 而 409 的 problem body 里那个 `current` 正是为合法成员准备的。
 *
 * 把 2 排在 3 后面则会把「你的标题太长了」告诉一个根本无权写这张卡的人 ——
 * 无害得多，但同样没有理由。
 *
 * ⚠️ 请求体的**格式**校验（400）比这四步都早：它在
 * {@see CardUpdatePayload::fromArray()} 里，由控制器在进本服务之前调用。
 * 那一步不查库，也不知道这张卡是谁的，所以它不构成上面那条泄露 ——
 * 一个畸形的 JSON body 对任何 id 都是 400。
 *
 * ============================================================================
 * revision 的比较交给 Doctrine，这里只传值
 * ============================================================================
 * 见 {@see CardRepositoryInterface::save()} 的实现：`EntityManager::lock()`
 * 挡住「载入时就已经不匹配」，Doctrine 自动加的 `WHERE revision = :old`
 * 挡住「载入与 flush 之间被人改了」。两层都翻译成
 * `DomainException::revisionConflict()`。
 *
 * 本服务因此**不写** `if ($card->revision() !== $expected)` —— 写了的话
 * 那两层保护里的第二层就成了一段永远走不到的代码，
 * 而它恰恰是并发下唯一有用的那层。
 */
final readonly class UpdateCardService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardSecrets $secrets,
        private CardViewAssembler $assembler,
        private LimitEnforcer $limits,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param int $expectedRevision 客户端 `If-Match` 里那个（见 `Shared\Http\Concurrency\IfMatch`）
     *
     * @throws DomainException `not_found`（404）/ `insufficient_role`（403）
     *                         / `limit_exceeded`（422）/ `revision_conflict`（409）
     */
    public function update(AuthContext $auth, Uuid $id, CardUpdatePayload $payload, int $expectedRevision): CardView
    {
        $card = $this->cards->find($id);

        if (!$card instanceof Card) {
            throw DomainException::notFound('No such card.');
        }

        if (!$card->isOwnedBy($auth->userId)) {
            // ⚠️ 早于 revision 与长度检查 —— 见类注释。
            throw DomainException::insufficientRole('Only the owner of a card may modify it.');
        }

        $this->enforceLimits($payload);

        $this->apply($card, $payload);

        $this->cards->saveWithRevision($card, $expectedRevision);

        return $this->assembler->assemble([$card], $auth->userId)[0];
    }

    /**
     * @throws DomainException `limit_exceeded`（422）
     */
    private function enforceLimits(CardUpdatePayload $payload): void
    {
        if (null !== $payload->title) {
            $this->limits->enforceLength(SystemLimit::TitleChars, $payload->title);
        }

        if (null !== $payload->barcodeValue) {
            $this->limits->enforceLength(SystemLimit::BarcodePayloadBytes, $payload->barcodeValue);
        }

        if (null !== $payload->note) {
            $this->limits->enforceLength(SystemLimit::NoteChars, $payload->note);
        }
    }

    /**
     * 把 payload 里**出现过**的字段落到实体上。
     *
     * ⚠️ 三个可空字段判的是 `*Present` 而不是「值非 null」——
     * 「没给 note」与「把 note 设成 null」是两件事，合并的后果是
     * 「只改标题的 PATCH 顺手把备注清空了」。见 {@see CardUpdatePayload} 的类注释。
     */
    private function apply(Card $card, CardUpdatePayload $payload): void
    {
        $now = $this->clock->now();

        if (null !== $payload->title) {
            $card->rename($payload->title, $now);
        }

        if (null !== $payload->color) {
            $card->changeColor($payload->color, $now);
        }

        if (null !== $payload->barcodeFormat) {
            $card->changeBarcodeFormat($payload->barcodeFormat, $now);
        }

        if (null !== $payload->barcodeValue) {
            // 密文与指纹只能一起换 —— 见 CardSecrets::barcode() 的注释。
            [$encrypted, $fingerprint] = $this->secrets->barcode($payload->barcodeValue);
            $card->changeBarcodeValue($encrypted, $fingerprint, $now);
        }

        if ($payload->merchantLabelPresent) {
            $card->changeMerchantLabel($payload->merchantLabel, $now);
        }

        if ($payload->notePresent) {
            $card->changeNote($this->secrets->note($payload->note), $now);
        }

        if ($payload->expiresOnPresent) {
            $card->changeExpiry($payload->expiresOn, $now);
        }
    }
}
