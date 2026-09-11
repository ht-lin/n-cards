<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Cleanup;

use App\Shared\Application\Cleanup\CleanupRunner;
use App\Shared\Application\Cleanup\RunDailyCleanup;
use App\Shared\Application\Cleanup\RunDailyCleanupHandler;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * T-113 的端到端：一条 {@see RunDailyCleanup} 消息 → Handler → runner → 三个任务 → 真库。
 *
 * ============================================================================
 * 这条用例存在的**唯一**理由：接线错误只会在 04:30 的生产上现形
 * ============================================================================
 * 别的测试都用 `new` 把被测对象拼出来，所以它们对 DI 与 Messenger 的配置一无所知。
 * 而本卡有三处接线，写错任何一处，单测与集成测试都会全绿，故障发生在**每天
 * 凌晨四点半的 scheduler 容器里**，没有请求、没有用户报障、只有一行日志：
 *
 *   1. `config/services.yaml` 里 `RunDailyCleanupHandler` 的
 *      `messenger.message_handler` 标签 —— 漏了的话 `command.bus` 会抛
 *      `NoHandlerForMessageException`（它没开 allow_no_handlers）。
 *   2. 那个标签上的 `bus: command.bus` —— 写成别的总线，消息永远送不到。
 *      scheduler transport 产生的信封**没有** BusNameStamp，
 *      `messenger:consume` 会把它交给 RoutableMessageBus 的 fallback，
 *      也就是 `default_bus`（见 messenger.yaml）。
 *   3. `_instanceof` 的 `app.cleanup_task` 标签 —— 漏了的话 runner 收集到 0 个任务，
 *      清理「成功」跑完、一行都没删，而 §8.2 的保留期从此是一句空话。
 *
 * 所以这里**必须**从容器取服务、经真总线 dispatch，而不是 `new` 一个 Handler。
 */
#[CoversClass(RunDailyCleanupHandler::class)]
#[CoversClass(RunDailyCleanup::class)]
#[CoversClass(CleanupRunner::class)]
final class CleanupPipelineTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    protected function setUp(): void
    {
        $this->bootIdentitySchema();
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    /**
     * ⚠️ 接线断言 ①②：消息在默认总线上找得到 Handler。
     *
     * `command.bus` 没开 `allow_no_handlers`，所以标签写错时这里会抛
     * `NoHandlerForMessageException` —— 那正是我们要在 CI 里看到的失败，
     * 而不是在生产的凌晨四点半。
     */
    public function testTheDailyMessageIsHandledOnTheDefaultBus(): void
    {
        $envelope = $this->commandBus()->dispatch(new RunDailyCleanup());

        self::assertNotNull(
            $envelope->last(HandledStamp::class),
            'RunDailyCleanup must be handled on command.bus — check the messenger.message_handler tag in services.yaml.',
        );
    }

    /**
     * ⚠️ 接线断言 ③：三个 Identity 任务真的被 `app.cleanup_task` 收集到了。
     *
     * 断言的是**名字**而不是数量：数量会随 T-204 / T-402 / T-403 / T-404
     * 接进来而变化，而那几张卡不该被迫回来改这条用例。名字是稳定的契约
     * （它们同时是 `cleanup_rows_total{task}` 的标签值与 `app:cleanup --task=` 的键）。
     */
    public function testEveryIdentityCleanupTaskIsCollected(): void
    {
        $names = $this->runner()->taskNames();

        self::assertContains('identity.zombie_registrations', $names);
        self::assertContains('identity.dead_otp_challenges', $names);
        self::assertContains('identity.otp_request_ips', $names);
    }

    /**
     * 保留期参数真的从 `config/packages/ncards_cleanup.yaml` 接到了任务上。
     *
     * 少写一条 `arguments:`，容器会因为构造参数缺失而拒绝编译
     * （ContainerCompilesTest 兜底），但**配错值**不会有任何症状 ——
     * 所以这里造两条只差一秒的行，用 7 天那个边界把实际生效的数字钉出来。
     */
    public function testTheConfiguredRetentionIsTheOneThatTakesEffect(): void
    {
        $now = $this->clockNow();

        // 第 7 天零 1 秒 —— 该删。
        $this->persistZombie(1, $now->modify('-7 days -1 second'));
        // 正好第 7 天 —— 该留。
        $this->persistZombie(2, $now->modify('-7 days'));
        // 第 6 天 —— 该留。
        $this->persistZombie(3, $now->modify('-6 days'));

        $this->commandBus()->dispatch(new RunDailyCleanup());

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM users WHERE id = ?',
                [IdentityEntities::id(1)->toString()],
            ),
        );
    }

    /**
     * 一趟清理把三张表上该删的都删掉 —— 这是 scheduler 容器每天 04:30 做的事，
     * 只不过这里由一次 dispatch 代替那次触发。
     */
    public function testARunPurgesZombiesAndDeadChallengesTogether(): void
    {
        $now = $this->clockNow();

        $this->persistZombie(1, $now->modify('-30 days'));
        $this->persistDeadChallenge(2, $now->modify('-30 days'));
        // 活着的挑战不许动。⚠️ 红了就是所有人都登不进去。
        $this->persistLiveChallenge(3, $now);

        $this->commandBus()->dispatch(new RunDailyCleanup());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM users'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM otp_challenges'));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT count(*) FROM otp_challenges WHERE id = ?',
                [IdentityEntities::id(3)->toString()],
            ),
        );
    }

    /**
     * 空库上跑一趟必须是无声无息的成功 —— 这是生产上绝大多数日子的形状，
     * 也是 compose 起栈后第一条 `app:cleanup` 会遇到的形状。
     */
    public function testARunOnAnEmptyDatabaseIsAQuietSuccess(): void
    {
        $results = $this->runner()->run();

        self::assertNotSame([], $results);

        foreach ($results as $result) {
            self::assertFalse($result->failed(), $result->task.' failed: '.($result->failure?->getMessage() ?? ''));
            self::assertSame(0, $result->rows);
        }
    }

    private function commandBus(): MessageBusInterface
    {
        /** @var MessageBusInterface $bus */
        $bus = self::getContainer()->get('command.bus');

        return $bus;
    }

    private function runner(): CleanupRunner
    {
        /** @var CleanupRunner $runner */
        $runner = self::getContainer()->get(CleanupRunner::class);

        return $runner;
    }

    /**
     * ⚠️ 用**生产时钟**的当下，不是一个固定时刻。
     *
     * 这条用例走的是真容器，里面注入的是 `SystemClock` —— 夹具必须相对
     * 它的 `now()` 定位，否则「30 天前」在一个固定基准上算出来的时刻
     * 可能根本不早于真实的 now，用例会随日期漂移而莫名其妙地失败。
     * 边界的精确断言在单测里（用 FrozenClock），这里验的是接线。
     */
    private function clockNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private function persistZombie(int $nth, \DateTimeImmutable $createdAt): void
    {
        $this->entityManager()->persist(IdentityEntities::user(
            id: IdentityEntities::id($nth),
            emailHash: IdentityEntities::digest('zombie-'.$nth),
            now: $createdAt,
        ));
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function persistDeadChallenge(int $nth, \DateTimeImmutable $createdAt): void
    {
        $this->entityManager()->persist(IdentityEntities::challenge(
            id: IdentityEntities::id($nth),
            emailHash: IdentityEntities::digest('dead-'.$nth),
            now: $createdAt,
        ));
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function persistLiveChallenge(int $nth, \DateTimeImmutable $now): void
    {
        $this->entityManager()->persist(IdentityEntities::challenge(
            id: IdentityEntities::id($nth),
            emailHash: IdentityEntities::digest('live-'.$nth),
            now: $now,
        ));
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->entityManager;
    }
}
