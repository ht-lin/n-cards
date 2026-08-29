<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * 冒烟测试：内核能启动、容器能编译。
 *
 * M0 阶段 src/ 里除了 Kernel 什么都没有，这条用例的作用是让后续每一个
 * config/packages/*.yaml 与 config/services/*.yaml 的改动都立刻被验证一次 ——
 * 容器编译期的错误（缺服务、循环引用、类型不匹配）在这里就会暴露，
 * 而不是等到部署后第一个请求。
 */
final class ContainerCompilesTest extends KernelTestCase
{
    public function testKernelBootsAndContainerCompiles(): void
    {
        self::bootKernel();

        $kernel = self::$kernel;
        self::assertInstanceOf(Kernel::class, $kernel);
        self::assertSame('test', $kernel->getEnvironment());

        // 取一个容器参数，确保容器真的编译出来了（而不是只 new 了个 Kernel）
        self::assertSame(
            \dirname(__DIR__, 2),
            self::getContainer()->getParameter('kernel.project_dir'),
        );
    }

    /**
     * ⚠️ `config/packages/*.yaml` 里**不得**注册来自 `require-dev` 的类。
     *
     * 这条测试存在的具体原因（T-007）：`nyholm/psr7` 是契约测试用的 dev 依赖，
     * 而它的 Symfony recipe 会自动生成 `config/packages/nyholm_psr7.yaml`，
     * 把六个 PSR-17 服务注册进**所有**环境的容器。`symfony.lock` 里至今记着这条
     * recipe，所以 `composer recipes:install nyholm/psr7 --force` 会把它请回来。
     *
     * 它的危害只在生产才显形：`composer install --no-dev` 之后
     * `Nyholm\Psr7\Factory\Psr17Factory` 不存在，容器编译直接炸 ——
     * 而 CI 装的是全量依赖，本地也是，谁都不会在合入前发现。
     *
     * 契约测试自己 `new Psr17Factory()` 就够了（见 tests/Api/Support/OpenApiContract），
     * 根本不需要容器注册。
     */
    public function testNoDevOnlyRecipeConfigLeakedIntoTheContainer(): void
    {
        $devOnlyConfigs = [
            'nyholm_psr7.yaml' => 'nyholm/psr7 是 require-dev（T-007 的契约测试用），'
                .'它的 recipe 会把 PSR-17 服务注册进生产容器 —— '
                .'composer install --no-dev 之后容器编译会找不到类',
        ];

        foreach ($devOnlyConfigs as $file => $why) {
            self::assertFileDoesNotExist(
                \dirname(__DIR__, 2).'/config/packages/'.$file,
                \sprintf("config/packages/%s 不该存在：%s。\n删掉它。", $file, $why),
            );
        }
    }
}
