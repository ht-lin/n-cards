<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

/**
 * {@see CreateCardService::create()} 的结果：卡 + 「这次是不是真的建了」。
 *
 * ============================================================================
 * 为什么状态码要由 Application 层告诉 Http 层
 * ============================================================================
 * 契约给 `POST /v1/cards` 定了**两个**成功码（§5.4.3）：
 * `201` 真新建、`200` 幂等重放（该 id 已经属于调用者）。
 * 控制器必须能区分，而它拿到的只有一个 {@see CardView} ——
 * 从里面是看不出来的（两条路径返回的卡长得一模一样）。
 *
 * 让控制器自己去查一次「这张卡是不是刚建的」不行：那要么再打一次库，
 * 要么比 `created_at` 与 `now()`（一个会在慢请求上随机出错的判断）。
 *
 * 所以这个布尔跟着结果一起上来。§12.2 的「Domain / Application 不得了解 HTTP」
 * 没有被破坏 —— 本类说的是「有没有新建」这个**业务事实**，
 * 不是「201 还是 200」。翻译成状态码是 {@see \App\Module\Wallet\Http\CardController}
 * 那一行的事。
 */
final readonly class CardCreated
{
    /**
     * @param bool $wasCreated true = 这次真的建了一张新卡（→ `201` + `Location`）；
     *                         false = 该 id 已经属于调用者，返回的是现有实体（→ `200`）
     */
    public function __construct(
        public CardView $card,
        public bool $wasCreated,
    ) {
    }
}
