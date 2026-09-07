<?php

declare(strict_types=1);

namespace App\Module\Identity\Http;

use App\Module\Identity\Application\Session\DeviceService;
use App\Module\Identity\Application\Session\DeviceView;
use App\Module\Identity\Application\Session\PushTokenPayload;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Http\Controller\AbstractApiController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * 设备管理页的三个端点（§6.2，T-105）。
 *
 * 三个都要 Bearer（走契约的全局 `security: [bearerAuth]`，没有覆盖）。
 *
 * ⚠️ 归属校验**不在这里** —— 它在 {@see DeviceService::ownedDevice()}，
 * 因为 deptrac 里 `Identity.Http` 看不到 `Identity.Domain`，控制器碰不到
 * `Device` 实体也就读不到它的 `user()`。这个限制正好保证了三个端点
 * 不可能各写一份归属判断、而其中一份写错。
 */
final class DeviceController extends AbstractApiController
{
    public function __construct(private readonly DeviceService $service)
    {
    }

    /**
     * ⚠️ **不分页**。§7.5 没有给设备数设限额，但真实上限是个位数
     * （重装即新设备，而人不会重装几百次）。响应体是一个裸对象 `{devices: [...]}`
     * 而不是 `Page`：加游标会让客户端为一个永远只有一页的列表写分页逻辑。
     */
    #[Route('/v1/me/devices', name: 'me_devices_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $devices = $this->service->list($this->authContext($request));

        return $this->json(['devices' => array_map(self::body(...), $devices)]);
    }

    /**
     * 远程登出。撤销设备**并且**撤销它的全部会话 —— 后者才是让那台设备
     * 手里的 refresh token 立刻失效的东西（见 `DeviceService` 的类注释）。
     *
     * 幂等 → 恒 204。允许删自己当前这台（效果等同登出）。
     */
    #[Route('/v1/me/devices/{deviceId}', name: 'me_devices_revoke', methods: ['DELETE'])]
    public function revoke(Request $request, string $deviceId): Response
    {
        $this->service->revoke($this->authContext($request), self::deviceId($deviceId));

        return $this->noContent();
    }

    /**
     * FCM 令牌上报。本任务只落库，不接 FCM（T-306）。
     */
    #[Route('/v1/me/devices/{deviceId}/push-token', name: 'me_devices_push_token', methods: ['PUT'])]
    public function updatePushToken(Request $request, string $deviceId): Response
    {
        $payload = PushTokenPayload::fromArray($this->decodeBody($request));

        $this->service->updatePushToken($this->authContext($request), self::deviceId($deviceId), $payload->pushToken);

        return $this->noContent();
    }

    /**
     * 路径段 → {@see Uuid}。
     *
     * ⚠️ 畸形的 id 返回 **404**，不是 400。理由与 `DeviceService` 里
     * 「不属于你的设备一律 404」完全相同：这个端点对任何一个当前用户拿不到的
     * 设备都必须给出同一个答案，而「你这个 id 格式不对」与「查无此设备」
     * 之间的差别对攻击者是有用的，对正常客户端毫无用处（它的 id 是自己生成的）。
     *
     * 所以这里用 `tryFromString()` 而不是 `fromString()` —— 后者抛 400
     * `validation_failed`。
     */
    private static function deviceId(string $raw): Uuid
    {
        $id = Uuid::tryFromString($raw);

        if (null === $id) {
            throw new DomainException(ErrorCode::NotFound, 'No such device.');
        }

        return $id;
    }

    /**
     * @return array{
     *     id: string,
     *     platform: string,
     *     model: ?string,
     *     os_version: ?string,
     *     app_version: ?string,
     *     is_current: bool,
     *     last_seen_at: string,
     *     created_at: string,
     *     push_token_updated_at: ?string,
     * }
     */
    private static function body(DeviceView $device): array
    {
        return [
            'id' => $device->id->toString(),
            'platform' => $device->platform,
            // 契约里这四个是 `anyOf: [string, "null"]`，**不是**省略该键 ——
            // 省略会让 Android 侧的 kotlinx.serialization 走默认值分支
            // （口径同 OtpVerifyController 里 username 那条注释）。
            'model' => $device->model,
            'os_version' => $device->osVersion,
            'app_version' => $device->appVersion,
            'is_current' => $device->isCurrent,
            // §6.1：时间一律 RFC 3339 UTC。
            'last_seen_at' => self::rfc3339($device->lastSeenAt),
            'created_at' => self::rfc3339($device->createdAt),
            // ⚠️ 只回时间戳，**绝不**回 push_token 本身（见 DeviceView 的类注释）。
            'push_token_updated_at' => null === $device->pushTokenUpdatedAt
                ? null
                : self::rfc3339($device->pushTokenUpdatedAt),
        ];
    }

    private static function rfc3339(\DateTimeImmutable $at): string
    {
        // 显式转时区而不是信任 ClockInterface 的 UTC 约定 —— 这是响应格式，
        // 不该依赖另一个类的注释来成立。
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
