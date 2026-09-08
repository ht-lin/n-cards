<?php

declare(strict_types=1);

namespace App\Shared\Domain\Onboarding;

/**
 * 一枚 access token 背后那个用户，此刻处在 §5.2 状态机的哪一格（T-108）。
 *
 * ============================================================================
 * 为什么是三态枚举而不是 `bool`
 * ============================================================================
 * 「有没有 username」看起来是一个是非题，但调用方（{@see
 * \App\Shared\Infrastructure\Http\OnboardingListener}）必须区分**三**种情形，
 * 而它们的处置各不相同：
 *
 * | case | 处置 |
 * |---|---|
 * | {@see Complete}    | 放行 |
 * | {@see Incomplete}  | `403 username_required` —— 客户端跳 username 设定页 |
 * | {@see UserUnknown} | `401 token_invalid` —— 客户端清会话跳登录 |
 *
 * 第三格不是防御性编程：{@see \App\Shared\Domain\Http\AuthContext} 的类注释
 * 逐字写着「持有它**不**意味着这个 user 还存在」。token 有最长 15 分钟寿命，
 * 而删号流程与 T-113 的僵尸注册行清理都会在那期间把 `users` 那一行删掉。
 *
 * 用 `bool` 的话这一格只能并进 `false`，也就是给一个已经不存在的账号返回
 * `403 username_required` —— 那会把客户端指向 `POST /me/username`（白名单内），
 * 而那条路径上的查找同样落空、抛 500。客户端于是在 403 与 500 之间打转，
 * 且两个状态码都不会让它清掉本地会话。
 *
 * ============================================================================
 * 为什么在 Shared\Domain
 * ============================================================================
 * 与 {@see \App\Shared\Domain\Http\AuthContext} 同一条理由：写入方
 * （`Identity.Infrastructure`）与读取方（`Shared.Infrastructure`）在 deptrac 里
 * 互相看不见，`Shared.Domain` 是唯一的公共地面。它是一个不含任何框架概念的枚举。
 */
enum OnboardingState
{
    /**
     * `users` 里没有这一行。
     *
     * ⚠️ 这**不是**「token 是伪造的」—— 伪造的 token 在
     * {@see \App\Shared\Application\Token\AccessTokenVerifierInterface} 那一层
     * 就被拒了，根本走不到这里。它是「签名有效，但主体已经不在了」。
     */
    case UserUnknown;

    /** `username IS NULL` —— §5.2 的注册中间态。 */
    case Incomplete;

    /** `username` 已设定。这是绝大多数请求走的那一格。 */
    case Complete;
}
