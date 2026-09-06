<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Token;

use App\Shared\Application\Token\AccessTokenSignerInterface;
use App\Shared\Application\Token\SigningKeyProviderInterface;
use App\Shared\Domain\Token\AccessTokenClaims;
use App\Shared\Domain\Token\IssuedAccessToken;

/**
 * JWS 紧凑序列化 + Ed25519 签名（§7.1：`alg = EdDSA`）。
 *
 * ```
 * base64url(header) . "." . base64url(payload) . "." . base64url(sig)
 * sig = Ed25519(seed, "base64url(header).base64url(payload)")
 * ```
 *
 * ============================================================================
 * 为什么手写而不是装一个 JWT 库
 * ============================================================================
 * 我们要签的是**一种算法的一种 claim 集**，没有协商、没有 JWE、没有嵌套 token，
 * 也不需要在运行时解析别人的 header。整件事就是上面那三行。
 *
 * 一个通用 JWT 库带来的是相反方向的东西：一张要显式收窄的算法白名单
 * （`alg: none` 与 HS/RS 混淆是 JWT 历史上最经典的两个洞），一层
 * Configuration/Validator 抽象，以及十几个传递依赖 —— 而这些复杂度全部服务于
 * 我们不打算使用的灵活性。仓库里的 VaultClient、限流器、幂等存储都是同一套取舍
 * （见各自的类注释）。
 *
 * 反过来说，**这个决定的前提是不长出第二种算法**。哪天真要支持多算法或
 * 接受外部签发的 token，那就是该装库的信号，而不是该往这个类里加 `match` 的信号。
 *
 * ============================================================================
 * ⚠️ 三条不能动的细节
 * ============================================================================
 *   1. **base64url，不是 base64**：`+/` 换成 `-_`，去掉 `=` 填充（RFC 7515 §2）。
 *      用普通 base64 签出来的 token 在任何标准验签方那里都是非法的，
 *      而我们自己的验签方（T-108）如果也用普通 base64，**两边会一致地错** ——
 *      直到某个第三方工具（jwt.io、网关）来验它时才暴露。
 *   2. **签的是拼接后的字符串**，不是 header 或 payload 各自签一次，
 *      也不是签解码后的字节。
 *   3. **`kid` 必须进 header**：§5.3 的 6 个月轮换有 24h 双密钥重叠期，
 *      验签方靠它选密钥。漏了它，轮换当天所有旧 token 会在验签方那里
 *      变成「试完所有密钥都不过」，而不是「用 kid 直接选中旧的那把」。
 *
 * ============================================================================
 * `JSON_UNESCAPED_SLASHES` 与排序
 * ============================================================================
 * JWS 不要求 JSON 规范化（签的是**字节**，不是语义），所以这里不需要排序键。
 * 但 `JSON_UNESCAPED_SLASHES` 还是加上：不加的话 UUID 里没有斜杠、今天没差别，
 * 而将来任何一个带 URL 的 claim 都会得到 `\/` 转义 —— 那是合法的 JSON，
 * 却会让人在调试时以为 token 坏了。
 */
final readonly class Ed25519AccessTokenSigner implements AccessTokenSignerInterface
{
    private const JSON_FLAGS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(private SigningKeyProviderInterface $keys)
    {
    }

    public function sign(AccessTokenClaims $claims): IssuedAccessToken
    {
        $key = $this->keys->currentKey();

        $header = self::base64UrlEncode(json_encode([
            'alg' => 'EdDSA',
            'typ' => 'JWT',
            'kid' => $key->kid,
        ], self::JSON_FLAGS));

        $payload = self::base64UrlEncode(json_encode($claims->toPayload(), self::JSON_FLAGS));

        $signingInput = $header.'.'.$payload;

        // `sodium_crypto_sign_detached` 要的是 64 字节的 secret key（seed + 公钥），
        // 而 KV 里存的 PKCS#8 只有 32 字节 seed —— `seed_keypair` 负责把公钥推出来。
        $keypair = sodium_crypto_sign_seed_keypair($key->seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        // 尽力而为地擦掉派生出来的密钥材料。PHP 里这不是可靠的保证
        // （字符串在此之前可能已经被复制过），但 sodium_memzero 是免费的，
        // 而把它省掉需要一个理由。真实的边界仍然是进程边界（见 SigningKey 的类注释）。
        sodium_memzero($keypair);
        sodium_memzero($secretKey);

        $expiresIn = $claims->expiresInSeconds();

        \assert($expiresIn > 0);

        return new IssuedAccessToken($signingInput.'.'.self::base64UrlEncode($signature), $expiresIn);
    }

    /**
     * RFC 7515 §2 的 base64url：`+/` → `-_`，去掉 `=` 填充。
     */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
