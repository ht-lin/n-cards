<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Me;

use App\Module\Identity\Domain\Entity\User;
use App\Shared\Domain\Identity\Uuid;

/**
 * 调用者自己的资料（契约里的 `User` schema）。
 *
 * ============================================================================
 * 为什么是扁平的一堆标量，而不是捎上 User 实体
 * ============================================================================
 * 与 {@see \App\Module\Identity\Application\Session\SessionIssued} 同一条：
 * deptrac 里 `Identity.Http` **看不到** `Identity.Domain`，控制器拿不到
 * {@see User} 也读不了它的 getter。把实体塞进这个 DTO，控制器编译不过。
 *
 * 这个限制正好把「控制器不许有业务」从约定变成机械强制 ——
 * 组响应体因此只能是一层字段搬运。
 *
 * ============================================================================
 * 三个生产者，一个形状
 * ============================================================================
 * `POST /v1/me/username`（T-107，本卡）、`GET /v1/me` 与 `PATCH /v1/me`（T-108）
 * 的响应里都是同一个 `User` schema。T-108 直接复用本类与
 * `UsernameController::body()` 里那段搬运，**不要**再写第二份 ——
 * 契约里 `User` 是一个 schema，服务端有两份组装代码的话，加字段时必然漏一处。
 *
 * ⚠️ `SessionIssued` 里那五个同名字段**不**合并到本类：那个 DTO 描述的是
 * 「一对新令牌 + 附带的用户资料」，把 `accessToken` / `refreshToken` 拉进
 * 一个叫 UserProfile 的东西里，会让「别把整个 DTO 丢进日志」那条约束
 * （见 SessionIssued 的类注释）跟着扩散到本来无害的地方。
 */
final readonly class UserProfile
{
    /**
     * @param string|null $username           §5.2：注册中间态为 null
     * @param string      $locale             `de` / `en`
     * @param bool        $onboardingComplete 等价于 `null !== $username`（契约写死了这条等价）
     */
    public function __construct(
        public Uuid $userId,
        public ?string $username,
        public string $locale,
        public bool $onboardingComplete,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * ⚠️ 唯一的构造入口应该是这里，而不是让每个调用点自己拆实体 ——
     * `onboardingComplete` 与 `username` 的等价关系（契约写死的那条）
     * 在这一处成立，就到处成立。
     */
    public static function of(User $user): self
    {
        return new self(
            $user->id(),
            $user->username(),
            $user->locale()->value,
            $user->hasUsername(),
            $user->createdAt(),
        );
    }
}
