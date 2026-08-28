<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * §14.4 的硬性要求：日志脱敏。
 *
 * > **必须**配置 Monolog processor 脱敏：`barcode_value`、`note`、`email`、`code`、
 * > `refresh_token` 一律替换为 `[REDACTED]`。
 *
 * ============================================================================
 * 为什么这是**安全**功能而不是整洁功能
 * ============================================================================
 * 服务端加密（§5.3）保护的是数据库里的静态数据。日志是绕过它的旁路：
 * 一条 `$logger->info('decrypt failed', ['barcode_value' => $plaintext])`
 * 会把明文条码写进 Loki，而 Loki 里没有 Vault。§8 的 ROPA 也没把日志算作
 * 存储会员卡号的地方 —— 真写进去就是一次合规事故。
 *
 * §13.3 的 CI「敏感日志扫描」是第一道防线（grep 掉插值），本类是第二道。
 * 两道都要有：grep 拦不住 `['user' => $userEntity]` 这种整体传对象的写法。
 *
 * ============================================================================
 * 一个刻意的取舍：`code`
 * ============================================================================
 * 脱敏键里的 `code` 指的是 §7.1 的 **OTP 验证码**。但 `code` 同时也是 §6.1 的
 * **错误码**字段名，而错误码恰恰是我们最想留在日志里的东西。
 *
 * 所以这里对 `code` 用**值形状**判断而不是无条件替换：只有看起来像 OTP 的值
 * （纯数字，4–10 位）才脱敏，`revision_conflict` 这类保持原样。
 * 宁可对一个恰好全是数字的错误码误伤，也不能把 OTP 漏出去。
 */
final readonly class PiiRedactionProcessor implements ProcessorInterface
{
    public const REDACTED = '[REDACTED]';

    /**
     * 无条件脱敏的键名（§14.4 点名的那几个，外加几个同类）。
     *
     * 比较时统一转小写，所以这里全部小写。
     *
     * @var list<string>
     */
    private const ALWAYS_REDACT = [
        'barcode_value',
        'note',
        'email',
        'refresh_token',
        'access_token',
        'password',
        'passphrase',
        'secret',
        'token',
        'authorization',
    ];

    /** 只在值「看起来像 OTP」时脱敏的键 —— 理由见类注释。 */
    private const REDACT_IF_OTP_SHAPED = ['code'];

    /** OTP 的形状：纯数字 4–10 位。§7.1 的验证码是 6 位。 */
    private const OTP_PATTERN = '/^\d{4,10}$/';

    /** 递归深度上限，防着环状或超深结构把日志写爆。 */
    private const MAX_DEPTH = 8;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: self::redact($record->context, 0),
            extra: self::redact($record->extra, 0),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    private static function redact(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $normalisedKey = is_string($key) ? strtolower($key) : '';

            if (\in_array($normalisedKey, self::ALWAYS_REDACT, true)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            if (\in_array($normalisedKey, self::REDACT_IF_OTP_SHAPED, true)
                && \is_scalar($value)
                && 1 === preg_match(self::OTP_PATTERN, (string) $value)
            ) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = \is_array($value) ? self::redact($value, $depth + 1) : $value;
        }

        return $result;
    }
}
