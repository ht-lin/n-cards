<?php

declare(strict_types=1);

namespace App\Shared\Application\Config;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Config\MaintenanceWindow;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Time\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `GET /v1/config` 的唯一数据源（T-112）。
 *
 * ============================================================================
 * ⚠️⚠️ `min_supported_client` 与 426 的阈值必须是**同一个参数**
 * ============================================================================
 * `config/services.yaml` 里那条参数的注释写的是：
 *
 * > §6.1 的强制升级基线：低于此版本的客户端一律 426 client_too_old。
 * > ClientVersionListener 做判定，T-112 的 GET /v1/config 读**同一个参数**下发给
 * > 客户端 —— 两处必须同源，否则会出现「服务端拒了但客户端不知道该升到哪个版本」。
 *
 * 所以这里注入的是 `%ncards.min_supported_client%`，不是一个新的
 * `ncards.config.min_supported_client`。两个参数的版本里，漂了之后的症状是
 * 客户端拿着服务端下发的「最低版本」却仍然被拒，而它没有任何办法判断是谁错了。
 * `tests/Api/ConfigEndpointTest` 有一条专门钉这个同源性。
 *
 * ============================================================================
 * ⚠️ 构造期解析，且配置错误一律 LogicException
 * ============================================================================
 * 形状与理由都与 {@see \App\Shared\Infrastructure\Http\ClientVersionListener} 的
 * 构造器逐字相同（那里的长注释是本段的完整版，别重写一遍论证，去读它）：
 *
 *   - 在构造期炸，是为了配错的值在首次实例化时就显形，而不是等某个真实请求进来；
 *   - **必须**把 `ClientVersion::parse()` 抛的 `DomainException` 换成
 *     `\LogicException`。前者是 `validation_failed` + `FieldError('X-Client')`，
 *     那套语义是给**客户端发来的 header** 准备的；原样冒出去会变成一个 400，
 *     指着客户端说「你的 X-Client 格式不对」，按 `ErrorCode::logLevel()` 记 info，
 *     §14.4 的 5xx 告警一声不响。换成 `LogicException` 归到 `internal_error(500)`，
 *     按 error 记日志，告警照响 —— 配错的服务端就该以 5xx 示人。
 *
 * ============================================================================
 * ⚠️ 但爆炸半径与 ClientVersionListener **不一样**，别照抄那段结论
 * ============================================================================
 * 那个监听器挂在 `kernel.request` 上，`EventDispatcher::sortListeners()` 会在调用
 * 任何监听器之前实例化它，所以一个配坏的 `MIN_SUPPORTED_CLIENT` 会让**每个**请求
 * 500，`/health/live` 与 `/health/ready` 一起倒，compose healthcheck 与 §14.3 的
 * Ansible 部署因此当场失败。
 *
 * 本类是**控制器的依赖**，只在 `/v1/config` 被打到时才构造。于是：
 *
 *   - 配坏 `LATEST_CLIENT` 或维护窗口 → 只有 `/v1/config` 500，`/health/ready`
 *     照样 200（实测如此），Ansible 的健康门禁**不会**拦住这次部署；
 *   - 唯一的安全网是 §14.4 对 5xx 的告警，以及客户端那边「配置拉不到」。
 *
 * 这是**刻意接受**的，不是疏漏：把本类做成一项 `HealthCheckInterface` 就能让
 * `/health/ready` 红，但那意味着**一个维护公告的时间戳打错字会让整个 API 下线**。
 * 对一个纯咨询性的字段，这个代价远大于收益 —— 降级成「公告发不出去」是对的取舍。
 * `/health/ready` 的职责是基础设施依赖（PG / Redis / Vault），不是配置审校。
 *
 * ============================================================================
 * 为什么 `latest < min` 也是配置错误
 * ============================================================================
 * 任务卡没提这条。但这两个数字一起下发时，`latest_client < min_supported_client`
 * 的含义是「请升级到一个已经不被支持的版本」—— 客户端照做之后仍然是 426，
 * 而它会以为自己已经是最新的。这个状态没有任何合理用途，配出来只可能是手误
 * （比如改了 min 忘了改 latest），所以在构造期就拦掉。
 */
final readonly class ClientConfigProvider
{
    private ClientVersion $minSupportedClient;

    private ClientVersion $latestClient;

    private MaintenanceWindow $window;

    /**
     * @param string      $minSupportedClient `%ncards.min_supported_client%` —— 与 426 的阈值同源
     * @param string      $latestClient       `%ncards.latest_client%`
     * @param string|null $windowStart        `%ncards.maintenance.window_start%`，留空即无窗口
     * @param string|null $windowEnd          `%ncards.maintenance.window_end%`
     *
     * @throws \LogicException 任一配置项不合法
     */
    public function __construct(
        #[Autowire('%ncards.min_supported_client%')]
        string $minSupportedClient,
        #[Autowire('%ncards.latest_client%')]
        string $latestClient,
        #[Autowire('%ncards.maintenance.window_start%')]
        ?string $windowStart,
        #[Autowire('%ncards.maintenance.window_end%')]
        ?string $windowEnd,
        private ClockInterface $clock,
    ) {
        $this->minSupportedClient = self::parseVersion('MIN_SUPPORTED_CLIENT', $minSupportedClient);
        $this->latestClient = self::parseVersion('LATEST_CLIENT', $latestClient);

        if (!$this->latestClient->isAtLeast($this->minSupportedClient)) {
            throw new \LogicException(\sprintf('LATEST_CLIENT (%s) is below MIN_SUPPORTED_CLIENT (%s) — that would tell clients to upgrade to a version the server already rejects.', $this->latestClient, $this->minSupportedClient));
        }

        $this->window = MaintenanceWindow::fromIso($windowStart, $windowEnd);
    }

    public function current(): ClientConfig
    {
        return new ClientConfig(
            $this->minSupportedClient,
            $this->latestClient,
            $this->window->statusAt($this->clock->now()),
        );
    }

    private static function parseVersion(string $variable, string $raw): ClientVersion
    {
        try {
            return ClientVersion::parse($raw);
        } catch (DomainException $e) {
            throw new \LogicException(\sprintf('%s is not a valid client version: "%s". Expected something like "android/1.4.0 (26)".', $variable, $raw), 0, $e);
        }
    }
}
