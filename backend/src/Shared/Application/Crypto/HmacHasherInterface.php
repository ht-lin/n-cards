<?php

declare(strict_types=1);

namespace App\Shared\Application\Crypto;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoUnavailable;

/**
 * 带 Vault pepper 的 HMAC-SHA256（§3.8 / §5.3 的 `transit/ncards-hmac`）。
 *
 * ============================================================================
 * 用在哪
 * ============================================================================
 *   - `users.email_hash`（BYTEA UNIQUE）—— §3.8：邮箱**不存明文列**，
 *     查找与唯一约束都走这个 hash（T-101）
 *   - `cards.barcode_value_fingerprint`（BYTEA）—— 重复卡检测（T-109）
 *   - OTP 的 `code_hash`（T-102）
 *
 * ============================================================================
 * 为什么是 Vault HMAC 而不是 PHP 的 hash_hmac()
 * ============================================================================
 * 关键在 pepper 的存放位置。用 `hash_hmac('sha256', $email, $pepper)` 的话，
 * pepper 必须以明文出现在应用进程里（环境变量或配置文件），于是它会跟着
 * `pg_dump`、`.env`、容器 inspect、core dump 一起走。
 *
 * §3.3 的威胁边界里，「数据库文件/备份泄露」是我们**承诺防护**的一档。
 * 若 pepper 与密文同处一台机器的磁盘上，拿到备份的人可以对着已知邮箱字典
 * 逐个算 HMAC 去比对 —— 邮箱空间是可枚举的，防护就归零了。
 * 放进 Vault 后，密钥材料永不出 Vault（§5.3），备份里只有算不回去的摘要。
 *
 * 代价是每次 hash 要一次网络往返。可以接受：这几个调用点都在登录、建卡这类
 * 低频写路径上，不在钱包列表那种批量读路径上。
 *
 * ============================================================================
 * ⚠️ 这把 key 绝不轮换
 * ============================================================================
 * 见 {@see \App\Shared\Domain\Crypto\CryptoKey::isRotatable()} 的注释 ——
 * 轮换会让全部既有 `email_hash` 查找失效，且 HMAC 单向不可补救。
 */
interface HmacHasherInterface
{
    /**
     * @param string $input 任意字节串
     *
     * @return string **32 字节裸摘要**，直接进 BYTEA 列
     *
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败 —— 503，可重试
     * @throws CryptoFailed      其余失败 —— 500
     */
    public function hash(string $input): string;

    /**
     * 校验 `$input` 的摘要是否等于 `$digest`。
     *
     * 实现**在本地**用 `hash_equals` 比，只为算 `$input` 的摘要打一次 Vault，
     * 不用 Transit 的 `verify` 端点——那会多一次往返，换不到任何东西
     * （摘要不是秘密，比较发生在哪一侧不影响安全性）。
     * policy 里仍然留着 `transit/verify/ncards-hmac`（§17.4 原样），
     * 是为了将来真需要时不用改 policy。
     *
     * @param string $digest 32 字节裸摘要，通常刚从 BYTEA 列读出来
     *
     * @throws CryptoUnavailable Vault 不可达 / 被封印 / 认证失败 —— 503，可重试
     * @throws CryptoFailed      其余失败 —— 500
     */
    public function verify(string $input, string $digest): bool;
}
