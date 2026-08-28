<?php

declare(strict_types=1);

namespace App\Shared\Application\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * §5.3 信封加密的门面：单条加解密。
 *
 * ============================================================================
 * 为什么接口在 Application 而实现在 Infrastructure
 * ============================================================================
 * §12.2 的目录树把 `CryptoServiceInterface.php` 画在 `Shared/Infrastructure/Crypto/`
 * 下，但那样**没有任何模块能引用它**：deptrac 里每个模块 Application 的允许列表是
 * `[_Ports, <M>.Domain, Shared.Domain, Shared.Application, Framework.Core]` ——
 * 没有 `Shared.Infrastructure`。Domain 更窄，只有 `Shared.Domain`。
 *
 * 于是照字面实现的后果是：加解密只能发生在各模块自己的 Infrastructure 层，
 * 也就是被迫塞进 Doctrine 仓储里，而 T-109 的「列表查询用 BatchDecryptor」
 * 恰恰是 Application 层的编排。
 *
 * 所以接口留在这里，值对象（{@see Ciphertext} / {@see CryptoKey}）放 `Shared\Domain\Crypto`，
 * 实现留在 `Shared\Infrastructure\Crypto`。与 T-004 把 `ErrorCode` 放进 `Shared\Domain\Error`
 * 是同一套论证。§12.2 的目录树已随 T-005 一并修正。
 *
 * 顺带地，T-005 的验收标准「deptrac 确认无模块直接 import `VaultTransitCrypto`」
 * 由此**结构性成立**：`Shared.Infrastructure` 不在任何模块的允许列表里，想违规都违不了。
 * 这条由 `tools/deptrac-selftest.sh` 的场景 ③ 钉住。
 *
 * ============================================================================
 * 批量解密请用 BatchDecryptorInterface
 * ============================================================================
 * §5.3 的硬要求：钱包列表可能有 50–200 张卡，**禁止在循环里逐条调 Vault**。
 * 在循环里调本接口的 `decrypt()` 正是那条禁令针对的写法。
 * 一次要解多于一条，就用 {@see BatchDecryptorInterface}。
 */
interface CryptoServiceInterface
{
    /**
     * @param string $plaintext 任意字节串（会被 base64 编码后送给 Vault，二进制安全）
     *
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败 —— 503，可重试
     * @throws CryptoFailed      其余加密失败 —— 500
     */
    public function encrypt(CryptoKey $key, string $plaintext): Ciphertext;

    /**
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败 —— 503，可重试
     * @throws CryptoFailed      密文损坏、key 版本低于 `min_decryption_version` 等 —— 500
     */
    public function decrypt(CryptoKey $key, Ciphertext $ciphertext): string;
}
