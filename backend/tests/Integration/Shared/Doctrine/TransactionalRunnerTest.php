<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Shared\Infrastructure\Doctrine\TransactionalRunner;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 真实 Postgres 上的事务语义。
 *
 * ============================================================================
 * 为什么这些断言重要
 * ============================================================================
 * §4.2 唯一强制同事务的跨模块协作（Social 解除好友 → Sharing 级联撤销）依赖
 * **嵌套 run() 的语义正确**。§13.4 的必测项 #5 要求：
 *
 * > ⑤ 全部发生在一个事务内（中途注入异常 → 全部回滚，不出现「好友已解除但共享还在」）
 *
 * 那条测试属于 T-3xx，但它成立的前提在这里 —— 如果嵌套事务被静默忽略
 * （DBAL 3 的旧模式）或者 savepoint 没开，T-3xx 会写出一条通过的测试和一个
 * 会在生产泄露 viewer 名单的 bug。
 */
#[CoversClass(TransactionalRunner::class)]
final class TransactionalRunnerTest extends KernelTestCase
{
    private Connection $connection;
    private TransactionalRunner $runner;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        $this->connection = $connection;
        $this->runner = new TransactionalRunner($connection);

        // 临时表随会话消失，用例之间不会互相污染。
        $this->connection->executeStatement('CREATE TEMPORARY TABLE t004_tx (id INT PRIMARY KEY)');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS t004_tx');
        }

        parent::tearDown();
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t004_tx');
    }

    private function insert(int $id): void
    {
        $this->connection->executeStatement('INSERT INTO t004_tx (id) VALUES (?)', [$id]);
    }

    public function testReturnsTheClosureResult(): void
    {
        $expected = 'done-'.bin2hex(random_bytes(4));

        self::assertSame($expected, $this->runner->run(static fn (): string => $expected));
    }

    public function testCommitsOnSuccess(): void
    {
        $this->runner->run(function (): void {
            $this->insert(1);
        });

        self::assertSame(1, $this->rows());
    }

    /**
     * 异常必须**原样**向上抛，不能被包成别的类型 ——
     * 否则业务代码抛的 DomainException 到不了 ApiProblemExceptionListener 手里，
     * 每个 409 revision_conflict 都会变成 500。
     */
    public function testRethrowsTheOriginalException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->runner->run(function (): void {
            $this->insert(1);

            throw new \RuntimeException('boom');
        });
    }

    public function testRollsBackOnFailure(): void
    {
        try {
            $this->runner->run(function (): void {
                $this->insert(1);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // 上一条测试已经验过异常本身，这里只关心副作用。
        }

        self::assertSame(0, $this->rows(), '失败必须整体回滚');
    }

    public function testReportsWhetherATransactionIsActive(): void
    {
        self::assertFalse($this->runner->isInTransaction());

        $this->runner->run(function (): void {
            self::assertTrue(
                $this->runner->isInTransaction(),
                'ShareRevoker 要靠这个在被非事务调用时直接抛，见 §13.4 必测项 #5',
            );
        });

        self::assertFalse($this->runner->isInTransaction());
    }

    /**
     * ⚠️ 嵌套 run() 必须真的嵌套（SAVEPOINT），而不是被静默忽略。
     *
     * 外层抛异常 → 内外层的写入**一起**回滚。这正是「好友已解除但共享还在」
     * 那个 bug 不会发生的原因。
     */
    public function testOuterFailureRollsBackNestedWork(): void
    {
        try {
            $this->runner->run(function (): void {
                $this->insert(1);

                // 模拟 Social.Application 调 Sharing 的 Port
                $this->runner->run(function (): void {
                    $this->insert(2);
                });

                throw new \RuntimeException('外层在内层成功之后失败');
            });
        } catch (\RuntimeException) {
            // 预期
        }

        self::assertSame(0, $this->rows(), '内层的写入必须跟着外层一起回滚');
    }

    /**
     * 内层失败但被外层捕获 → 只回滚到 SAVEPOINT，外层的写入保留。
     *
     * 这是 savepoint 语义真正生效的证据；`use_savepoints: false` 时
     * 内层的回滚会把整个事务标脏。
     */
    public function testNestedFailureRollsBackOnlyToTheSavepoint(): void
    {
        $this->runner->run(function (): void {
            $this->insert(1);

            try {
                $this->runner->run(function (): void {
                    $this->insert(2);

                    throw new \RuntimeException('内层失败');
                });
            } catch (\RuntimeException) {
                // 外层选择继续
            }

            $this->insert(3);
        });

        $ids = $this->connection->fetchFirstColumn('SELECT id FROM t004_tx ORDER BY id');

        self::assertSame([1, 3], array_map('intval', $ids), '只有内层那条应当被回滚');
    }

    /**
     * 闭包**不**该收到 Connection 参数。
     *
     * `Connection::transactional()` 会把自身传给回调；实现里包了一层
     * `static fn (): mixed => $operation()` 就是为了挡住它 —— 否则一个声明了
     * 首个参数的闭包会意外拿到 Connection，把 Doctrine 泄回 Application 层。
     */
    public function testClosureReceivesNoArguments(): void
    {
        $this->runner->run(static function (...$args): void {
            self::assertSame([], $args, '闭包不该收到 Connection —— 那会把 Doctrine 泄进 Application 层');
        });
    }
}
