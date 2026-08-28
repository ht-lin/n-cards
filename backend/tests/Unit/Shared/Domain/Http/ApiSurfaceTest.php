<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Http;

use App\Shared\Domain\Http\ApiSurface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T-004 最容易出事的一条规则的单测层。
 *
 * 任务书原文：「`ClientVersionListener` 必须把 `/health/*` 排除在外……漏掉这条豁免，
 * 两个探活端点会在本任务合入当天集体变 400，连带 compose 起栈与 staging 部署的
 * 健康检查一起失效。」
 *
 * 这里验证的是那条豁免的**正向形态**：只有 `/v1/` 才是产品 API。
 * 端到端形态由 tests/Api/ClientVersionEnforcementTest 与 RouteInventoryTest 兜底。
 */
#[CoversClass(ApiSurface::class)]
final class ApiSurfaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function productApiPaths(): iterable
    {
        yield 'cards list' => ['/v1/cards'];
        yield 'single card' => ['/v1/cards/01941f29-7c00-70ab-8000-000000000000'];
        yield 'auth' => ['/v1/auth/otp/request'];
        yield 'nested' => ['/v1/cards/x/members/y'];
        yield 'trailing slash' => ['/v1/'];
    }

    #[DataProvider('productApiPaths')]
    public function testRecognisesProductApiPaths(string $path): void
    {
        self::assertTrue(ApiSurface::isProductApiPath($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function exemptPaths(): iterable
    {
        // T-003 交付的两个探活端点 —— 调用方是 Docker healthcheck / Caddy / Ansible，
        // 它们不带 X-Client。这两行红了就说明整个栈的健康检查要挂。
        yield 'health live' => ['/health/live'];
        yield 'health ready' => ['/health/ready'];

        yield 'root' => ['/'];
        yield 'empty' => [''];
        // 下面两条守的是 V1_PREFIX 的尾斜杠：去掉它，/v1foo 会被当成产品 API。
        yield 'bare v1 without slash' => ['/v1'];
        yield 'v1 prefix collision' => ['/v1foo/bar'];
        // 版本前缀必须在最前面，不能出现在中间。
        yield 'v1 not at the start' => ['/api/v1/cards'];
        yield 'future v2' => ['/v2/cards'];
        yield 'dev error page' => ['/_error/404'];
        yield 'metrics endpoint' => ['/metrics'];
    }

    #[DataProvider('exemptPaths')]
    public function testExemptsEverythingOutsideV1(string $path): void
    {
        self::assertFalse(ApiSurface::isProductApiPath($path));
    }
}
