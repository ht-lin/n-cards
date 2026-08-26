<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Application\Health\HealthCheckInterface;
use Doctrine\DBAL\Connection;

/**
 * Postgres 就绪检查 —— T-003 验收标准「PG 不可用时 /health/ready 返回 503」的落点。
 *
 * 本类在 Shared\Infrastructure 而不是 Shared\Http，是因为 deptrac 的 `Shared.Http`
 * 允许列表里没有 `Framework.Persistence`：控制器碰 Doctrine 会直接是一条 violation。
 * 这个分层不是形式主义 —— 它保证「换掉探活实现」永远不需要动 HTTP 层。
 */
final readonly class DatabaseHealthCheck implements HealthCheckInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): string
    {
        return 'postgres';
    }

    public function check(): void
    {
        // 不用 Connection::connect()：DBAL 4 的连接是惰性的，只有真正发语句才会建连。
        // `SELECT 1` 是最便宜的「连得上且能应答」的证明。
        $this->connection->executeQuery('SELECT 1')->free();
    }
}
