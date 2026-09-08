<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Me\ProfileService;
use App\Module\Identity\Application\Me\ProfileUpdatePayload;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET /v1/me` 与 `PATCH /v1/me`（§6.2，T-108）。
 *
 * ============================================================================
 * ⚠️ 两个方法，但**只有 `GET` 在 onboarding 白名单里**
 * ============================================================================
 * `me_get` 是 {@see \App\Shared\Infrastructure\Http\OnboardingListener::EXEMPT_ROUTES}
 * 的三条之一，`me_update` 不是。这不是疏漏，是 §5.2 与 §6.2 的清单逐字如此：
 *
 *   - `GET` 必须可达 —— 客户端正是靠它的 `onboarding_complete: false` 才知道
 *     自己卡在注册中间态、该去 username 设定页（该页不可跳过、不可返回）。
 *     把它挡住，客户端问不出这个问题，注册流程走不完。
 *   - `PATCH` 必须挡住 —— 在 username 设定之前开一条写 `users` 的路径，
 *     正好是那个拦截器存在的理由。而且它挡住也不损失什么：注册期的语言由
 *     `POST /v1/auth/otp/request` 的 `locale` 字段决定。
 *
 * ============================================================================
 * ⚠️ 这里永远不会有 username 的写入路径
 * ============================================================================
 * `PATCH` 收到 `username` 字段时返回 `409 username_immutable`（§6.2：
 * **不静默忽略**），判定在 {@see ProfileUpdatePayload::fromArray()} 的第一行。
 * 想让它「顺手支持改名」之前先读 {@see UsernameController} 的类注释与 §17.5 Q10
 * —— 不可变性是靠「没有这个端点」保证的，而这个控制器是最容易把它做没的地方。
 *
 * `display_name` 同理：v1.1 C10 把它从整个产品里移除了，契约的 `User` 与
 * `MeUpdate` 里都没有它，这里也不会有。
 *
 * ============================================================================
 * 一个控制器两个路由，与 UsernameController 的「只能有一个方法」不冲突
 * ============================================================================
 * 那条约束说的是「不许存在第二个写 username 的端点」，不是「一个控制器一个方法」
 * —— `DeviceController` 就有三个。这里的两个路由是同一个资源的读与写。
 */
final class MeController extends AbstractApiController
{
    public function __construct(private readonly ProfileService $service)
    {
    }

    #[Route('/v1/me', name: 'me_get', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        return $this->json(['user' => UserBody::of($this->service->profile($this->authContext($request)))]);
    }

    #[Route('/v1/me', name: 'me_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $payload = ProfileUpdatePayload::fromArray($this->decodeBody($request));

        $profile = $this->service->update($this->authContext($request), $payload);

        // 返回**完整**资料而不是 204：客户端拿它直接替换本地副本，
        // 不需要紧接着再拉一次 `GET /me`。与 `POST /me/username` 同一个
        // `UserEnvelope`，见 {@see UserBody}。
        return $this->json(['user' => UserBody::of($profile)]);
    }
}
