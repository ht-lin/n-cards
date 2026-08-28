<?php

declare(strict_types=1);

namespace App\Tests\Double\Random;

use App\Shared\Domain\Random\RandomnessInterface;

/**
 * 产出预先排好的「随机」值。
 *
 * 有了它，「给定这一毫秒 + 这串字节，UUIDv7 应该恰好是这个字符串」才写得出来 ——
 * 那是唯一能真正验证 RFC 9562 位布局（版本 nibble、variant 位、计数器位置）的断言方式。
 *
 * 队列耗尽时**抛异常而不是回落到真随机**：静默回落会让一个本该确定的测试
 * 变成偶发失败，而那种失败最难查。
 */
final class SequenceRandomness implements RandomnessInterface
{
    /** @var list<string> */
    private array $bytes;

    /** @var list<int> */
    private array $ints;

    /**
     * @param list<string> $bytes `bytes()` 依次返回的值
     * @param list<int>    $ints  `int()` 依次返回的值
     */
    public function __construct(array $bytes = [], array $ints = [])
    {
        $this->bytes = $bytes;
        $this->ints = $ints;
    }

    /**
     * 一个只出固定字节、且 int() 恒返回同一个值的常用组合。
     */
    public static function fixed(string $byte = "\x00", int $int = 0, int $times = 1): self
    {
        return new self(
            array_fill(0, $times, str_repeat($byte, 8)),
            array_fill(0, $times, $int),
        );
    }

    public function bytes(int $length): string
    {
        if ([] === $this->bytes) {
            throw new \LogicException('SequenceRandomness ran out of byte strings; the test asked for more randomness than it queued.');
        }

        $next = array_shift($this->bytes);

        if (\strlen($next) !== $length) {
            throw new \LogicException(\sprintf('SequenceRandomness was asked for %d bytes but the queued value is %d bytes long.', $length, \strlen($next)));
        }

        return $next;
    }

    public function int(int $min, int $max): int
    {
        if ([] === $this->ints) {
            throw new \LogicException('SequenceRandomness ran out of integers; the test asked for more randomness than it queued.');
        }

        $next = array_shift($this->ints);

        if ($next < $min || $next > $max) {
            throw new \LogicException(\sprintf('SequenceRandomness queued %d, which is outside the requested range [%d, %d].', $next, $min, $max));
        }

        return $next;
    }
}
