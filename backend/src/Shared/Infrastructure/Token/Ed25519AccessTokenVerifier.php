<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Token;

use App\Shared\Application\Token\AccessTokenVerifierInterface;
use App\Shared\Application\Token\SigningKeyProviderInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Token\AccessTokenClaims;

/**
 * {@see Ed25519AccessTokenSigner} 的镜像（§7.1，T-105）。
 *
 * ```
 * header . "." . payload . "." . sig      三段，base64url
 * 验的是 Ed25519(pub[kid], "header.payload") == sig
 * ```
 *
 * 手写而不装 JWT 库的论证见签名侧的类注释。那段论证在**验签侧更重要**：
 * 通用库带来的头号风险恰恰是「按 header 的 `alg` 选算法」这个默认行为，
 * 而下面第一条纪律就是把它焊死。
 *
 * ============================================================================
 * ⚠️ 四条纪律，每一条对应一个已知的 JWT 洞
 * ============================================================================
 *  1. **`alg` 必须逐字等于 `EdDSA`，且在验签之前检查。**
 *     JWT 历史上最经典的两个洞都在这里：`alg: none`（签名段留空就通过），
 *     以及 `alg: HS256` + 拿**公钥**当 HMAC 密钥（公钥是公开的，于是任何人
 *     都能签出合法 token）。两者的共同前提都是「听 header 的话去选算法」。
 *     这个类只认一种算法，`alg` 在这里是一个**必须匹配的常量**，不是一个开关。
 *
 *  2. **按 `kid` 直接选密钥，绝不「挨个试」。**
 *     回落到遍历会让轮换故障静默通过：`previous` 配错了、`kid` 写错了，
 *     token 照样验得过，直到某天两把密钥都不匹配才炸 —— 而那时已经没人记得
 *     当初改过什么。认不出的 `kid` 就是 `token_invalid`。
 *
 *  3. **先验签，再解析 payload。**
 *     反过来写的话，payload 里的内容会在「这枚 token 是不是我们签的」之前
 *     就被解析、被信任，哪怕只是用来决定抛哪个异常。
 *     `exp` 的判断因此排在签名之后 —— 一枚伪造的过期 token 应该得到
 *     `token_invalid`，不是 `token_expired`。
 *
 *  4. **不留 clock skew 容差。**
 *     签发方与验签方是同一个进程组、同一台机器、同一个 `ClockInterface`。
 *     给 `exp` 加 30 秒宽限只会让 §7.1 那个「撤销后最长 15 分钟」的窗口变成
 *     15 分 30 秒，换不到任何东西。真需要它的那天（多机部署 + 时钟漂移）
 *     应该先修时钟。
 */
final readonly class Ed25519AccessTokenVerifier implements AccessTokenVerifierInterface
{
    /** 本类唯一接受的 `alg`。它是一个常量，不是一个由 header 决定的变量（纪律 1）。 */
    private const EXPECTED_ALG = 'EdDSA';

    /** Ed25519 签名恒为 64 字节。 */
    private const SIGNATURE_BYTES = 64;

    /** JSON 解析深度上限。header 与 payload 都是扁平对象。 */
    private const MAX_JSON_DEPTH = 8;

    /**
     * §7.1 的 claim 集，**恰好这六个**。
     *
     * 与 {@see AccessTokenClaims::toPayload()} 同源：那边多写一个键、这边就会拒，
     * 于是「往 access token 里偷偷塞字段」在测试里是可见的。
     */
    private const REQUIRED_CLAIMS = ['sub', 'sid', 'did', 'jti', 'iat', 'exp'];

    /**
     * 除「过期」之外全部拒绝情形共用的文案。
     *
     * ⚠️ **绝不**细分成「签名不过」/「kid 不认识」/「claim 少了一个」。
     * 对客户端而言处置完全相同（清会话、跳登录），而可辨认的差异等于给
     * 攻击者一个免费的预言机：他可以靠响应差异确认「我伪造的这枚 token
     * 卡在哪一步」，从而逐步逼近一枚能通过的。
     *
     * 常量本体在接口上，与 `AuthenticationListener`「没带 header」那条共用 ——
     * 理由见 {@see AccessTokenVerifierInterface::REJECTED} 的注释。
     */
    public const REJECTED = AccessTokenVerifierInterface::REJECTED;

    public function __construct(private SigningKeyProviderInterface $keys)
    {
    }

    public function verify(string $token, \DateTimeImmutable $now): AccessTokenClaims
    {
        $segments = explode('.', $token);

        if (3 !== \count($segments)) {
            throw self::rejected();
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $segments;

        $header = self::decodeJson($encodedHeader);

        // 纪律 1：在碰签名之前，先把算法钉死。
        if (self::EXPECTED_ALG !== ($header['alg'] ?? null)) {
            throw self::rejected();
        }

        $kid = $header['kid'] ?? null;

        if (!\is_string($kid) || '' === $kid) {
            throw self::rejected();
        }

        // 纪律 2：查得到就用，查不到就拒。没有第三条路。
        // ⚠️ verificationKeys() 在 Vault 不可达时抛 CryptoUnavailable（503），
        // 那个异常**故意不 catch** —— 它与「你的 token 不行」是两回事。
        $publicKey = $this->keys->verificationKeys()[$kid] ?? null;

        if (null === $publicKey) {
            throw self::rejected();
        }

        $signature = self::base64UrlDecode($encodedSignature);

        // sodium_crypto_sign_verify_detached() 对长度不对的签名会抛
        // SodiumException 而不是返回 false —— 那会变成一个 500。
        if (self::SIGNATURE_BYTES !== \strlen($signature)) {
            throw self::rejected();
        }

        // ⚠️ 验的是**拼接后的原始字符串**，不是解码后的字节，也不是重新编码的结果。
        // 拿解析出来的 header/payload 重新 json_encode 再验，会因为键序、空格、
        // 转义方式的任何差异而失败 —— 而那种失败是间歇性的、极难定位的。
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        if (!sodium_crypto_sign_verify_detached($signature, $signingInput, $publicKey)) {
            throw self::rejected();
        }

        // 纪律 3：从这一行往下，payload 才是可信的。
        return self::claimsFrom(self::decodeJson($encodedPayload), $now);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws DomainException
     */
    private static function claimsFrom(array $payload, \DateTimeImmutable $now): AccessTokenClaims
    {
        // 「恰好六个」是双向的：少一个是残缺，多一个说明签名侧偷偷加了 claim
        // （见 AccessTokenClaims 的类注释：那是一扇单向门）。
        //
        // ⚠️ 比的是**集合**，不是顺序 —— JSON 对象的键序不是语义的一部分，
        // 拿 `array_keys() !== REQUIRED_CLAIMS` 去比会让签名侧某天换个 json_encode
        // 的写法就全线 401。
        $keys = array_keys($payload);

        if ([] !== array_diff(self::REQUIRED_CLAIMS, $keys) || [] !== array_diff($keys, self::REQUIRED_CLAIMS)) {
            throw self::rejected();
        }

        $issuedAt = $payload['iat'];
        $expiresAt = $payload['exp'];

        // RFC 7519 的 NumericDate 是**秒级整数**，不是字符串、不是毫秒。
        // 收得比签发侧宽（接受 "1757..."）会让一个手工构造的 token 在这里
        // 走上与真 token 不同的路径。
        if (!\is_int($issuedAt) || !\is_int($expiresAt)) {
            throw self::rejected();
        }

        // ⚠️ 一枚「将来才签发」的 token 是畸形的，拒掉。
        //
        // 这条看起来多余（签名侧用的是同一个时钟），但它挡住一类**本仓库特有**
        // 的真实错误：这里到处都是毫秒时间戳（`FrozenClock` 的构造参数、
        // `Uuid7Generator`、`timestampMillis()`），而 RFC 7519 的 NumericDate
        // 是**秒**。哪天有人把 `AccessTokenClaims` 改成毫秒，`is_int` 照样通过、
        // `exp` 变成公元 55000 年 —— 每一枚 token 都永不过期，而**没有任何测试会红**。
        //
        // 用 `iat` 而不是「给 exp 设一个上界」来查：后者要在验签器里硬编码一个
        // TTL 策略（那是 ncards_jwt.yaml 的事），而 iat 的约束是自明的。
        if ($issuedAt > $now->getTimestamp()) {
            throw self::rejected();
        }

        // ⚠️ `tryFromString()` 而不是 `fromString()`：后者抛的是
        // `validation_failed`（400，且带一个名为 `id` 的字段错误），
        // 而这里的语境是「这枚 token 不合法」= 401。一枚 sub 写坏的伪造 token
        // 若回 400 + 字段错误，就成了一条能把伪造 token 与真 token 区分开的信道。
        $subject = self::uuidClaim($payload['sub']);
        $sessionId = self::uuidClaim($payload['sid']);
        $deviceId = self::uuidClaim($payload['did']);
        $tokenId = self::uuidClaim($payload['jti']);

        // 纪律 4：无容差。`>=` 而不是 `>` —— exp 那一秒本身就算过期，
        // 与 Session::isExpiredAt() 的口径一致。
        if ($now->getTimestamp() >= $expiresAt) {
            // ⚠️ 这是**唯一**一个不返回 token_invalid 的分支，而且只有走到这里
            // （签名已验过）才能这么说。客户端靠这个码决定「去刷新」而不是「跳登录」。
            throw new DomainException(ErrorCode::TokenExpired, 'The access token has expired.');
        }

        return new AccessTokenClaims(
            $subject,
            $sessionId,
            $deviceId,
            $tokenId,
            (new \DateTimeImmutable('@'.$issuedAt))->setTimezone(new \DateTimeZone('UTC')),
            (new \DateTimeImmutable('@'.$expiresAt))->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /**
     * @throws DomainException `token_invalid` —— 不是字符串，或不是规范形式的 UUID
     */
    private static function uuidClaim(mixed $value): Uuid
    {
        $uuid = \is_string($value) ? Uuid::tryFromString($value) : null;

        if (null === $uuid) {
            throw self::rejected();
        }

        return $uuid;
    }

    /**
     * base64url → JSON 对象。
     *
     * @return array<string, mixed>
     *
     * @throws DomainException
     */
    private static function decodeJson(string $segment): array
    {
        try {
            $decoded = json_decode(self::base64UrlDecode($segment), true, self::MAX_JSON_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::rejected();
        }

        // 顶层必须是 JSON 对象。`[1,2]` 与 `"x"` 都会让下游的数组访问
        // 变成一个费解的类型错误（500），而它其实只是一枚畸形的 token（401）。
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw self::rejected();
        }

        /* @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * RFC 7515 §2 的 base64url 解码。
     *
     * ⚠️ `strict` 模式。非严格模式会**静默忽略**字母表之外的字符，
     * 于是 `abc!!def` 与 `abcdef` 解出同一个结果 —— 那等于让一枚 token 有
     * 无数种等价写法，而任何基于 token 字面值的东西（日志关联、幂等键）都会因此错位。
     *
     * 不需要补 `=` 填充：PHP 的 `base64_decode()` 接受无填充输入，
     * 而签名侧（{@see Ed25519AccessTokenSigner}）按 RFC 7515 §2 把填充去掉了。
     *
     * 解码失败返回空串，交给调用方按「畸形」拒掉 —— 空串既不是合法 JSON，
     * 长度也不是 64，两个调用点都会拒。
     */
    private static function base64UrlDecode(string $segment): string
    {
        $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

        return false === $decoded ? '' : $decoded;
    }

    private static function rejected(): DomainException
    {
        return new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
    }
}
