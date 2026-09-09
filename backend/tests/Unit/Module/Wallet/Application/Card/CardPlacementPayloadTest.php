<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Wallet\Application\Card\CardPlacementPayload;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CardPlacementPayload::class)]
final class CardPlacementPayloadTest extends TestCase
{
    public function testAValidBodyIsAccepted(): void
    {
        $payload = CardPlacementPayload::fromArray(['sort_order' => 3, 'is_pinned' => true]);

        self::assertSame(3, $payload->sortOrder);
        self::assertTrue($payload->isPinned);
    }

    public function testANegativeSortOrderIsAccepted(): void
    {
        // 服务端对 sort_order 没有语义 —— 不唯一、不归一化，也不要求非负。
        self::assertSame(-5, CardPlacementPayload::fromArray(['sort_order' => -5, 'is_pinned' => false])->sortOrder);
    }

    // ========================================================================
    // 必填
    // ========================================================================

    /**
     * 契约里两个字段都在 `required` 里 —— placement 是一次**完整的摆放声明**，
     * 不是部分更新。做成可选的话「只发 is_pinned 意味着 sort_order 保持不变
     * 还是重置」就成了一个两端各自记住的约定，而契约里没有地方写它。
     */
    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('missingFields')]
    public function testBothFieldsAreRequired(array $body, string $expectedField): void
    {
        $problem = self::capture($body);

        self::assertSame(ErrorCode::ValidationFailed, $problem->errorCode());
        self::assertSame($expectedField, $problem->fieldErrors()[0]->field);
        self::assertSame(FieldErrorCode::Required, $problem->fieldErrors()[0]->code);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function missingFields(): iterable
    {
        yield 'sort_order 缺席' => [['is_pinned' => true], 'sort_order'];
        yield 'is_pinned 缺席' => [['sort_order' => 0], 'is_pinned'];
    }

    public function testAnEmptyBodyReportsBothFields(): void
    {
        $problem = self::capture([]);

        self::assertCount(2, $problem->fieldErrors());
    }

    // ========================================================================
    // 类型
    // ========================================================================

    /**
     * ⚠️ 只收真正的 int / bool。`"3"` 与 `1` 一旦被收下，两端对
     * 「1 算不算 true」就会有不同的理解，而契约写的是 `type: integer` /
     * `type: boolean`。
     */
    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('wrongTypes')]
    public function testWrongTypesAreRejected(array $body, string $expectedField): void
    {
        $problem = self::capture($body);

        self::assertSame($expectedField, $problem->fieldErrors()[0]->field);
        self::assertSame(FieldErrorCode::InvalidType, $problem->fieldErrors()[0]->code);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function wrongTypes(): iterable
    {
        yield 'sort_order 是字符串' => [['sort_order' => '3', 'is_pinned' => true], 'sort_order'];
        yield 'sort_order 是浮点' => [['sort_order' => 3.5, 'is_pinned' => true], 'sort_order'];
        yield 'sort_order 是 null' => [['sort_order' => null, 'is_pinned' => true], 'sort_order'];
        yield 'is_pinned 是字符串' => [['sort_order' => 0, 'is_pinned' => 'true'], 'is_pinned'];
        yield 'is_pinned 是 1' => [['sort_order' => 0, 'is_pinned' => 1], 'is_pinned'];
        yield 'is_pinned 是 null' => [['sort_order' => 0, 'is_pinned' => null], 'is_pinned'];
    }

    // ========================================================================
    // ⚠️ int32 范围 —— 漏掉它就是一个 500
    // ========================================================================

    /**
     * `card_members.sort_order` 是 PG 的 `INTEGER`。
     * `PHP_INT_MAX` 是一个**合法的 JSON 整数**，`is_int()` 收得下，
     * 然后在 flush 的时候变成一条 DBAL 错误 —— 也就是一个 500，
     * 而契约写的是 `400 validation_failed`。
     */
    #[DataProvider('outOfRange')]
    public function testValuesOutsideInt32AreRejected(int $sortOrder): void
    {
        $problem = self::capture(['sort_order' => $sortOrder, 'is_pinned' => false]);

        self::assertSame('sort_order', $problem->fieldErrors()[0]->field);
        self::assertSame(FieldErrorCode::OutOfRange, $problem->fieldErrors()[0]->code);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'int32 上界 + 1' => [2147483648];
        yield 'int32 下界 - 1' => [-2147483649];
        yield 'PHP_INT_MAX' => [\PHP_INT_MAX];
        yield 'PHP_INT_MIN' => [\PHP_INT_MIN];
    }

    #[DataProvider('int32Bounds')]
    public function testTheInt32BoundsThemselvesAreAccepted(int $sortOrder): void
    {
        self::assertSame($sortOrder, CardPlacementPayload::fromArray([
            'sort_order' => $sortOrder,
            'is_pinned' => false,
        ])->sortOrder);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function int32Bounds(): iterable
    {
        yield 'int32 上界' => [2147483647];
        yield 'int32 下界' => [-2147483648];
    }

    // ========================================================================
    // 未知字段
    // ========================================================================

    /**
     * ⚠️ 卡的内容字段走 `PATCH /v1/cards/{id}`（有 If-Match 保护），
     * 不走这里。混进来的 `title` 必须被拒，否则就出现了第二个改卡入口 ——
     * 而这一个不带乐观锁。
     */
    public function testCardContentFieldsAreRejected(): void
    {
        $problem = self::capture(['sort_order' => 0, 'is_pinned' => false, 'title' => '新标题']);

        self::assertSame('title', $problem->fieldErrors()[0]->field);
        self::assertSame(FieldErrorCode::UnknownField, $problem->fieldErrors()[0]->code);
    }

    public function testRevisionIsRejected(): void
    {
        // placement 不走 revision 锁 —— 客户端不该往这里发它。
        $problem = self::capture(['sort_order' => 0, 'is_pinned' => false, 'revision' => 7]);

        self::assertSame('revision', $problem->fieldErrors()[0]->field);
        self::assertSame(FieldErrorCode::UnknownField, $problem->fieldErrors()[0]->code);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function capture(array $body): DomainException
    {
        try {
            CardPlacementPayload::fromArray($body);
        } catch (DomainException $e) {
            return $e;
        }

        self::fail('Expected DomainException.');
    }
}
