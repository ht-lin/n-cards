<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Token;

use App\Shared\Application\Token\SigningKeyProviderInterface;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Domain\Token\SigningKey;
use App\Shared\Infrastructure\Vault\VaultClient;

/**
 * 从 Vault KV v2 读 JWT 签名密钥（§5.3 密钥清单第四行）。
 *
 * 密钥由 `infra/vault/bootstrap.sh` 在建栈时生成并写入
 * `secret/data/ncards/jwt/current`，形状：
 *
 * ```json
 * {"private_key": "<PKCS#8 PEM>", "public_key": "<SPKI PEM>", "kid": "<16 hex>", "algorithm": "EdDSA"}
 * ```
 *
 * 那个脚本里写着「T-005 只负责把密钥放到位，不写读取侧 —— 那是 T-104 的事」。
 * 这个类就是那一侧。
 *
 * ============================================================================
 * ⚠️ 为什么必须自己解 PEM，而不是用 openssl_*
 * ============================================================================
 * PHP 的 OpenSSL 扩展**不支持 EdDSA 签名** —— `openssl_sign()` 没有对应的
 * `OPENSSL_ALGO_*`，`openssl_pkey_get_details()` 对 Ed25519 也不给出裸密钥字节。
 * 能签 Ed25519 的是 `ext-sodium`，而它要的是 32 字节的**裸 seed**，不是 PEM。
 *
 * 好在这个转换是**定长切片**，不需要 ASN.1 解析器：Ed25519 的 PKCS#8 私钥
 * DER 恒为 48 字节，前 16 字节是固定不变的头（版本 + OID 1.3.101.112 +
 * OCTET STRING 包装），后 32 字节就是 seed。SPKI 公钥 DER 恒为 44 字节 =
 * 12 字节头 + 32 字节公钥。两个头都逐字节校验过再切，不匹配就当作密钥损坏。
 *
 * ⚠️ 别把这段「泛化」成一个 DER 解析器。它之所以安全且只有二十行，
 * 全靠「Ed25519 的编码是唯一且定长的」这条前提；一个真正的解析器要处理
 * 长度可变、属性可选、算法可变，而那正是 CVE 的产地。
 *
 * ============================================================================
 * 进程内缓存**带 TTL**
 * ============================================================================
 * FrankenPHP 的 worker 是长驻进程，容器里这个服务是单例 —— 一个朴素的
 * `private ?SigningKey $cached` 会活到进程重启为止。
 *
 * 那正好撞上 §5.3 的轮换语义：JWT 签名密钥 6 个月轮换一次，**双密钥重叠期 24h**。
 * 永久缓存的话，运维在 Vault 里换了密钥，而 worker 里还钉着旧的那把 ——
 * 24 小时后重叠期结束、旧 kid 被移出验签集合，这个进程签出的每一枚 token
 * 才开始被拒。症状出现在轮换**之后一天**，没人会把它和轮换联系起来。
 *
 * 所以缓存带一个远小于重叠期的 TTL（`ncards.jwt.key_cache_ttl_seconds`，300 秒）：
 * 轮换在 5 分钟内自然生效，而稳态下每 5 分钟一次 KV 读的成本可以忽略
 * （登录本身就不是高频路径）。
 *
 * ⚠️ 这与 {@see \App\Shared\Infrastructure\Crypto\VaultTransitCrypto} 的
 * 「不缓存明文」不冲突：那条禁的是**用户数据**跨请求驻留，而这里缓存的是
 * 一把全局密钥 —— 它本来就要在进程里活到签完这次名为止。
 */
final class VaultKvSigningKeyProvider implements SigningKeyProviderInterface
{
    /**
     * Ed25519 PKCS#8 私钥 DER 的固定前缀（16 字节）。
     *
     * `302e020100300506032b657004220420`
     *   30 2e             SEQUENCE, 46 字节
     *   02 01 00          INTEGER 0（PKCS#8 版本）
     *   30 05             SEQUENCE, 5 字节（AlgorithmIdentifier）
     *     06 03 2b6570    OID 1.3.101.112 = id-Ed25519
     *   04 22             OCTET STRING, 34 字节（PrivateKey）
     *     04 20           内层 OCTET STRING, 32 字节 = seed
     */
    private const PKCS8_ED25519_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    /**
     * Ed25519 SPKI 公钥 DER 的固定前缀（12 字节）。
     *
     * `302a300506032b6570032100`
     *   30 2a             SEQUENCE, 42 字节
     *   30 05 06 03 2b6570  AlgorithmIdentifier，同上
     *   03 21 00          BIT STRING, 33 字节，0 个未用位 → 后面 32 字节是公钥
     */
    private const SPKI_ED25519_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";

    /** `bootstrap.sh` 写进 KV 的算法标识。对不上说明拿错了密钥。 */
    private const EXPECTED_ALGORITHM = 'EdDSA';

    /**
     * 所有读取与解析失败共用同一句文案。
     *
     * ⚠️ **绝不**说清楚是哪一步失败的，更不能回显 PEM 片段或字节偏移 ——
     * 异常文案会进日志，而这条路径上流过的是签名密钥本身。
     * 对运维而言「密钥读不出来」与「密钥格式不对」的处置是同一个
     * （去看 `infra/vault/bootstrap.sh` 与那条 KV），细分没有价值。
     */
    private const UNREADABLE = 'The JWT signing key could not be read.';

    private ?SigningKey $cached = null;

    private ?\DateTimeImmutable $cachedUntil = null;

    /** @var array<non-empty-string, non-empty-string>|null */
    private ?array $cachedVerificationKeys = null;

    private ?\DateTimeImmutable $verificationKeysCachedUntil = null;

    /**
     * @param string      $kvPath          KV v2 里的逻辑路径，**不含** `data/` 段，
     *                                     例如 `ncards/jwt/current`。必须与
     *                                     `infra/vault/bootstrap.sh` 的 `JWT_KV_PATH` 一致
     * @param string      $previousKvPath  §5.3 重叠期里那把**上一代**密钥的逻辑路径。
     *                                     这条 KV 在稳态下**不存在**，读不到不是错误
     * @param int<1, max> $cacheTtlSeconds 远小于 §5.3 的 24h 重叠期
     */
    public function __construct(
        private readonly VaultClient $vault,
        private readonly ClockInterface $clock,
        private readonly string $kvPath,
        private readonly string $previousKvPath,
        private readonly int $cacheTtlSeconds,
    ) {
    }

    public function currentKey(): SigningKey
    {
        $now = $this->clock->now();

        if (null !== $this->cached && null !== $this->cachedUntil && $now < $this->cachedUntil) {
            return $this->cached;
        }

        $key = $this->fetch($this->kvPath);

        $this->cached = $key;
        $this->cachedUntil = $now->modify(\sprintf('+%d seconds', $this->cacheTtlSeconds));

        return $key;
    }

    /**
     * §5.3 的「验两把、签一把」的**验**那一半（T-105）。
     *
     * 缓存与 {@see currentKey()} 分开，用的是同一个 TTL 但各自独立的槽位 ——
     * 两者的读取次数不同（每个带 Bearer 的请求都要验签，而签名只发生在登录与刷新），
     * 共用一个槽会让其中一个的过期时刻由另一个的调用节奏决定。
     */
    public function verificationKeys(): array
    {
        $now = $this->clock->now();

        if (null !== $this->cachedVerificationKeys
            && null !== $this->verificationKeysCachedUntil
            && $now < $this->verificationKeysCachedUntil
        ) {
            return $this->cachedVerificationKeys;
        }

        // current 读不出来 = 没有任何人能通过鉴权。与 fetch() 里那一段同一个理由，
        // 收敛成 503 而不是 500：处置是运维动作，且 §14.4 的 5xx 告警不该被它点着。
        try {
            [$kid, $publicKey] = $this->fetchPublicKey($this->kvPath);
        } catch (CryptoFailed $e) {
            throw new CryptoUnavailable(self::UNREADABLE, $e);
        }

        $keys = [$kid => $publicKey];

        // ⚠️ previous 缺失是**常态**，不是故障：首次部署到第一次轮换之间的
        // 全部时间里这条 KV 都不存在。
        //
        // 只吞 CryptoFailed（VaultClient 把 404 映射到这里），**不吞**
        // CryptoUnavailable —— 后者覆盖 403（policy 里漏了这条路径）、
        // 5xx 与 sealed。把 403 一起吞掉的话，「忘了更新 ncards-app.hcl」
        // 的症状就变成「轮换当天旧 token 全部失效」，而日志里一行都没有。
        try {
            [$previousKid, $previousPublicKey] = $this->fetchPublicKey($this->previousKvPath);
            $keys[$previousKid] = $previousPublicKey;
        } catch (CryptoFailed) {
            // 没有上一代密钥。稳态。
        }

        $this->cachedVerificationKeys = $keys;
        $this->verificationKeysCachedUntil = $now->modify(\sprintf('+%d seconds', $this->cacheTtlSeconds));

        return $keys;
    }

    /**
     * @return array{non-empty-string, non-empty-string} [kid, 32 字节裸公钥]
     *
     * @throws CryptoFailed      这条 KV 不存在（404）—— 调用方决定它是否可选
     * @throws CryptoUnavailable Vault 不可达 / 封印 / 权限不足，或值不是一把合法的密钥
     */
    private function fetchPublicKey(string $kvPath): array
    {
        $data = $this->readEnvelope($kvPath);

        $publicPem = $data['public_key'] ?? null;
        $kid = $data['kid'] ?? null;

        if (!\is_string($publicPem) || !\is_string($kid) || '' === $kid) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        $publicKey = self::publicKeyFromSpkiPem($publicPem);

        \assert('' !== $publicKey);

        return [$kid, $publicKey];
    }

    /**
     * @throws CryptoUnavailable
     */
    private function fetch(string $kvPath): SigningKey
    {
        try {
            $data = $this->readEnvelope($kvPath);
        } catch (CryptoFailed $e) {
            // ============================================================
            // ⚠️ 一律收敛成 503，包括 VaultClient 判定为 500 的那些（比如 404）
            // ============================================================
            // `VaultClient` 的默认映射是对的：4xx 通常是「密文坏了 / key 不存在」
            // 这类重试也没用的业务错误，归 500。但**这条路径**不适用那条规则。
            //
            // 签名密钥读不出来的后果是「所有人都登不进去」，而处置永远是一个
            // 运维动作（重跑 bootstrap.sh、unseal、修 policy）—— 与 ADR-0004 的
            // 手工 unseal 完全同构。500 会有两个具体后果：
            //   - 客户端（T-010 的 ApiError）不重试，用户要手动重来；
            //   - §14.4 的「API 5xx 率高」告警把一次可预期的运维窗口报成 bug，
            //     而 503 走的是 ErrorCode::logLevel() 的 warning 分支。
            //
            // 换句话说：这里区分「Vault 挂了」与「密钥没了」对调用方毫无价值，
            // 两者都是「登录暂时不可用，去看 Vault」。
            throw new CryptoUnavailable(self::UNREADABLE, $e);
        }

        $privatePem = $data['private_key'] ?? null;
        $kid = $data['kid'] ?? null;

        if (!\is_string($privatePem) || !\is_string($kid) || '' === $kid) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        return new SigningKey(self::seedFromPkcs8Pem($privatePem), $kid);
    }

    /**
     * 读一条 JWT 密钥 KV 并校验它确实是一把 Ed25519 密钥。
     *
     * ⚠️ **不**把 {@see CryptoFailed} 收敛成 {@see CryptoUnavailable}——
     * 那个转换归调用方，因为两个调用方对「这条 KV 不存在」的判断相反：
     * `current` 缺失是故障（{@see fetch()} 转 503），
     * `previous` 缺失是稳态（{@see verificationKeys()} 静默略过）。
     * 在这里统一转掉的话，后者就再也分不出「没有上一代密钥」与「Vault 挂了」。
     *
     * @return array<string, mixed>
     *
     * @throws CryptoFailed      这条 KV 不存在
     * @throws CryptoUnavailable Vault 不可达 / 封印 / 权限不足，或值不是一把 Ed25519 密钥
     */
    private function readEnvelope(string $kvPath): array
    {
        // KV v2 的读路径要插一段 `data/`：逻辑路径 `ncards/jwt/current`
        // 对应的 API 路径是 `secret/data/ncards/jwt/current`。
        // ⚠️ 少了那一段会打到 KV v1 的形状上，Vault 回 404，而 404 在这里
        // 与「密钥不存在」无法区分 —— 症状是「刚建的栈登录不了」。
        $envelope = $this->vault->read('secret/data/'.$kvPath);

        $data = $envelope['data'] ?? null;

        if (!\is_array($data)) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        $algorithm = $data['algorithm'] ?? null;

        if (self::EXPECTED_ALGORITHM !== $algorithm) {
            // 拿到的不是一把 Ed25519 密钥。可能是有人手工覆盖了这条 KV，
            // 也可能是 kvPath 配错了指到了别的 secret 上。
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        /* @var array<string, mixed> $data */
        return $data;
    }

    /**
     * PKCS#8 PEM → 32 字节 seed。
     *
     * @throws CryptoUnavailable 不是一份合法的 Ed25519 PKCS#8 私钥
     */
    public static function seedFromPkcs8Pem(string $pem): string
    {
        return self::sliceAfterPrefix(
            self::derFromPem($pem, 'PRIVATE KEY'),
            self::PKCS8_ED25519_PREFIX,
        );
    }

    /**
     * SPKI PEM → 32 字节公钥。
     *
     * T-105 起这是 {@see verificationKeys()} 取验签材料的入口。
     * 仍然保持 `public static`：集成测试直接拿 `bootstrap.sh` 写进同一条 KV 的
     * `public_key` 验一枚真签出来的 token —— 「验签方能不能用这份公钥验过」
     * 正是签名侧唯一无法自证的部分。
     *
     * @throws CryptoUnavailable 不是一份合法的 Ed25519 SPKI 公钥
     */
    public static function publicKeyFromSpkiPem(string $pem): string
    {
        return self::sliceAfterPrefix(
            self::derFromPem($pem, 'PUBLIC KEY'),
            self::SPKI_ED25519_PREFIX,
        );
    }

    /**
     * @throws CryptoUnavailable
     */
    private static function derFromPem(string $pem, string $label): string
    {
        $begin = '-----BEGIN '.$label.'-----';
        $end = '-----END '.$label.'-----';

        $start = strpos($pem, $begin);
        $stop = strpos($pem, $end);

        if (false === $start || false === $stop || $stop <= $start) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        $body = substr($pem, $start + \strlen($begin), $stop - $start - \strlen($begin));

        // `strict` 模式：PEM 体里除了 base64 字母表与换行不该有别的东西。
        $der = base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);

        if (false === $der) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        return $der;
    }

    /**
     * @throws CryptoUnavailable
     */
    private static function sliceAfterPrefix(string $der, string $prefix): string
    {
        $expectedLength = \strlen($prefix) + SigningKey::SEED_BYTES;

        // 长度与前缀都对上才切。`hash_equals` 而不是 `===`：这里比的是公开的
        // 结构常量、不是秘密，用它纯粹是为了不让下一个人把这行改成
        // 「先比长度再 substr 比较」那种会随内容提前返回的写法。
        if ($expectedLength !== \strlen($der) || !hash_equals($prefix, substr($der, 0, \strlen($prefix)))) {
            throw new CryptoUnavailable(self::UNREADABLE);
        }

        return substr($der, \strlen($prefix));
    }
}
