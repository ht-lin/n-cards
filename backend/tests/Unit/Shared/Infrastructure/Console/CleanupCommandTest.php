<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Console;

use App\Shared\Application\Cleanup\CleanupRunner;
use App\Shared\Application\Cleanup\CleanupTaskInterface;
use App\Shared\Infrastructure\Console\CleanupCommand;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\RecordingLogger;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:cleanup` 的退出码语义（T-113）。
 *
 * 这是运维手动入口，所以「有没有出事」必须体现在**退出码**上 ——
 * 人不会去读一屏输出，脚本更不会。消息那一侧相反
 * （{@see \App\Shared\Application\Cleanup\RunDailyCleanupHandler} 不抛），
 * 理由写在那个类里。
 */
#[CoversClass(CleanupCommand::class)]
final class CleanupCommandTest extends TestCase
{
    public function testSucceedsAndReportsEachTaskRowCount(): void
    {
        $tester = self::runCommand([], self::task('identity.dead_otp_challenges', rows: 12));

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('identity.dead_otp_challenges', $display);
        self::assertStringContainsString('12', $display);
        self::assertStringContainsString('共处理 12 行', $display);
    }

    /**
     * 一个都没删不是失败 —— 一个没有任何过期数据的库本来就该这样。
     */
    public function testSucceedsWhenNothingNeededCleaning(): void
    {
        $tester = self::runCommand([], self::task('identity.otp_request_ips', rows: 0));

        $tester->assertCommandIsSuccessful();
    }

    /**
     * ⚠️ 任何一个任务失败即 FAILURE，即使别的都成功了 —— 这正是僵尸行删除撞上
     * `cards.owner_id` RESTRICT 时的形状：另外两个任务照跑（runner 的隔离），
     * 但这一趟整体是红的。
     */
    public function testFailsWhenAnyTaskFailed(): void
    {
        $tester = self::runCommand([], self::task('ok', rows: 1), self::failingTask('boom'));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('boom', $display);
        self::assertStringContainsString('1 / 2', $display);
    }

    public function testRunsOnlyTheRequestedTask(): void
    {
        $tester = self::runCommand(
            ['--task' => 'wanted'],
            self::task('wanted', rows: 1),
            self::task('other', rows: 5),
        );

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('wanted', $display);
        self::assertStringNotContainsString('other', $display);
    }

    /**
     * ⚠️ 打错任务名必须是**错误**，不是「跑了 0 个任务」的静默成功。
     *
     * 运维在生产上敲少了一个词、看到一行绿色的 success，会以为清理跑过了 ——
     * 而 §8.2 的保留期那天就是没执行。输出里还要列出可选值，
     * 免得人得去翻源码找名字。
     */
    public function testFailsAndListsTheOptionsOnAnUnknownTaskName(): void
    {
        $tester = self::runCommand(['--task' => 'identity.otp_challenges'], self::task('identity.dead_otp_challenges', rows: 0));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('identity.otp_challenges', $display);
        self::assertStringContainsString('identity.dead_otp_challenges', $display, 'The error must list what is available.');
    }

    /**
     * 一个 task 都没注册时不该崩 —— 与 `app:seed` 的空壳分支同一个理由
     * （compose 起栈后的第一条命令不该是红的）。
     */
    public function testSucceedsWithNoTasksRegistered(): void
    {
        $tester = self::runCommand([]);

        $tester->assertCommandIsSuccessful();
    }

    /**
     * ⚠️ 与 `app:seed` 相反，这个命令**没有** prod 闸：清理恰恰主要在 prod 跑，
     * 它就是 §8.2 保留期的执行点。这条用例把那个不对称钉住。
     */
    public function testHasNoProdGuardUnlikeAppSeed(): void
    {
        $task = self::task('identity.dead_otp_challenges', rows: 4);

        // 命令根本不接 kernel.environment —— 构造签名里没有它，也就无从拦。
        $tester = self::runCommand([], $task);

        $tester->assertCommandIsSuccessful();
        self::assertSame(1, $task->runs);
    }

    /**
     * @param array<string, string> $input
     */
    private static function runCommand(array $input, CleanupTaskInterface ...$tasks): CommandTester
    {
        $runner = new CleanupRunner(
            $tasks,
            new FrozenClock(1_788_985_800_000),
            new RecordingLogger(),
            new RecordingMetrics(),
        );

        $tester = new CommandTester(new CleanupCommand($runner));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param int<0, max> $rows
     */
    private static function task(string $name, int $rows): CleanupTaskSpy
    {
        return new CleanupTaskSpy($name, $rows, null);
    }

    private static function failingTask(string $name): CleanupTaskSpy
    {
        return new CleanupTaskSpy($name, 0, new \RuntimeException('FK violation'));
    }
}

/**
 * 与 CleanupRunnerTest 里那个同名替身是**两个**文件内类，刻意不共用。
 *
 * 提到 tests/Double/ 的话它会长成一个「既要记调用次数、又要能抛、又要能配行数」
 * 的通用替身，而两边真正要断言的东西不一样（那边是隔离与时刻，这边是退出码）。
 * 与 RecordingMetrics 的类注释是同一条理由。
 */
final class CleanupTaskSpy implements CleanupTaskInterface
{
    public int $runs = 0;

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
        if (null !== $this->failure) {
            throw $this->failure;
        }

        ++$this->runs;

        return $this->rows;
    }
}
