<?php

declare(strict_types=1);

namespace App\Shared\Domain\Identity;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * UUID 值对象（§12.2 的 `Shared/Domain/Uuid.php`）。
 *
 * ============================================================================
 * 为什么是手写而不是 symfony/uid
 * ============================================================================
 * deptrac 的 `Shared.Domain` 允许列表是**空的** —— 连 `Symfony\*` 与 `Psr\*` 都不能 import
 * （`Framework.Core` 收集 `^(Symfony|Psr|Twig)\\`）。这不是可以顺手放宽的规则：
 * §12.2 的「Domain 不得 import 任何框架类型」是整套分层的地基，为一个 128 位整数
 * 的格式化去掀它不划算。
 *
 * 本类只用全局命名空间的内建函数（`preg_match`、`bin2hex`、`hex2bin`、`substr`、`hexdec`），
 * deptrac 的 `classLike` 收集器匹配不到函数，所以空白名单是可满足的。
 * 生成逻辑在 {@see Uuid7Generator}。
 *
 * ============================================================================
 * 归一化：一律小写
 * ============================================================================
 * 大小写混写的输入照收，但对外**永远**输出小写。理由是两端的既有行为：
 * Android 的 `java.util.UUID.toString()` 出小写，Postgres 的 `uuid` 类型输出也是小写。
 * 不归一化的话，同一个 id 会以两种字符串形态出现在日志与幂等键里。
 *
 * ============================================================================
 * `fromString()` 刻意**不**强制 version 7
 * ============================================================================
 * §5.4.3 规定卡的 id 由客户端生成。今天 T-010 的生成器出 v7，但若将来某个旧客户端
 * 送来一个 v4，事后收紧校验是 §13.6 在 `/v1` 内明令禁止的（「收紧校验」）。
 * 需要 v7 语义的调用方自己用 {@see version()} / {@see timestampMillis()} 判，
 * 把决定权留在调用点。
 */
final readonly class Uuid implements \JsonSerializable, \Stringable
{
    /**
     * 规范形式：8-4-4-4-12 个十六进制字符。
     *
     * 刻意**不**接受 `{...}` 花括号形式、`urn:uuid:` 前缀与无连字符形式 ——
     * 多一种接受的写法就多一种同一个 id 的字符串形态，而幂等键、日志关联与
     * 数据库唯一约束都是按字符串比的。
     */
    private const PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /** RFC 9562 的 Nil UUID。当哨兵值用是 bug 温床，一律拒绝。 */
    private const NIL = '00000000-0000-0000-0000-000000000000';

    /** RFC 9562 的 Max UUID。同上。 */
    private const MAX = 'ffffffff-ffff-ffff-ffff-ffffffffffff';

    /**
     * @param string $value 已校验且已归一化为小写的规范形式
     */
    private function __construct(private string $value)
    {
    }

    /**
     * @throws DomainException `validation_failed` —— 调用方通常直接把客户端传来的 id 丢进来，
     *                         所以默认按「客户端输入非法」处理
     */
    public static function fromString(string $value): self
    {
        $uuid = self::tryFromString($value);

        if (null === $uuid) {
            throw DomainException::validationFailed(new FieldError('id', FieldErrorCode::InvalidFormat, 'Value is not a valid UUID.'));
        }

        return $uuid;
    }

    /**
     * 非法时返回 null，把「这算不算客户端错误」的决定留给调用方。
     */
    public static function tryFromString(string $value): ?self
    {
        if (!self::isValid($value)) {
            return null;
        }

        return new self(strtolower($value));
    }

    public static function isValid(string $value): bool
    {
        if (1 !== preg_match(self::PATTERN, $value)) {
            return false;
        }

        $normalised = strtolower($value);

        return self::NIL !== $normalised && self::MAX !== $normalised;
    }

    /**
     * 从 16 字节的二进制形态构造（{@see Uuid7Generator} 与 Doctrine 的二进制列会用到）。
     *
     * @throws DomainException `validation_failed`
     */
    public static function fromBytes(string $bytes): self
    {
        if (16 !== \strlen($bytes)) {
            throw DomainException::validationFailed(new FieldError('id', FieldErrorCode::InvalidFormat, 'A UUID must be exactly 16 bytes.'));
        }

        $hex = bin2hex($bytes);

        return self::fromString(\sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    /**
     * 16 字节的二进制形态。
     */
    public function toBytes(): string
    {
        $bytes = hex2bin(str_replace('-', '', $this->value));

        // 构造时已保证是 32 个十六进制字符，hex2bin 不可能失败；断言只为让 phpstan
        // 在 level 8 下收窄 string|false，而不是靠 @var 糊过去。
        \assert(false !== $bytes);

        return $bytes;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * RFC 9562 的版本号（v7 = 7，v4 = 4，……）。
     */
    public function version(): int
    {
        return (int) hexdec($this->value[14]);
    }

    /**
     * v7 前 48 位携带的 Unix 毫秒时间戳。
     *
     * 只对 v7 有意义 —— 别的版本那 48 位是随机数，读出来是个无意义的时间。
     *
     * @throws \LogicException 该 UUID 不是 v7 时。这是**编程错误**而非客户端错误，
     *                         所以不是 DomainException：调用方应该先用 version() 判
     */
    public function timestampMillis(): int
    {
        if (7 !== $this->version()) {
            throw new \LogicException(\sprintf('Only a version 7 UUID carries a timestamp; this one is version %d.', $this->version()));
        }

        // 前 12 个十六进制字符 = 48 位。PHP 的 int 是 64 位，装得下。
        return (int) hexdec(substr(str_replace('-', '', $this->value), 0, 12));
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
