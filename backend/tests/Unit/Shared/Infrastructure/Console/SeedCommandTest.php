<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Console;

use App\Shared\Application\Seed\SeederInterface;
use App\Shared\Infrastructure\Console\SeedCommand;
use App\Tests\Double\Seed\SpySeeder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SeedCommand::class)]
final class SeedCommandTest extends TestCase
{
    /**
     * T-003 交付的是空壳：一个 seeder 都没有，命令必须照样成功退出 ——
     * 否则 compose 起栈后的第一条 `bin/console app:seed` 就会红。
     */
    public function testSucceedsWithNoSeedersRegistered(): void
    {
        $tester = self::runCommand([], 'dev');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('0 个 seeder', $tester->getDisplay());
    }

    public function testRunsEverySeederAndSumsTheRows(): void
    {
        $identity = self::seeder('identity', 3);
        $wallet = self::seeder('wallet', 7);

        $tester = self::runCommand([$identity, $wallet], 'dev');

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('identity', $display);
        self::assertStringContainsString('wallet', $display);
        self::assertStringContainsString('2 个 seeder，共写入 10 行', $display);
    }

    /**
     * §14.1：production 绝不造数据。这道闸在命令里，不在各 seeder 里 ——
     * 一处拦住，将来新增 seeder 不会漏。
     */
    public function testRefusesToRunInProd(): void
    {
        $seeder = self::seeder('identity', 3);

        $tester = self::runCommand([$seeder], 'prod');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $seeder->calls, 'prod 下一个 seeder 都不许被调用');
    }

    /**
     * @param list<SeederInterface> $seeders
     */
    private static function runCommand(array $seeders, string $environment): CommandTester
    {
        $tester = new CommandTester(new SeedCommand($seeders, $environment));
        $tester->execute([]);

        return $tester;
    }

    private static function seeder(string $name, int $rows): SpySeeder
    {
        return new SpySeeder($name, $rows);
    }
}
