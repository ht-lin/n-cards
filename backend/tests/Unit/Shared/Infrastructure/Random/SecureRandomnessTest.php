<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Random;

use App\Shared\Infrastructure\Random\SecureRandomness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecureRandomness::class)]
final class SecureRandomnessTest extends TestCase
{
    public function testReturnsTheRequestedNumberOfBytes(): void
    {
        $random = new SecureRandomness();

        self::assertSame(1, \strlen($random->bytes(1)));
        self::assertSame(8, \strlen($random->bytes(8)));
        self::assertSame(32, \strlen($random->bytes(32)));
    }

    /**
     * 不是密码学检验（那不是单测能做的事），只抓「忘了真的随机」这类低级错误 ——
     * 比如返回常量、返回全零、或把同一次结果缓存住。
     */
    public function testSuccessiveCallsDiffer(): void
    {
        $random = new SecureRandomness();
        $samples = [];

        for ($i = 0; $i < 32; ++$i) {
            $samples[] = $random->bytes(16);
        }

        self::assertCount(32, array_unique($samples), '连续取样出现重复 —— 随机源可疑');
    }

    public function testIntStaysWithinTheInclusiveRange(): void
    {
        $random = new SecureRandomness();

        for ($i = 0; $i < 500; ++$i) {
            $value = $random->int(0, 0xFF);

            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(0xFF, $value);
        }
    }

    public function testIntWithASingleValueRangeIsDeterministic(): void
    {
        self::assertSame(7, (new SecureRandomness())->int(7, 7));
    }

    public function testIntCoversMoreThanOneValue(): void
    {
        $random = new SecureRandomness();
        $seen = [];

        for ($i = 0; $i < 200; ++$i) {
            $seen[$random->int(0, 9)] = true;
        }

        self::assertGreaterThan(1, \count($seen), 'int() 只产出了一个值 —— 随机源可疑');
    }
}
