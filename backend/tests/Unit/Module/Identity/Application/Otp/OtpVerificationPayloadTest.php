<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Otp\OtpVerificationPayload;
use App\Module\Identity\Application\Session\DeviceDescriptor;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 契约里的 `OtpVerification`（`docs/api/openapi.yaml`）。
 *
 * 与 {@see OtpRequestPayloadTest} 同一套结构，多了一层嵌套对象（`device`）。
 */
#[CoversClass(OtpVerificationPayload::class)]
// T-106：`device` 那半的校验搬去了 DeviceDescriptor，两个请求体共用一份。
#[CoversClass(DeviceDescriptor::class)]
final class OtpVerificationPayloadTest extends TestCase
{
    private const CHALLENGE_ID = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

    private const DEVICE_ID = '0192f3a1-b2c3-7d4e-8f01-0000000000de';

    public function testAcceptsTheContractExample(): void
    {
        $payload = OtpVerificationPayload::fromArray(self::body());

        self::assertSame(self::CHALLENGE_ID, $payload->challengeId->toString());
        self::assertSame('418396', $payload->code);
        self::assertSame(self::DEVICE_ID, $payload->device->id->toString());
        // platform 今天只有 android 一个取值，断言它是恒真的（PHPStan 会直接报出来）。
        // 真正在验解析的是下面 provider 里的 `device.platform unknown`。
        self::assertSame('Pixel 7a', $payload->device->model);
        self::assertSame('14', $payload->device->osVersion);
        self::assertSame('1.4.0', $payload->device->appVersion);
    }

    /**
     * 三个描述性字段服务端收得比契约更宽：它们只用于设备管理页的展示与
     * 新设备提醒信的正文，缺了不影响登录能不能成，而库里那三列也都可空。
     */
    public function testTheThreeDescriptiveDeviceFieldsAreOptional(): void
    {
        $body = self::body();
        unset($body['device']['model'], $body['device']['os_version'], $body['device']['app_version']);

        $payload = OtpVerificationPayload::fromArray($body);

        self::assertNull($payload->device->model);
        self::assertNull($payload->device->osVersion);
        self::assertNull($payload->device->appVersion);
    }

    /**
     * 空串与「没传」等价 —— 客户端在拿不到机型时经常发空串而不是省略字段，
     * 两者存进库都该是 NULL，读侧因此只需要处理一种「不知道」。
     */
    public function testBlankDescriptiveFieldsCollapseToNull(): void
    {
        $body = self::body();
        $body['device']['model'] = '   ';

        self::assertNull(OtpVerificationPayload::fromArray($body)->device->model);
    }

    /**
     * ⚠️ `maxLength` 是**字符**数不是字节数：机型名里有非 ASCII
     * （`Xiaomi 红米 Note 13`），按字节判会把合法机型误拒。
     */
    public function testMeasuresTheModelInCharactersNotBytes(): void
    {
        $body = self::body();
        // 100 个汉字 = 100 字符 / 300 字节。按字节判的实现会在这里拒绝。
        $body['device']['model'] = str_repeat('红', DeviceDescriptor::MODEL_MAX_LENGTH);

        self::assertSame(
            str_repeat('红', DeviceDescriptor::MODEL_MAX_LENGTH),
            OtpVerificationPayload::fromArray($body)->device->model,
        );
    }

    // ========================================================================
    // 校验失败
    // ========================================================================

    /**
     * @param array<string, mixed>        $body
     * @param list<array{string, string}> $expected `[field, code]` 对
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidPayloads(array $body, array $expected): void
    {
        try {
            OtpVerificationPayload::fromArray($body);
            self::fail('Expected a validation failure.');
        } catch (DomainException $exception) {
            self::assertSame(ErrorCode::ValidationFailed, $exception->errorCode());

            $actual = array_map(
                static fn (FieldError $error): array => [$error->field, $error->code->value],
                $exception->fieldErrors(),
            );

            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<array{string, string}>}>
     */
    public static function invalidPayloads(): iterable
    {
        yield 'challenge_id missing' => [
            self::bodyWithout('challenge_id'),
            [['challenge_id', FieldErrorCode::Required->value]],
        ];

        yield 'challenge_id is not a uuid' => [
            self::bodyWith(['challenge_id' => 'not-a-uuid']),
            [['challenge_id', FieldErrorCode::InvalidFormat->value]],
        ];

        yield 'challenge_id is not a string' => [
            self::bodyWith(['challenge_id' => 42]),
            [['challenge_id', FieldErrorCode::InvalidType->value]],
        ];

        yield 'code missing' => [
            self::bodyWithout('code'),
            [['code', FieldErrorCode::Required->value]],
        ];

        // ⚠️ 数字形态的码会丢掉前导零：`000007` 变成 `7`，而 §7.1 的码里
        // 有百万分之一是那个形态（RequestOtpService::generateCode() 特意 str_pad 过）。
        yield 'code sent as a JSON number' => [
            self::bodyWith(['code' => 418396]),
            [['code', FieldErrorCode::InvalidType->value]],
        ];

        yield 'code too short' => [
            self::bodyWith(['code' => '12345']),
            [['code', FieldErrorCode::InvalidFormat->value]],
        ];

        yield 'code too long' => [
            self::bodyWith(['code' => '1234567']),
            [['code', FieldErrorCode::InvalidFormat->value]],
        ];

        yield 'code is not numeric' => [
            self::bodyWith(['code' => 'abcdef']),
            [['code', FieldErrorCode::InvalidFormat->value]],
        ];

        yield 'device missing' => [
            self::bodyWithout('device'),
            [['device', FieldErrorCode::Required->value]],
        ];

        // json_decode 把 JSON 数组也解成 PHP array —— 不拦的话会一路走到
        // $raw['id'] 才以一个费解的类型错误炸掉。
        yield 'device sent as a JSON array' => [
            self::bodyWith(['device' => ['a', 'b']]),
            [['device', FieldErrorCode::InvalidType->value]],
        ];

        yield 'device.id missing' => [
            self::bodyWithoutDeviceField('id'),
            [['device.id', FieldErrorCode::Required->value]],
        ];

        yield 'device.platform missing' => [
            self::bodyWithoutDeviceField('platform'),
            [['device.platform', FieldErrorCode::Required->value]],
        ];

        yield 'device.platform unknown' => [
            self::bodyWithDeviceField('platform', 'ios'),
            [['device.platform', FieldErrorCode::InvalidFormat->value]],
        ];

        yield 'device.model too long' => [
            self::bodyWithDeviceField('model', str_repeat('a', DeviceDescriptor::MODEL_MAX_LENGTH + 1)),
            [['device.model', FieldErrorCode::TooLong->value]],
        ];

        yield 'device.os_version too long' => [
            self::bodyWithDeviceField('os_version', str_repeat('a', DeviceDescriptor::VERSION_MAX_LENGTH + 1)),
            [['device.os_version', FieldErrorCode::TooLong->value]],
        ];

        yield 'unknown top-level field' => [
            self::bodyWith(['refresh_token' => 'nope']),
            [['refresh_token', FieldErrorCode::UnknownField->value]],
        ];

        yield 'unknown device field' => [
            self::bodyWithDeviceField('push_token', 'nope'),
            [['device.push_token', FieldErrorCode::UnknownField->value]],
        ];

        // 全部字段校验完再抛，客户端要一次拿到所有错误才能一次性标红表单。
        yield 'every error is reported at once' => [
            ['challenge_id' => 'nope', 'code' => '1', 'device' => ['platform' => 'ios']],
            [
                ['challenge_id', FieldErrorCode::InvalidFormat->value],
                ['code', FieldErrorCode::InvalidFormat->value],
                ['device.id', FieldErrorCode::Required->value],
                ['device.platform', FieldErrorCode::InvalidFormat->value],
            ],
        ];
    }

    /**
     * ⚠️ 验证码是**一次性凭据**，绝不能进错误文案 —— detail 会进应用日志、
     * Sentry 与任何抓过 4xx 响应的中间层，而它在 10 分钟内都还有效。
     * `PiiRedactionProcessor` 兜的是结构化 context 里的键，兜不住 detail 字符串。
     */
    public function testNeverEchoesTheCodeBackInAnErrorMessage(): void
    {
        try {
            OtpVerificationPayload::fromArray(self::bodyWith(['code' => 'hunter2secret']));
            self::fail('Expected a validation failure.');
        } catch (DomainException $exception) {
            $rendered = $exception->detail().json_encode($exception->fieldErrors());

            self::assertStringNotContainsString('hunter2secret', $rendered);
        }
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * @return array<string, mixed>
     */
    private static function body(): array
    {
        return [
            'challenge_id' => self::CHALLENGE_ID,
            'code' => '418396',
            'device' => [
                'id' => self::DEVICE_ID,
                'platform' => 'android',
                'model' => 'Pixel 7a',
                'os_version' => '14',
                'app_version' => '1.4.0',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function bodyWith(array $overrides): array
    {
        return [...self::body(), ...$overrides];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyWithout(string $field): array
    {
        $body = self::body();
        unset($body[$field]);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyWithDeviceField(string $field, mixed $value): array
    {
        $body = self::body();
        \assert(\is_array($body['device']));
        $body['device'][$field] = $value;

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function bodyWithoutDeviceField(string $field): array
    {
        $body = self::body();
        \assert(\is_array($body['device']));
        unset($body['device'][$field]);

        return $body;
    }
}
