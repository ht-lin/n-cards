<?php

declare(strict_types=1);

namespace App\Shared\Domain\Pagination;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * 不透明游标的编解码器（§6.1：游标式分页，**不使用** offset）。
 *
 * ============================================================================
 * 形状无关，是刻意的
 * ============================================================================
 * 本类不知道游标里装的是什么，只负责 base64url(JSON) 的往返与一组安全守卫。
 * 这样 §5.4.1 的同步游标 `{"seq":123456,"sv":1}` 在 T-202 能原样复用本类，
 * 而普通列表端点用自己的 `{"v":1,"k":{...}}`，两边不需要各写一份编解码。
 *
 * ============================================================================
 * ⚠️ 游标**不签名、不加密**，这是经过判断的决定，不是遗漏
 * ============================================================================
 * 载荷是「排序键 + id」—— 调用方本来就持有这些值（它们就在上一页的响应里）。
 * 而服务端在每一次查询里**总是**重新施加归属/`audience` 过滤（§5.2、§4.2 规则 5），
 * 游标只影响窗口位置，不影响可见性。所以篡改游标能做到的极限，是在自己**已经有权看到**
 * 的结果集里换一个位置 —— 没有任何越权。
 *
 * 反过来，签名会把 Vault 的密钥管理（T-005）拖进分页这条最热的路径，还要处理密钥轮换
 * 期间的旧游标兼容，换来的是零威胁缓解。
 *
 * 后来的 reviewer 大概率会想「顺手把游标签个名」。**不要**。要改先推翻上面这段。
 *
 * ============================================================================
 * 解码失败抛的是 validation_failed，不是 full_resync_required
 * ============================================================================
 * 同一个非法游标，在普通列表端点是「你传了个坏参数」（400），
 * 在 `/v1/sync` 是「你的游标失效了，清库重同步」（409 `full_resync_required`，§5.4.1）。
 * 含义由**调用方**决定，所以本类只抛通用的 `validation_failed`，
 * T-202 的 SyncController 捕获后自行改抛。
 */
final readonly class Cursor implements \Stringable
{
    /** 编码后的长度上限。远超任何合法游标，纯粹是防呆与防滥用。 */
    private const MAX_ENCODED_LENGTH = 512;

    /** base64url 字母表（无填充）。 */
    private const ENCODED_PATTERN = '/^[A-Za-z0-9_-]+$/';

    /** JSON 嵌套深度上限 —— 游标是扁平的键值对，8 层绰绰有余。 */
    private const MAX_JSON_DEPTH = 8;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(private array $payload, private string $encoded)
    {
    }

    /**
     * @param array<string, mixed> $payload 非空关联数组
     */
    public static function encode(array $payload): self
    {
        if ([] === $payload) {
            throw new \InvalidArgumentException('A cursor payload must not be empty.');
        }

        $json = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        // base64url，去掉尾部 `=` 填充 —— 免得客户端在 URL 里再转义一次。
        $encoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return new self($payload, $encoded);
    }

    /**
     * @throws DomainException `validation_failed`（字段名 `cursor`）
     */
    public static function decode(string $raw): self
    {
        // 守卫按「最便宜的先跑」排序：长度 → 字符集 → base64 → JSON → 结构。
        if ('' === $raw || \strlen($raw) > self::MAX_ENCODED_LENGTH) {
            throw self::invalid();
        }

        if (1 !== preg_match(self::ENCODED_PATTERN, $raw)) {
            throw self::invalid();
        }

        // strict: true —— 非法字符不要被静默丢弃，那会让两个不同的游标解出同一个值。
        $json = base64_decode(strtr($raw, '-_', '+/'), true);

        if (false === $json) {
            throw self::invalid();
        }

        try {
            $payload = json_decode($json, true, self::MAX_JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }

        // 必须是非空的关联数组：JSON 里的标量、null、列表都不是合法游标。
        if (!\is_array($payload) || [] === $payload || array_is_list($payload)) {
            throw self::invalid();
        }

        /* @var array<string, mixed> $payload */
        return new self($payload, $raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function __toString(): string
    {
        return $this->encoded;
    }

    private static function invalid(): DomainException
    {
        // detail 刻意不说明「哪里不对」—— 游标是不透明的，把解析细节讲给客户端听
        // 只会诱导它去构造游标，而那正是我们不希望它做的事。
        return DomainException::validationFailed(
            new FieldError('cursor', FieldErrorCode::InvalidFormat, 'Cursor is not a valid pagination cursor.'),
        );
    }
}
