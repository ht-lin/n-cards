<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Config;

use App\Shared\Application\Config\ClientConfigProvider;
use App\Shared\Domain\Config\MaintenanceMessageKey;
use App\Shared\Domain\Error\DomainException;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `GET /v1/config` 的数据源：配置错误的处置，与维护三态（T-112）。
 *
 * ============================================================================
 * ⚠️ 本文件的重点是「配错了会怎样」，不是「配对了会怎样」
 * ============================================================================
 * 配对了是一条直路（三个值换成一个 DTO），Api 层的用例已经端到端覆盖。
 * 而配错了有两种截然不同的结局，差别全在**异常类型**上：
 *
 *   - `DomainException` → 4xx，按 `ErrorCode::logLevel()` 记 info，§14.4 的 5xx
 *     告警一声不响，而响应会指着客户端说是它的错；
 *   - `LogicException` → `internal_error(500)`，按 error 记日志，§14.4 的告警照响。
 *     （⚠️ 但**不会**让 `/health/ready` 变红 —— 本类只在 `/v1/config` 被打到时
 *     才构造。爆炸半径与 `ClientVersionListener` 的差别见那个类的注释。）
 *
 * 服务端配置错误**必须**是后者。口径与 `ClientVersionEnforcementTest` 里那两条
 * （`testMalformedMinimumSupportedVersionIsAServerError` /
 * `testMalformedMinimumIsNotADomainException`）逐字相同 —— 那里是 T-004 为
 * `MIN_SUPPORTED_CLIENT` 立的先例，本文件把同一条规则推到另外三个变量上。
 */
#[CoversClass(ClientConfigProvider::class)]
final class ClientConfigProviderTest extends TestCase
{
    private const MIN = 'android/1.0.0 (10)';
    private const LATEST = 'android/1.4.0 (26)';

    private const START = '2026-09-15T03:00:00+02:00';
    private const END = '2026-09-15T04:00:00+02:00';

    private const START_MILLIS = 1789434000_000; // 2026-09-15T01:00:00Z
    private const END_MILLIS = 1789437600_000;   // 2026-09-15T02:00:00Z

    // ========================================================================
    // 正常路径
    // ========================================================================

    public function testServesTheConfiguredVersionsInCanonicalForm(): void
    {
        $config = self::provider()->current();

        // ⚠️ 断言的是 ClientVersion 的规范形式，而不是原样回显：
        // 那保证同一个版本在 /v1/config 的响应里与 §14.4 的指标标签里长得一样。
        self::assertSame(self::MIN, (string) $config->minSupportedClient);
        self::assertSame(self::LATEST, (string) $config->latestClient);
    }

    /**
     * 括号前那个可选空格是 `ClientVersion::PATTERN` 允许的，所以 env 里写成
     * `android/1.4.0(26)` 也该被接受 —— 并且归一成带空格的规范形式。
     */
    public function testToleratesTheNonCanonicalSpellingInConfiguration(): void
    {
        $config = self::provider(latest: 'android/1.4.0(26)')->current();

        self::assertSame('android/1.4.0 (26)', (string) $config->latestClient);
    }

    public function testNoWindowConfiguredMeansNoAnnouncement(): void
    {
        $maintenance = self::provider()->current()->maintenance;

        self::assertFalse($maintenance->active);
        self::assertNull($maintenance->messageKey);
        self::assertNull($maintenance->retryAfter);
    }

    /**
     * 三态各一条。窗口的边界本身由 `MaintenanceWindowTest` 逐秒钉死，
     * 这里只证明 provider 真的把**时钟**接了进去 —— 把 `statusAt()` 的参数
     * 写成一个固定时刻，那个文件全绿而这三条会红。
     *
     * @return iterable<string, array{int, bool, MaintenanceMessageKey|null, int|null}>
     */
    public static function maintenanceStates(): iterable
    {
        yield '窗口还远' => [self::START_MILLIS - 86401_000, false, null, null];
        yield '已公告' => [self::START_MILLIS - 3600_000, false, MaintenanceMessageKey::Scheduled, null];
        yield '进行中' => [self::START_MILLIS + 1800_000, true, MaintenanceMessageKey::InProgress, 1800];
        yield '已结束' => [self::END_MILLIS, false, null, null];
    }

    #[DataProvider('maintenanceStates')]
    public function testMaintenanceFollowsTheClock(int $nowMillis, bool $active, ?MaintenanceMessageKey $key, ?int $retryAfter): void
    {
        $maintenance = self::provider(
            clock: new FrozenClock($nowMillis),
            start: self::START,
            end: self::END,
        )->current()->maintenance;

        self::assertSame($active, $maintenance->active);
        self::assertSame($key, $maintenance->messageKey);
        self::assertSame($retryAfter, $maintenance->retryAfter);
    }

    /**
     * 时钟往前走，同一个 provider 的答案要跟着变 —— 证明 `current()` 每次都重新
     * 求值，而不是在构造期把状态算死。一个长驻的 php-fpm worker 里，构造期求值
     * 的后果是公告在进程重启之前永远不变。
     */
    public function testStatusIsRecomputedOnEveryCall(): void
    {
        $clock = new FrozenClock(self::START_MILLIS - 3600_000);
        $provider = self::provider(clock: $clock, start: self::START, end: self::END);

        self::assertFalse($provider->current()->maintenance->active);

        $clock->advance(3600_000);

        self::assertTrue($provider->current()->maintenance->active);
    }

    // ========================================================================
    // 配置错误 —— 全部是 500，一条都不许是 4xx
    // ========================================================================

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedVersions(): iterable
    {
        yield 'MIN 缺 platform 前缀' => ['1.0.0 (10)', self::LATEST, 'MIN_SUPPORTED_CLIENT'];
        yield 'MIN 缺 build' => ['android/1.0.0', self::LATEST, 'MIN_SUPPORTED_CLIENT'];
        yield 'MIN 为空' => ['', self::LATEST, 'MIN_SUPPORTED_CLIENT'];
        yield 'LATEST 缺 build' => [self::MIN, 'android/1.4.0', 'LATEST_CLIENT'];
        yield 'LATEST 为空' => [self::MIN, '', 'LATEST_CLIENT'];
        yield 'LATEST 平台名大写' => [self::MIN, 'Android/1.4.0 (26)', 'LATEST_CLIENT'];
    }

    #[DataProvider('malformedVersions')]
    public function testMalformedVersionIsAServerError(string $min, string $latest, string $variable): void
    {
        self::expectException(\LogicException::class);
        // 文案必须点名是**哪个**变量配错了 —— 两个变量同形，不点名的话运维要靠
        // 猜。这也是不能直接把 ClientVersion::parse 的异常放出去的理由之一。
        self::expectExceptionMessage($variable.' is not a valid client version');

        self::provider(min: $min, latest: $latest);
    }

    /**
     * ⚠️ 这一条与上面那组不重复：上面测的是「是不是炸了」，这条测的是「炸的是哪一族」。
     *
     * `ClientVersion::parse()` 抛的是 `validation_failed` + `FieldError('X-Client')`，
     * 那套语义是给**客户端发来的 header** 准备的。原样冒出去会变成一个 400，
     * 指着客户端说「你的 X-Client 格式不对」，而真正配错的是服务端的 env。
     */
    public function testMalformedVersionIsNotADomainException(): void
    {
        try {
            self::provider(min: '1.0.0');
            self::fail('配错的客户端版本必须抛异常');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(
                DomainException::class,
                $e,
                '服务端配置错误不能走 DomainException —— 那会变成一个 400，并且不触发 §14.4 的 5xx 告警',
            );
        }
    }

    /**
     * ⚠️ 任务卡没提这条，但它是这两个值**一起**下发时才出现的新故障面。
     *
     * `latest < min` 的含义是「请升级到一个已经不被支持的版本」：客户端照做之后
     * 仍然是 426，而它会以为自己已经是最新的 —— 一个没有出路的死循环。
     * 这个状态没有任何合理用途，配出来只可能是手误（改了 min 忘了改 latest）。
     *
     * @return iterable<string, array{string, string}>
     */
    public static function latestBelowMinimum(): iterable
    {
        yield '整个版本更低' => ['android/1.4.0 (26)', 'android/1.0.0 (10)'];
        yield '只差 build' => ['android/1.4.0 (26)', 'android/1.4.0 (25)'];
        // ⚠️ ClientVersion::isAtLeast() 不比 platform，所以换平台名绕不过这条校验 ——
        // 这里顺带把那个语义钉住。
        yield '换了平台名也不行' => ['android/1.4.0 (26)', 'ios/1.0.0 (10)'];
    }

    #[DataProvider('latestBelowMinimum')]
    public function testLatestBelowTheMinimumIsAServerError(string $min, string $latest): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage('is below MIN_SUPPORTED_CLIENT');

        self::provider(min: $min, latest: $latest);
    }

    /**
     * 相等是合法的：刚抬高基线、商店上最新的就是那个版本，是一个正常状态。
     */
    public function testLatestEqualToTheMinimumIsFine(): void
    {
        $config = self::provider(min: self::MIN, latest: self::MIN)->current();

        self::assertSame((string) $config->minSupportedClient, (string) $config->latestClient);
    }

    /**
     * 窗口的配置错误照样是 500。判定本体在 `MaintenanceWindowTest` 里，
     * 这一条证明它**确实被 provider 调用了** —— 少了这个调用，一个配错的窗口
     * 会静默地变成「没有窗口」。
     *
     * @return iterable<string, array{string|null, string|null, string}>
     */
    public static function malformedWindows(): iterable
    {
        yield '只配了 start' => [self::START, null, 'must be set together'];
        yield '只配了 end' => [null, self::END, 'must be set together'];
        yield 'start 无 offset' => ['2026-09-15T03:00:00', self::END, 'explicit offset'];
        yield 'end 无 offset' => [self::START, '2026-09-15T04:00:00', 'explicit offset'];
        yield '顺序颠倒' => [self::END, self::START, 'must be strictly before'];
    }

    #[DataProvider('malformedWindows')]
    public function testMalformedWindowIsAServerError(?string $start, ?string $end, string $expectedMessage): void
    {
        self::expectException(\LogicException::class);
        self::expectExceptionMessage($expectedMessage);

        self::provider(start: $start, end: $end);
    }

    private static function provider(
        string $min = self::MIN,
        string $latest = self::LATEST,
        ?string $start = null,
        ?string $end = null,
        ?FrozenClock $clock = null,
    ): ClientConfigProvider {
        return new ClientConfigProvider($min, $latest, $start, $end, $clock ?? new FrozenClock());
    }
}
