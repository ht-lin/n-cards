<?php

declare(strict_types=1);

namespace App\Shared\Domain\Crypto;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;

/**
 * 加解密失败（§5.3 的门面在任何一步出错时抛这个）。
 *
 * ============================================================================
 * 为什么继承 DomainException
 * ============================================================================
 * `DomainException` 是**刻意不 final** 的（见其类注释：「各模块会继承它做更具体的
 * 类型，好让 catch 能按类型收窄而不是按 code 比字符串」）。继承它有两个直接好处：
 *
 *   1. `ApiProblemExceptionListener` 不用改一行就能把它渲染成 RFC 9457 —— 否则
 *      它会走通用 throwable 分支，被 Symfony 的 `ErrorListener` 记成 CRITICAL
 *      （见 `ErrorCode::logLevel()` 的注释）。
 *   2. 错误码是 `internal_error` 而不是某个新造的 code。这是对的：解密失败对客户端
 *      **没有任何可执行的含义**，重试也没用，客户端唯一能做的就是显示通用错误。
 *      §13.6 禁止在 `/v1` 内随意扩张错误码表，能复用就不新增。
 *
 * ⚠️ 传输层故障（Vault 连不上 / 被封印）用子类 {@see CryptoUnavailable}，
 * 那个是 503 + 可重试。两者的区别对客户端是有意义的，对告警更是 ——
 * §14.4 的「5xx 率 > 1% 持续 5min」只该被真正的 bug 惊动，
 * 而 Vault 封印期间的 503 是**期望行为**（见 `infra/vault/vault.hcl`）。
 *
 * ============================================================================
 * detail 恒为固定文案
 * ============================================================================
 * 与 `DomainException::internal()` 同一条纪律：原始异常消息只进日志的 previous 链，
 * 绝不进响应体。这里的原因比别处更硬 —— 加解密路径上流动的就是明文本身，
 * 一个把 $plaintext 拼进消息的「便于排查」改动等于把会员卡号发给客户端。
 */
class CryptoFailed extends DomainException
{
    /**
     * @param ErrorCode $errorCode 只给子类用 —— {@see CryptoUnavailable} 需要换成
     *                             `service_unavailable`。调用方一律用默认值：
     *                             想要 503 就抛 `CryptoUnavailable`，不要在这里传参，
     *                             否则 `catch (CryptoUnavailable)` 这种按类型收窄的
     *                             写法会漏掉一部分本该被它接住的异常
     */
    public function __construct(
        string $detail = 'Cryptographic operation failed.',
        ?\Throwable $previous = null,
        ErrorCode $errorCode = ErrorCode::InternalError,
    ) {
        parent::__construct($errorCode, $detail, [], [], $previous);
    }
}
