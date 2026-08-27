<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Http\ApiSurface;
use App\Shared\Domain\Http\RequestAttributes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * `X-Client: android/1.4.0 (26)` 的解析与强制（§6.1「客户端标识」一行：**必填**）。
 *
 * ============================================================================
 * ⚠️⚠️ 豁免：只有 `/v1/*` 受约束
 * ============================================================================
 * T-004 的任务书、backend/README.md 与 HealthController 的类注释，三处都留了同一条警告：
 *
 * > `ClientVersionListener` 必须把 `/health/*` 排除在外。T-003 已交付的
 * > `/health/live` 与 `/health/ready` 的调用方是 Docker healthcheck、Caddy 与
 * > §14.3 部署流程里的 Ansible，它们**不带** `X-Client`。漏掉这条豁免，两个探活
 * > 端点会在本任务合入当天集体变 400，连带 compose 起栈与 staging 部署的健康检查
 * > 一起失效。
 *
 * 这里**不**照字面写成「排除 /health/*」—— 那是黑名单，下一条非 `/v1` 路由必然被漏掉
 * （任务书自己也说「同样的豁免逻辑将来还要覆盖 T-007 之后的任何非 `/v1` 端点」）。
 * 改成正向白名单 {@see ApiSurface::isProductApiPath()}：只有 `/v1/` 受约束，
 * 别的一律豁免，不需要任何人记得去登记。
 *
 * 真正的强制点是 `tests/Api/RouteInventoryTest` —— 新增非 `/v1` 路由而不主动登记就 CI 红。
 *
 * ============================================================================
 * ⚠️ 优先级 40：**早于** RouterListener（32）
 * ============================================================================
 * 如果跑在路由之后，`POST /v1/typo` 缺 header 会先 404 —— 客户端看到的是
 * 「端点不存在」而不是「你漏了 header」，排查方向直接被带偏。
 * 早于路由也顺带绕开了「路由没匹配就没有 `_route` 属性」的问题：
 * 这里按 `getPathInfo()` 判，不依赖路由结果。
 *
 * 代价是无法按路由名做单个豁免 —— 而这正合我们意，粒度就是 `/v1/*` 对其余。
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: self::PRIORITY)]
final readonly class ClientVersionListener
{
    public const HEADER = 'X-Client';

    /** 早于 RouterListener(32)，晚于 RequestIdListener(512)。由 ListenerOrderTest 钉死。 */
    public const PRIORITY = 40;

    private ClientVersion $minimum;

    public function __construct(
        #[Autowire('%ncards.min_supported_client%')]
        string $minimumSupported,
    ) {
        // 在构造期解析：配错了要在容器编译/首次实例化时就炸，而不是等到
        // 某个真实请求进来才发现最低版本是个垃圾字符串。
        $this->minimum = ClientVersion::parse($minimumSupported);
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // ⚠️ 这两行的顺序与形状在四个横切监听器里是一致的。照抄，别自创。
        if (!ApiSurface::isProductApiPath($request->getPathInfo())) {
            return;
        }

        $header = $request->headers->get(self::HEADER);

        if (null === $header || '' === trim($header)) {
            // 任务书：「缺失即 400」。§6.1 没有为「缺 header」设专用 code，
            // 用 validation_failed + 字段级 required 说明是哪个 header。
            throw DomainException::validationFailed(new FieldError(self::HEADER, FieldErrorCode::Required, 'The X-Client header is required.'));
        }

        // 格式不对时 ClientVersion::parse 自己抛 validation_failed + invalid_format。
        $client = ClientVersion::parse($header);

        if (!$client->isAtLeast($this->minimum)) {
            // §6.1：426 client_too_old → 客户端弹强制升级墙。
            throw new DomainException(ErrorCode::ClientTooOld, \sprintf('Client %s is below the minimum supported version %s.', $client, $this->minimum));
        }

        $request->attributes->set(RequestAttributes::CLIENT_VERSION, $client);
    }

    /**
     * 读取本次请求解析出的客户端版本。
     *
     * 只有 `/v1/*` 会有值 —— 非 `/v1` 端点上恒为 null（那是豁免的直接后果，不是 bug）。
     */
    public static function readFrom(Request $request): ?ClientVersion
    {
        $client = $request->attributes->get(RequestAttributes::CLIENT_VERSION);

        return $client instanceof ClientVersion ? $client : null;
    }
}
