<?php

declare(strict_types=1);

namespace App\Module\Sharing\Application\Port;

use App\Module\Sharing\Application\Dto\CardMembershipMap;
use App\Shared\Domain\Identity\Uuid;

/**
 * 「这个人在这批卡上分别是什么角色、摆在哪」——**只读**（§5.2，T-110）。
 *
 * ============================================================================
 * ⚠️ 批量是硬要求，而且本接口**故意没有单条版本**
 * ============================================================================
 * 与 {@see \App\Shared\Application\Crypto\BatchDecryptorInterface} 逐字同源的
 * 设计：留一个 `membershipFor($userId, $cardId)` 就等于留了一个会被抄进
 * foreach 的示范，而那正是要拦的写法。
 *
 * §9.1 的预算说明了为什么：`GET /v1/sync/bootstrap`（200 张卡）P95 ≤ 700 ms，
 * 而 `GET /v1/cards` 一页也能到那个量级。200 次主键查找不会把预算吃光，
 * 但它是那种「加了一张表就多一轮 N+1」的形状，加到第三张表就晚了。
 *
 * 单卡场景（`GET /v1/cards/{id}`、placement 端点）传一个只有一项的数组。
 *
 * ============================================================================
 * 只看得见**活跃**成员
 * ============================================================================
 * 实现恒带 `left_at IS NULL`。墓碑行（被移除 / 已退出 / 好友解除被级联撤销）
 * 对这个接口不存在 —— 漏掉它的症状是**被移除的成员仍然能看到卡**，
 * 也就是 §7.2 的 T20（权限残留）。
 *
 * ============================================================================
 * 缺席不是错误
 * ============================================================================
 * 不是成员的卡**不出现在结果里**，本接口不抛。两个调用方对「缺席」的处置不同：
 *
 *   - {@see \App\Module\Wallet\Application\Card\UpdateCardPlacementService}：
 *     缺席 = `403 not_a_member`，这是那个端点的鉴权判据。
 *   - {@see \App\Module\Wallet\Application\Card\CardViewAssembler}：
 *     缺席 = 数据破损（调用方已经判过权限了），一个 500。
 *
 * 把判断塞进本接口，这两处就得共用一个答案，而它们的答案不一样。
 */
interface CardMembershipReaderInterface
{
    /**
     * @param Uuid       $userId  视角。结果恒为「他自己的」那一行，不含别人的
     *                            （§5.2 的成员可见性 C11：viewer 之间互不可见）
     * @param list<Uuid> $cardIds 可含重复。**空数组 → 不查库**，返回空 map
     *
     * @return CardMembershipMap 不是成员的卡不出现在里面
     */
    public function membershipsFor(Uuid $userId, array $cardIds): CardMembershipMap;
}
