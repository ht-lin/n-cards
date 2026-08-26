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
}
