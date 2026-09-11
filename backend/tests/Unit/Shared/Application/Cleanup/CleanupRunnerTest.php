<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Cleanup;

use App\Shared\Application\Cleanup\CleanupRunner;
use App\Shared\Application\Cleanup\CleanupTaskInterface;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\RecordingLogger;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * ⚠️ 本文件最重要的一条是 {@see testOneFailingTaskDoesNotStopTheOthers()}。
 *
 * 它钉的是 T-113 的核心取舍：僵尸行删除有一个已知的、理论上不可能但真发生了
 * 就会**每天重复**的失败模式（撞上 `cards.owner_id` 的 `ON DELETE RESTRICT`）。
 * 没有隔离的话，那一次失败会让 `otp_challenges` 从此再也不清理 ——
 * 一个静默堆积 Vault 加密邮箱密文的后果，而 §8.2 的 ROPA 还写着它会被删。
 */
#[CoversClass(CleanupRunner::class)]
final class CleanupRunnerTest extends TestCase
{
    /** 2026-09-11T04:30:00Z，随便挑的固定时刻；用例只关心「三个任务拿到同一个」。 */
    private const NOW_MILLIS = 1_788_985_800_000;

    private FrozenClock $clock;

    private RecordingLogger $logger;

    private RecordingMetrics $metrics;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(self::NOW_MILLIS);
        $this->logger = new RecordingLogger();
        $this->metrics = new RecordingMetrics();
    }

    public function testRunsEveryRegisteredTaskAndReportsItsRowCount(): void
    {
        $results = $this->runner(
            $this->task('a', rows: 3),
            $this->task('b', rows: 0),
        )->run();

        self::assertCount(2, $results);
        self::assertSame('a', $results[0]->task);
        self::assertSame(3, $results[0]->rows);
        self::assertFalse($results[0]->failed());
        self::assertSame('b', $results[1]->task);
        self::assertSame(0, $results[1]->rows);
        self::assertFalse($results[1]->failed());
    }

    /**
     * ⚠️ **红了就是一个会静默堆积 PII 的回归。** 见类注释。
     */
    public function testOneFailingTaskDoesNotStopTheOthers(): void
    {
        $first = $this->task('first', rows: 1);
        $boom = $this->failingTask('boom', new \RuntimeException('FK violation'));
        $last = $this->task('last', rows: 2);

        $results = $this->runner($first, $boom, $last)->run();

        // 三个都跑过 —— 中间那个炸了没有让第三个被跳过。
        self::assertSame(1, $first->runs);
        self::assertSame(1, $last->runs);

        self::assertCount(3, $results);
        self::assertFalse($results[0]->failed());
        self::assertTrue($results[1]->failed());
        self::assertSame('FK violation', $results[1]->failure?->getMessage());
        // 失败的那个行数记 0，而不是「上一个任务的行数」之类的脏值。
        self::assertSame(0, $results[1]->rows);
        self::assertFalse($results[2]->failed());
        self::assertSame(2, $results[2]->rows);
    }

    /**
     * 异常**不得**冒泡：scheduler transport 没有 retry_strategy 也没有
     * failure_transport（`SchedulerTransport::reject()` 是空操作），
     * 抛出去只会让 Messenger 记一行「消息处理失败」然后把它丢掉 ——
     * 而那一行说不出是哪个任务、更说不出另外两个跑没跑。
     */
    public function testAFailingTaskNeverPropagates(): void
    {
        $runner = $this->runner($this->failingTask('boom', new \RuntimeException('nope')));

        $results = $runner->run();

        self::assertTrue($results[0]->failed());
    }

    public function testAFailureIsLoggedAsAnErrorWithTheTaskName(): void
    {
        $exception = new \RuntimeException('FK violation');

        $this->runner($this->failingTask('identity.zombie_registrations', $exception))->run();

        $errors = array_values(array_filter(
            $this->logger->records,
            static fn (array $r) => LogLevel::ERROR === $r['level'],
        ));

        self::assertCount(1, $errors);
        self::assertSame('identity.zombie_registrations', $errors[0]['context']['task']);
        // 异常对象本身进 context（§14.4：日志是结构化 JSON，不往 msg 里拼）。
        self::assertSame($exception, $errors[0]['context']['exception']);
    }

    /**
     * 「跑了但没东西可删」与「根本没跑」必须在指标上分得开 —— 后者是
     * scheduler 挂掉的症状，也是本卡唯一需要告警的故障。
     */
    public function testARunIsCountedEvenWhenNoRowWasTouched(): void
    {
        $this->runner($this->task('quiet', rows: 0))->run();

        self::assertSame([['task' => 'quiet']], $this->metrics->labelsFor('cleanup_runs_total'));
        self::assertSame([], $this->metrics->labelsFor('cleanup_rows_total'));
    }

    public function testRowsAreCountedWithTheActualRowCount(): void
    {
        $this->runner($this->task('busy', rows: 7))->run();

        $calls = array_values(array_filter(
            $this->metrics->calls(),
            static fn (array $c) => 'cleanup_rows_total' === $c['name'],
        ));

        self::assertCount(1, $calls);
        self::assertSame(7, $calls[0]['by']);
        self::assertSame(['task' => 'busy'], $calls[0]['labels']);
    }

    public function testAFailedTaskCountsAnErrorAndNotARun(): void
    {
        $this->runner($this->failingTask('boom', new \RuntimeException('nope')))->run();

        self::assertSame([['task' => 'boom']], $this->metrics->labelsFor('cleanup_errors_total'));
        self::assertSame([], $this->metrics->labelsFor('cleanup_runs_total'));
    }

    /**
     * 一趟清理里所有任务共用**同一个** `$now`。
     *
     * 分开读时钟的话，跨午夜那一趟里两个任务的「7 天前」可能落在不同的日子，
     * 而这种偏差只在每天极短的一个窗口里出现 —— 是那种上线半年后才被发现、
     * 且无法复现的 bug。
     */
    public function testEveryTaskReceivesTheSameInstant(): void
    {
        $first = $this->task('a', rows: 0);
        $second = $this->task('b', rows: 0);

        $this->runner($first, $second)->run();

        self::assertEquals($first->seenNow, $second->seenNow);
        self::assertSame(
            $this->clock->now()->format(\DateTimeInterface::RFC3339_EXTENDED),
            $first->seenNow?->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }

    public function testOnlyRunsTheNamedTaskWhenOneIsRequested(): void
    {
        $wanted = $this->task('wanted', rows: 1);
        $other = $this->task('other', rows: 1);

        $results = $this->runner($wanted, $other)->run('wanted');

        self::assertCount(1, $results);
        self::assertSame('wanted', $results[0]->task);
        self::assertSame(1, $wanted->runs);
        self::assertSame(0, $other->runs, 'A filtered-out task must not run at all.');
    }

    /**
     * 打错任务名要能被调用方认出来 —— `app:cleanup` 靠「结果为空」翻成一条错误，
     * 因为「跑了 0 个任务」的静默成功会让运维以为清理跑过了。
     */
    public function testAnUnknownTaskNameYieldsNoResults(): void
    {
        $results = $this->runner($this->task('a', rows: 1))->run('nope');

        self::assertSame([], $results);
    }

    public function testExposesTheRegisteredTaskNames(): void
    {
        self::assertSame(
            ['a', 'b'],
            $this->runner($this->task('a', rows: 0), $this->task('b', rows: 0))->taskNames(),
        );
    }

    private function runner(CleanupTaskInterface ...$tasks): CleanupRunner
    {
        return new CleanupRunner($tasks, $this->clock, $this->logger, $this->metrics);
    }

    /**
     * @param int<0, max> $rows
     */
    private function task(string $name, int $rows): CleanupTaskSpy
    {
        return new CleanupTaskSpy($name, $rows, null);
    }

    private function failingTask(string $name, \Throwable $failure): CleanupTaskSpy
    {
        return new CleanupTaskSpy($name, 0, $failure);
    }
}

/**
 * 记下「跑过几次」与「拿到的是哪个时刻」的清理任务替身。
 *
 * 文件内类而不是提到 tests/Double/：它带着两个只对本文件的断言有意义的公开属性，
 * 提上去会变成一个谁都要读一遍才知道用不用得上的公共类
 * （与 RecordingMetrics 的类注释是同一条理由）。
 */
final class CleanupTaskSpy implements CleanupTaskInterface
{
    public int $runs = 0;

    public ?\DateTimeImmutable $seenNow = null;

    /**
     * @param int<0, max> $rows
     */
    public function __construct(
        private readonly string $name,
        private readonly int $rows,
        private readonly ?\Throwable $failure,
    ) {
    }

    public function name(): string
    {
        \assert('' !== $this->name);

        return $this->name;
    }

    public function run(\DateTimeImmutable $now): int
    {
        $this->seenNow = $now;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        ++$this->runs;

        return $this->rows;
    }
}
