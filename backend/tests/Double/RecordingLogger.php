<?php

declare(strict_types=1);

namespace App\Tests\Double;

use Psr\Log\AbstractLogger;

/**
 * 把日志记进内存的 PSR-3 实现，供断言「排障信息确实落到了日志里」。
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}
