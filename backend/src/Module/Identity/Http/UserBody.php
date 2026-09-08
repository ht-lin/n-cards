<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Me\UserProfile;

/**
 * 契约 `User` schema 的**唯一**一份组装代码（T-108）。
 *
 * 三个生产者共用它：`GET /v1/me`、`PATCH /v1/me`（{@see MeController}）
 * 与 `POST /v1/me/username`（{@see UsernameController}）。
 * T-107 的 {@see UserProfile} 类注释与 `UsernameController::body()` 都写着
 * 「别写第三份」—— 本类就是那句话的落点：契约里 `User` 是一个 schema，
 * 服务端有两份组装代码的话，加字段时必然漏一处，而漏掉的那个端点会安静地
 * 少返回一个键。
 *
 * ============================================================================
 * 为什么收 DTO 而不是实体
 * ============================================================================
 * deptrac 里 `Identity.Http` **看不到** `Identity.Domain`，所以这里拿不到
 * `User` 也读不了它的 getter。这个限制正好把「控制器不许有业务」从约定变成
 * 机械强制 —— 本类因此只能是一层字段搬运。
 *
 * ============================================================================
 * ⚠️ 还有三份同形的搬运，本卡**没有**合并它们
 * ============================================================================
 * `OtpVerifyController` / `MagicConsumeController` / `TokenRefreshController`
 * 的 `body()` 里各有一个 `user` 对象，五个字段与这里逐字相同。
 * 它们搬的是 {@see \App\Module\Identity\Application\Session\SessionIssued} ——
 * **另一个 DTO**（「一对新令牌 + 附带的用户资料」）。要合并得先让
 * `SessionIssued` 持有一个 `UserProfile`，那会牵动三个控制器、三个服务
 * 与它们的测试，与本卡的交付物无关。
 *
 * 合并它的人要知道：`SessionIssued` 的类注释上挂着「别把整个 DTO 丢进日志」
 * 那条约束（它带着 refresh token），把 `UserProfile` 塞进去之前先读那一段。
 */
final class UserBody
{
    private function __construct()
    {
    }

    /**
     * @return array{
     *     id: string,
     *     username: ?string,
     *     locale: string,
     *     onboarding_complete: bool,
     *     created_at: string,
     * }
     */
    public static function of(UserProfile $profile): array
    {
        return [
            'id' => $profile->userId->toString(),
            // 契约里 `User.username` 是 `anyOf: [Username, "null"]` ——
            // §5.2 的注册中间态让 null 是一个真实存在的值，而且 `GET /v1/me`
            // 正是**专门**为了让客户端看到它才被放进 onboarding 白名单的。
            // ⚠️ null 时也要**发出这个键**，不是省略它：省略会让 Android 侧的
            // kotlinx.serialization 走默认值分支。
            'username' => $profile->username,
            'locale' => $profile->locale,
            'onboarding_complete' => $profile->onboardingComplete,
            // §6.1：时间一律 RFC 3339 UTC。显式转时区而不是信任 ClockInterface
            // 的 UTC 约定 —— 这是响应格式，不该依赖另一个类的注释来成立。
            'created_at' => $profile->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
