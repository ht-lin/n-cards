<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Error;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\RateLimitExceeded;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimitExceeded::class)]
final class RateLimitExceededTest extends TestCase
{
    /**
     * 必须是 DomainException 的子类 —— 否则它走不到
     * `ApiProblemExceptionListener` 的 DomainException 分支，会掉进
     * 「未映射异常 → 500 + 固定文案」，429 就此变成 500。
     */
    public function testIsADomainExceptionCarryingTheRateLimitedCode(): void
    {
        self::assertContains(
            DomainException::class,
            class_parents(RateLimitExceeded::class) ?: [],
            'RateLimitExceeded 必须继承 DomainException，否则 429 会掉进 500 分支',
        );

        $e = new RateLimitExceeded(30);

        self::assertSame(ErrorCode::RateLimited, $e->errorCode());
        self::assertSame(429, $e->errorCode()->httpStatus());
    }

    public function testCarriesTheExactHeaderValues(): void
    {
        $e = new RateLimitExceeded(3600, 0, 'otp_request_email');

        self::assertSame(3600, $e->retryAfterSeconds());
        self::assertSame(0, $e->remaining());
    }

    public function testDetailNamesThePolicyWhenThereIsOnlyOne(): void
    {
        $e = new RateLimitExceeded(60, 0, 'user_lookup_user');

        self::assertStringContainsString('user_lookup_user', $e->detail());
        self::assertStringContainsString('60', $e->detail());
    }

    /**
     * 多维度时不点名策略 —— 说出「是 IP 维度拒的」等于告诉攻击者该换 IP
     * 还是换邮箱（§3.8 的枚举防线同理）。
     */
    public function testDetailOmitsThePolicyWhenNoneIsGiven(): void
    {
        $e = new RateLimitExceeded(60);

        self::assertStringNotContainsString('"', $e->detail());
        self::assertStringContainsString('Rate limit exceeded', $e->detail());
    }

    /**
     * ⚠️ `detail` 会进日志与 Sentry，所以里面**绝不能**出现主体
     * （email_hash / IP / user id —— §8.2 的安全类数据）。
     *
     * 构造签名里根本没有主体这个参数，这条测试是那个设计的回归锁。
     */
    public function testDetailNeverContainsTheSubject(): void
    {
        $e = new RateLimitExceeded(60, 0, 'otp_request_ip');

        self::assertStringNotContainsString('203.0.113', $e->detail());
        self::assertStringNotContainsString('@', $e->detail());
    }

    /**
     * §6.1：detail 是英文开发者文案，不含可展示的德语。
     */
    public function testDetailIsPlainAscii(): void
    {
        self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', (new RateLimitExceeded(5, 0, 'sync_device'))->detail());
    }

    /**
     * 限流不是字段级校验失败，也不是冲突 —— errors[] 与 current{} 都该是空的，
     * 于是 problem body 里那两个成员不会出现。
     */
    public function testCarriesNoFieldErrorsOrCurrentState(): void
    {
        $e = new RateLimitExceeded(5);

        self::assertSame([], $e->fieldErrors());
        self::assertSame([], $e->current());
    }
}
