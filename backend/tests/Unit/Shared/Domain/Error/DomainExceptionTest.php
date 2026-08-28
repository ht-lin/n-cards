<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Error;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DomainException::class)]
#[CoversClass(FieldError::class)]
final class DomainExceptionTest extends TestCase
{
    public function testCarriesTheErrorCode(): void
    {
        $e = new DomainException(ErrorCode::NotAMember, 'You are not a member of this card.');

        self::assertSame(ErrorCode::NotAMember, $e->errorCode());
        self::assertSame('You are not a member of this card.', $e->detail());
    }

    /**
     * detail 同时是 `getMessage()`，这样日志、Sentry、phpunit 的失败输出
     * 都能不做任何适配就看到有意义的内容。
     */
    public function testDetailIsAlsoTheExceptionMessage(): void
    {
        $e = new DomainException(ErrorCode::NotFound, 'No such card.');

        self::assertSame($e->detail(), $e->getMessage());
    }

    /**
     * `\Exception::$code` 是 int 语义，装不下我们的字符串 code —— 所以恒为 0，
     * 真正的 code 走 errorCode()。属性也因此叫 $errorCode 而不是 $code。
     */
    public function testNativeExceptionCodeStaysZero(): void
    {
        self::assertSame(0, (new DomainException(ErrorCode::RateLimited))->getCode());
    }

    public function testDefaultsAreEmpty(): void
    {
        $e = new DomainException(ErrorCode::TokenExpired);

        self::assertSame('', $e->detail());
        self::assertSame([], $e->fieldErrors());
        self::assertSame([], $e->current());
        self::assertNull($e->getPrevious());
    }

    public function testValidationFailedCollectsFieldErrors(): void
    {
        $e = DomainException::validationFailed(
            new FieldError('title', FieldErrorCode::TooLong, 'Title must be at most 100 characters.'),
            new FieldError('note', FieldErrorCode::TooLong, 'Note must be at most 2000 characters.'),
        );

        self::assertSame(ErrorCode::ValidationFailed, $e->errorCode());
        self::assertCount(2, $e->fieldErrors());
        self::assertSame('title', $e->fieldErrors()[0]->field);
        self::assertSame('note', $e->fieldErrors()[1]->field);
    }

    /**
     * `errors` 在 JSON 里必须是数组而不是对象 —— 带键的输入要被重新索引，
     * 否则 json_encode 会编出 `{"2":{...}}`，客户端的解析直接崩。
     */
    public function testFieldErrorsAreReindexedIntoAList(): void
    {
        $e = new DomainException(ErrorCode::ValidationFailed, '', [
            7 => new FieldError('a', FieldErrorCode::Required, 'a is required.'),
            3 => new FieldError('b', FieldErrorCode::Required, 'b is required.'),
        ]);

        self::assertSame([0, 1], array_keys($e->fieldErrors()));
        self::assertSame(
            '[{"field":"a","code":"required","message":"a is required."},{"field":"b","code":"required","message":"b is required."}]',
            json_encode($e->fieldErrors(), \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * §5.4.3：409 要带上服务端当前状态，客户端据此走冲突解决。
     */
    public function testRevisionConflictCarriesCurrentState(): void
    {
        $current = ['revision' => 42, 'title' => 'Payback'];
        $e = DomainException::revisionConflict($current);

        self::assertSame(ErrorCode::RevisionConflict, $e->errorCode());
        self::assertSame($current, $e->current());
    }

    public function testLimitExceededNamesTheLimit(): void
    {
        $e = DomainException::limitExceeded('cards_per_user', 500);

        self::assertSame(ErrorCode::LimitExceeded, $e->errorCode());
        self::assertStringContainsString('cards_per_user', $e->detail());
        self::assertStringContainsString('500', $e->detail());
    }

    public function testNotFoundAndAlreadyExistsHaveSaneDefaults(): void
    {
        self::assertSame(ErrorCode::NotFound, DomainException::notFound()->errorCode());
        self::assertNotSame('', DomainException::notFound()->detail());
        self::assertSame(ErrorCode::AlreadyExists, DomainException::alreadyExists()->errorCode());
        self::assertNotSame('', DomainException::alreadyExists()->detail());
    }

    /**
     * ⚠️ 安全相关：原始异常消息可能含连接串、SQL 片段或加密载荷。
     * 它只能进日志（经 previous），绝不能进 detail —— detail 会直接进响应体。
     */
    public function testInternalNeverLeaksTheOriginalMessage(): void
    {
        $original = new \RuntimeException('SQLSTATE[08006] connection to 10.0.0.5:5432 failed: password authentication failed for user "ncards"');

        $e = DomainException::internal($original);

        self::assertSame(ErrorCode::InternalError, $e->errorCode());
        self::assertSame($original, $e->getPrevious(), '原异常必须挂在 previous 上供日志取用');
        self::assertStringNotContainsString('10.0.0.5', $e->detail());
        self::assertStringNotContainsString('password', $e->detail());
        self::assertStringNotContainsString('SQLSTATE', $e->detail());
        self::assertSame('An unexpected error occurred.', $e->detail());
    }

    /**
     * 刻意不 final：各模块要继承出更具体的类型（`CardNotFoundException` 之类），
     * 好让 catch 能按类型收窄而不是按 code 比字符串。
     *
     * 真正的断言是**下面这个匿名类能被声明出来** —— 给 DomainException 加上 final
     * 会让本文件直接 fatal，测试跑都跑不起来。所以这里只需再验证子类确实继承到了
     * 父类的行为（errorCode 与 detail 都从 parent::__construct 传下来）。
     */
    public function testIsExtensibleByModules(): void
    {
        $subclass = new class extends DomainException {
            public function __construct()
            {
                parent::__construct(ErrorCode::InsufficientRole, 'Viewers cannot modify a card.');
            }
        };

        self::assertSame(ErrorCode::InsufficientRole, $subclass->errorCode());
        self::assertSame('Viewers cannot modify a card.', $subclass->detail());
    }

    /**
     * detail 是英文开发者文案（§6.1）。命名构造产出的固定文案必须是 ASCII ——
     * 这是「服务端绝不返回可展示的德语文案」的机械化形式。
     */
    public function testBuiltInDetailsArePrintableAscii(): void
    {
        $details = [
            DomainException::validationFailed()->detail(),
            DomainException::notFound()->detail(),
            DomainException::revisionConflict([])->detail(),
            DomainException::limitExceeded('cards_per_user', 500)->detail(),
            DomainException::alreadyExists()->detail(),
            DomainException::internal(new \RuntimeException())->detail(),
        ];

        foreach ($details as $detail) {
            self::assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $detail, '内置 detail 含非 ASCII 字符：'.$detail);
        }
    }
}
