<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Sharing\Application\Port\CardOwnershipRegistrarInterface;
use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\Repository\CardRepositoryInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Shared\Domain\Limit\SystemLimit;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/cards`（§5.4.3，T-109）。
 *
 * ============================================================================
 * 天然幂等：三条出路，由 `id` 的归属决定
 * ============================================================================
 * | `id` 的状态 | 结果 |
 * |---|---|
 * | 库里没有 | 建卡，`201` + `Location` |
 * | 已存在，`owner_id` 是调用者 | **什么都不改**，`200` + 现有实体 |
 * | 已存在，属于别人 | `409 id_conflict`，客户端重新生成 id 重试 |
 *
 * 第二条为什么不顺手把请求体应用上去：改卡有 `PATCH`，而 `PATCH` 有
 * `If-Match` 保护。一个迟到的离线 outbox 条目（§5.4.3：网络恢复后重放整个队列）
 * 带着几小时前的 body 打过来，如果这里应用它，就会把用户后来改过的标题
 * **静默覆盖回去** —— 而且没有任何冲突检测会发现，因为建卡请求根本不带 revision。
 *
 * ⚠️ 这与 `Idempotency-Key`（{@see \App\Shared\Infrastructure\Http\IdempotencyMiddleware}）
 * 是**两层**，互不冲突也互不替代：那一层按请求指纹回放整个响应，
 * 只在客户端带了那个头时生效；这一层是资源本身的性质，不带头也成立。
 * 本服务不需要为那一层写任何代码。
 *
 * ============================================================================
 * ⚠️ 软删的 id 仍然算「被占用」
 * ============================================================================
 * 所以这里用 {@see CardRepositoryInterface::findIncludingDeleted()} 而不是
 * `find()`。用 `find()` 的话，重放一个建卡请求会看到「没有这张卡」，
 * 走进 INSERT，然后撞主键冲突 —— 一个 500，而不是契约写的 200 / 409。
 *
 * ⚠️ **幂等重放这一支也不补成员行**（T-110）。三条理由：
 *
 *   1. 它一旦写就不再是重放：要自己的事务、自己的 `uq_card_single_owner` 失败
 *      处理、以及一个「200 到底意味着什么」的新答案。上面那条不变量存在的
 *      理由（迟到的 outbox 条目不得静默改动服务端状态）对成员行同样成立。
 *   2. 「卡有、owner 行没有」这个状态**不该存在**，而消除它的地方是 T-110 的
 *      迁移（它把 `cards` 全表回填了，含软删的）。在这里补等于把破损盖掉，
 *      而且盖在生产里最不会被走到的那条分支上。
 *   3. 这一支会返回**软删**的卡（见下）。给它补一行 `left_at IS NULL` 的 owner
 *      记录，等于恢复了成员关系却没恢复卡本身 —— 正好毒化 M3 的 audience 快照
 *      与 `uq_card_single_owner` 的不变量。
 *
 * 真出现这种破损，它会在读路径上炸（{@see CardViewAssembler} 的
 * `assertComplete()`），而不是在写路径上被悄悄补上。
 *
 * 命中软删行时返回的是那张**已删除**的卡（`200`），**不复活它**：
 * 复活需要一个明确的产品决定（§5.4.3 没有「取消删除」这个操作），
 * 而在一个建卡端点上悄悄实现它，等于让「删卡」变得可以被一次网络重试撤销。
 * 客户端看到的是一张它刚建过的卡，而它的本地副本会在下一次 `/sync`
 * 收到墓碑时被清掉（T-201）。
 *
 * ============================================================================
 * 限额：三条长度在这里，卡数留给 T-111
 * ============================================================================
 * {@see LimitEnforcer::enforceLength()} 的三项（§7.5：title 100 字符、
 * note 2000 字符、payload 1024 **字节**）在这里挡住，因为
 * `cards.title` 在库层带 `CHECK (char_length(title) <= 100)` ——
 * 不挡的话那是一个 **500**，而不是契约写的 `422 limit_exceeded`。
 *
 * `cards_per_user`（500）也在这里，但它要一次 `COUNT(*)`；T-111 的交付物
 * 是「在写入路径强制 §7.5 限额」，本卡把强制点建好，T-111 补它的边界测试。
 */
final readonly class CreateCardService
{
    public function __construct(
        private CardRepositoryInterface $cards,
        private CardSecrets $secrets,
        private CardViewAssembler $assembler,
        private LimitEnforcer $limits,
        private ClockInterface $clock,
        private CardOwnershipRegistrarInterface $members,
        private TransactionRunnerInterface $transactions,
    ) {
    }

    /**
     * @throws DomainException `id_conflict`（409）/ `limit_exceeded`（422）
     */
    public function create(AuthContext $auth, CardCreatePayload $payload): CardCreated
    {
        $existing = $this->cards->findIncludingDeleted($payload->id);

        if (null !== $existing) {
            if (!$existing->isOwnedBy($auth->userId)) {
                throw DomainException::idConflict();
            }

            // 幂等命中：不碰任何字段，也不碰 revision。
            return new CardCreated($this->view($existing, $auth), false);
        }

        $this->enforceLimits($auth, $payload);

        [$barcodeEncrypted, $fingerprint] = $this->secrets->barcode($payload->barcodeValue);

        // ⚠️ 一个 `now()`，两处用。`card_members.joined_at` 必须与
        // `cards.created_at` **逐字相同** —— 读两次时钟会让同一个逻辑事件
        // 得到相差几微秒的两个时间戳，而 M2 的 change_log 排序会在意。
        $now = $this->clock->now();

        $card = Card::create(
            $payload->id,
            $auth->userId,
            $payload->title,
            $payload->merchantLabel,
            $payload->color,
            $payload->barcodeFormat,
            $barcodeEncrypted,
            $fingerprint,
            $this->secrets->note($payload->note),
            $payload->expiresOn,
            $now,
        );

        // ⚠️ 事务里**只有这两次写**。上面的 `enforceLimits()`（一次 COUNT(*)）与
        // `secrets->barcode()`（一次 Vault 往返）、下面的 `view()`（又一次 Vault
        // 往返）都刻意留在外面：把 PG 事务开着跨越一次 Vault 往返，等于把 Vault
        // 的每一次延迟毛刺变成持有中的行锁与连接。
        //
        // 形状同 `Identity\Application\Session\SessionIssuer` 那段「三张表同生共死」；
        // `use_savepoints: true`（doctrine.yaml）让嵌套安全。
        //
        // ⚠️ 顺序不能反：`card_members.card_id → cards(id)` 是真外键，
        // 先插成员行会撞 ForeignKeyConstraintViolationException。
        //
        // ⚠️ T-201 注意：§5.4 要求「任何对 `cards` / `card_members` 的写入必须在
        // **同一事务内**写 `change_log`」。`ChangeLogSubscriber` 要挂的就是这个
        // 事务边界 —— 别再开第二个。
        $this->transactions->run(function () use ($card, $auth, $now): void {
            $this->cards->save($card);

            // 单人钱包阶段也必须有这一行（T-110 任务卡逐字）：M3 的邀请、
            // 级联撤销、change_log audience 全都以「每张卡都有一行 owner 成员
            // 记录」为前提，等到那时再补就是一次数据迁移。
            $this->members->registerOwner($card->id(), $auth->userId, $now);
        });

        return new CardCreated($this->view($card, $auth), true);
    }

    /**
     * @throws DomainException `limit_exceeded`（422）
     */
    private function enforceLimits(AuthContext $auth, CardCreatePayload $payload): void
    {
        $this->limits->enforceLength(SystemLimit::TitleChars, $payload->title);
        $this->limits->enforceLength(SystemLimit::BarcodePayloadBytes, $payload->barcodeValue);

        if (null !== $payload->note) {
            $this->limits->enforceLength(SystemLimit::NoteChars, $payload->note);
        }

        // ⚠️ 计数只统计 `owner_id = :user`（§17.5 Q11）—— 共享给他的卡不计入，
        // 否则 owner 可以通过共享消耗别人的配额。约束落在仓储的
        // `countOwnedBy()` 上，别在这里改成「我能看到的全部卡」。
        //
        // 这一步排在长度校验**之后**：它要一次 COUNT(*)，而一个超长标题
        // 不该先花掉一次查询。
        $this->limits->enforceCanAdd(SystemLimit::CardsPerUser, $this->cards->countOwnedBy($auth->userId));
    }

    private function view(Card $card, AuthContext $auth): CardView
    {
        // 单张卡也走批量入口 —— 见 CardViewAssembler 的类注释。
        return $this->assembler->assemble([$card], $auth->userId)[0];
    }
}
