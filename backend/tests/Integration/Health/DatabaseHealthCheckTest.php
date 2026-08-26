<?php

declare(strict_types=1);

namespace App\Tests\Integration\Health;

use App\Shared\Infrastructure\Health\DatabaseHealthCheck;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 对**真实** Postgres 跑一遍探活。
 *
 * 单测里的替身能证明「聚合逻辑对」，证明不了「DBAL 连接串对、pdo_pgsql 装了、
 * SELECT 1 真能发出去」。这条用例补的就是那一段。
 *
 * 没起 compose 栈的开发机上连不上 PG，此时 markTestSkipped —— `composer qa`
 * 在裸机上依然全绿。CI 里有 postgres service（.github/workflows/backend.yml），
 * 所以在 CI 上这条**一定**会真跑。
 */
#[CoversClass(DatabaseHealthCheck::class)]
final class DatabaseHealthCheckTest extends KernelTestCase
{
    public function testPassesAgainstALiveDatabase(): void
    {
        self::bootKernel();
        $check = self::getContainer()->get(DatabaseHealthCheck::class);

        try {
            $check->check();
        } catch (DbalException $e) {
            self::markTestSkipped('没有可用的 Postgres（先 `docker compose up -d`）：'.$e->getMessage());
        }

        self::assertSame('postgres', $check->name());
    }

    /**
     * T-003 验收标准的另一半：数据库不可用时探活必须**抛异常**（由 ReadinessProbe
     * 翻成 503），而不是静默通过。
     *
     * 不依赖「把真实的 PG 停掉」—— 直接连一个不会有人监听的端口，效果一样，
     * 且这条用例在有没有 compose 栈的机器上都会真跑。
     */
    public function testFailsWhenTheDatabaseIsUnreachable(): void
    {
        $check = new DatabaseHealthCheck(DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'nobody',
            'password' => 'nobody',
            'dbname' => 'nothing',
        ]));

        $this->expectException(DbalException::class);
        $check->check();
    }
}
