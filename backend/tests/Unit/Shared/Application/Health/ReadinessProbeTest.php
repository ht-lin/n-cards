<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Health;

use App\Shared\Application\Health\ReadinessProbe;
use App\Tests\Double\Health\RecordingHealthCheck;
use App\Tests\Double\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadinessProbe::class)]
final class ReadinessProbeTest extends TestCase
{
    public function testReadyWhenThereAreNoChecks(): void
    {
        self::assertTrue((new ReadinessProbe([]))->isReady());
    }

    public function testReadyWhenEveryCheckPasses(): void
    {
        $probe = new ReadinessProbe([
            new RecordingHealthCheck('postgres'),
            new RecordingHealthCheck('redis'),
        ]);

        self::assertTrue($probe->isReady());
    }

    public function testNotReadyWhenAnyCheckFails(): void
    {
        $probe = new ReadinessProbe([
            new RecordingHealthCheck('postgres'),
            new RecordingHealthCheck('vault', 'connection refused'),
        ]);

        self::assertFalse($probe->isReady());
    }

    /**
     * 刻意不短路：一次探活要把所有检查跑完，这样 503 的那条日志里能看到**全部**
     * 失败组件，而不是只看到第一个。
     */
    public function testEveryCheckRunsEvenAfterAnEarlierFailure(): void
    {
        $later = new RecordingHealthCheck('redis');

        $probe = new ReadinessProbe([
            new RecordingHealthCheck('postgres', 'connection refused'),
            $later,
        ]);

        self::assertFalse($probe->isReady());
        self::assertSame(1, $later->calls, '第一项失败后，后续检查仍然应该被执行');
    }

    /**
     * §6.2：健康端点不暴露内部细节 —— isReady() 只回一个 bool，本身就没有可泄露的
     * 东西。这条锁的是另一半：排障需要的组件名与异常**必须**能从日志里拿到。
     */
    public function testFailureDetailsGoToTheLogger(): void
    {
        $logger = new RecordingLogger();

        $probe = new ReadinessProbe(
            [new RecordingHealthCheck('postgres', 'connection refused')],
            $logger,
        );

        self::assertFalse($probe->isReady());
        self::assertCount(1, $logger->records);
        self::assertSame('postgres', $logger->records[0]['context']['component']);
        self::assertInstanceOf(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    public function testWorksWithoutALogger(): void
    {
        $probe = new ReadinessProbe([new RecordingHealthCheck('postgres', 'connection refused')]);

        self::assertFalse($probe->isReady());
    }
}
