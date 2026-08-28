<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Redis;

use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 连接参数 —— 尤其是**超时**。
 *
 * ============================================================================
 * 为什么值得单独一个测试文件
 * ============================================================================
 * 超时配错是**无声**的：Predis 只在 `read_write_timeout` 存在时才调
 * `stream_set_timeout()`，不设就沿用 php.ini 的 `default_socket_timeout`（默认 60 秒）。
 * 没有任何报错、任何日志、任何失败的测试会提示这件事 —— 只有在生产里遇到一个
 * 「接受连接但不回包」的 Redis 时，它才以「每个写请求卡 60 秒」的形式显形。
 *
 * 这些用例不需要真 Redis：`create()` 只构造客户端，Predis 要到第一条命令才建连。
 */
#[CoversClass(RedisConnectionFactory::class)]
final class RedisConnectionFactoryTest extends TestCase
{
    public function testConnectTimeoutIsSetExplicitly(): void
    {
        $parameters = (new RedisConnectionFactory('redis://127.0.0.1:6379'))
            ->create()
            ->getConnection()
            ->getParameters();

        self::assertSame(
            RedisConnectionFactory::CONNECT_TIMEOUT_SECONDS,
            $parameters->timeout,
            '不设的话 Predis 用 5 秒 —— 对同一个 docker 网络里的容器来说太长了',
        );
    }

    /**
     * ⚠️ 本文件里最重要的一条。
     *
     * 不设 `read_write_timeout` 等于 60 秒，而 60 秒会推翻幂等 fail-open 的前提：
     * 那套论证建立在「Redis 故障是廉价的」之上，但一个挂起（而非拒绝）的 Redis
     * 会让每个带 Idempotency-Key 的 POST 与 /health/ready 各卡满 60 秒。
     */
    public function testReadWriteTimeoutIsSetExplicitly(): void
    {
        $parameters = (new RedisConnectionFactory('redis://127.0.0.1:6379'))
            ->create()
            ->getConnection()
            ->getParameters();

        // 注意 ParametersInterface 把这些属性标成了 `@property float`，所以
        // 「断言它不是 null」在 phpstan 眼里恒真（实际未设置时 __get 返回 null）。
        // 直接断言值本身，既绕开那条规则，也是更强的断言。
        self::assertSame(
            RedisConnectionFactory::READ_WRITE_TIMEOUT_SECONDS,
            $parameters->read_write_timeout,
            'read_write_timeout 没设对 —— 不设就沿用 php.ini 的 default_socket_timeout（60 秒），'
            .'一个卡住的 Redis 会把每个写请求和 /health/ready 各拖 60 秒才 fail-open',
        );
    }

    /**
     * 超时是**连接参数**，不是**客户端选项** —— 写进 `new Client()` 的第二个入参
     * 不会报错，只会被静默忽略。这条用例盯的就是那个区别：参数确实落到了连接上。
     *
     * 同时也证明 DSN 解析没有把 host/port 弄丢（合并方向写反的话最先坏的就是它们）。
     */
    public function testDsnHostAndPortSurviveTheMerge(): void
    {
        $parameters = (new RedisConnectionFactory('redis://redis.internal:6380'))
            ->create()
            ->getConnection()
            ->getParameters();

        self::assertSame('redis.internal', $parameters->host);
        self::assertSame('6380', (string) $parameters->port);
    }

    /**
     * DSN 里显式给的超时**覆盖**我们的默认值。
     *
     * 这是运维的逃生口：线上真遇到需要放宽超时的情况，改 REDIS_URL 就行，
     * 不用改代码重新发布。
     */
    public function testDsnOverridesTheDefaultTimeouts(): void
    {
        $parameters = (new RedisConnectionFactory('redis://127.0.0.1:6379?read_write_timeout=5&timeout=3'))
            ->create()
            ->getConnection()
            ->getParameters();

        self::assertSame('5', (string) $parameters->read_write_timeout);
        self::assertSame('3', (string) $parameters->timeout);
    }

    /**
     * ⚠️⚠️ 端到端地证明「挂起的 Redis 不会拖住请求」。
     *
     * 上面几条验的是参数写对了；这条验的是那个参数**真的生效**。用一个只 accept、
     * 从不回包的 socket 模拟 BGSAVE 停顿 / 网络黑洞 —— 这正是 TCP 层面**连得上**、
     * 因而连接超时救不了的那种故障。
     *
     * 没有 read_write_timeout 时这条会跑满 60 秒才失败（而不是挂死），
     * 那个耗时本身就是断言想说的事。
     */
    public function testAHangingServerFailsFastInsteadOfBlockingForAMinute(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if (false === $server) {
            self::markTestSkipped('无法监听本地端口（'.$errstr.'）。');
        }

        $address = (string) stream_socket_get_name($server, false);

        $factory = new RedisConnectionFactory('redis://'.$address);
        $started = microtime(true);

        try {
            // accept 之后永远不回包 —— Predis 会一直等 read。
            $factory->create()->ping();
            self::fail('对着一个从不回包的服务端，ping 不该成功返回');
        } catch (\Throwable) {
            // 抛什么类型不重要，RedisIdempotencyStore 会把任何 Throwable 包成
            // IdempotencyStoreUnavailable。这里只关心**多久**抛出来。
        } finally {
            fclose($server);
        }

        $elapsed = microtime(true) - $started;

        self::assertLessThan(
            5.0,
            $elapsed,
            \sprintf(
                '挂起的 Redis 用了 %.1f 秒才失败。read_write_timeout 没生效 —— '
                .'生产里这会让每个带 Idempotency-Key 的 POST 与 /health/ready 各卡这么久，'
                .'而 fail-open 的前提是「Redis 故障是廉价的」。',
                $elapsed,
            ),
        );
    }
}
