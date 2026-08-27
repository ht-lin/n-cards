<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Idempotency;

use App\Shared\Application\Idempotency\IdempotencyRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * 幂等记录的两种形态。
 *
 * 此前只被 `IdempotencyMiddleware` 的测试间接覆盖 —— 那些测试关心的是**状态机**，
 * 而这里钉的是状态机赖以分支的三个字段本身：`fingerprint`（同键不同 body 的检测）、
 * `completed`（回放还是 409）、以及回放要还原的 `status`/`headers`/`body`。
 */
#[CoversClass(IdempotencyRecord::class)]
final class IdempotencyRecordTest extends TestCase
{
    public function testInProgressCarriesOnlyTheFingerprint(): void
    {
        $record = IdempotencyRecord::inProgress('fp');

        self::assertSame('fp', $record->fingerprint);
        self::assertFalse($record->completed);
        // 在途记录没有响应可回放 —— 中间件据此返回 409 而不是尝试重放一个空响应。
        self::assertNull($record->status);
        self::assertSame([], $record->headers);
        self::assertNull($record->body);
    }

    public function testCompletedCarriesTheReplayableResponse(): void
    {
        $record = IdempotencyRecord::completed(
            'fp',
            201,
            ['content-type' => 'application/json', 'location' => '/v1/cards/1'],
            '{"id":1}',
        );

        self::assertSame('fp', $record->fingerprint);
        self::assertTrue($record->completed);
        self::assertSame(201, $record->status);
        self::assertSame(['content-type' => 'application/json', 'location' => '/v1/cards/1'], $record->headers);
        self::assertSame('{"id":1}', $record->body);
    }

    /**
     * ⚠️ 指纹必须跨越「在途 → 已完成」保留下来。
     *
     * 丢了它，「同键不同 body」的检测就废了：第二次带着不同 body 的请求会被
     * 当成合法回放，直接拿回第一次的响应。
     */
    public function testFingerprintSurvivesTheTransitionToCompleted(): void
    {
        $inProgress = IdempotencyRecord::inProgress('original');
        $completed = IdempotencyRecord::completed($inProgress->fingerprint, 200, [], '{}');

        self::assertSame($inProgress->fingerprint, $completed->fingerprint);
    }

    /**
     * 204 没有响应体，但仍然是需要去重的 2xx。
     */
    public function testCompletedSupportsAnEmptyBody(): void
    {
        $record = IdempotencyRecord::completed('fp', 204, [], '');

        self::assertTrue($record->completed);
        self::assertSame(204, $record->status);
        self::assertSame('', $record->body, '空体与「没有记录」是两回事');
    }

    /**
     * 只能经两个命名构造创建 —— 构造函数是 private，不存在「既 completed 又没有
     * status」这种自相矛盾的中间态。
     */
    public function testCannotBeConstructedDirectly(): void
    {
        self::assertFalse(
            (new \ReflectionClass(IdempotencyRecord::class))->getConstructor()?->isPublic() ?? true,
        );
    }
}
