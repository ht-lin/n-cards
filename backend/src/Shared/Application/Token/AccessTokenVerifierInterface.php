<?php

declare(strict_types=1);

namespace App\Shared\Application\Token;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Token\AccessTokenClaims;

/**
 * `Authorization: Bearer <jwt>` 的验签与解析（§7.1，T-105）。
 *
 * 接口在 Application、实现在 Infrastructure，理由与
 * {@see AccessTokenSignerInterface} 逐字相同（没有任何模块能看见 Shared.Infrastructure）。
 *
 * ============================================================================
 * ⚠️ 本接口**只回答「这枚 token 是我们签的、且还没过期」**
 * ============================================================================
 * 它**不**回答「这个会话还有效吗」。§7.1 明确写着服务端**不做** access token
 * 黑名单 —— 会话被撤销之后，已签发的 access token 仍然会在这里通过，
 * 直到它自己过期（最长 15 分钟）。那个窗口是**被接受的**，不是遗漏。
 *
 * 所以这里不注入任何仓储、不查库。想在这里加一句「顺便查一下 session 有没有被撤销」
 * 的话，先读 §7.1 与 ADR-0015：那等于给每个带 Bearer 的请求加一趟 DB 往返，
 * 并把一条已经写进规格的决定悄悄反悔掉。
 *
 * 真正需要「会话此刻是否有效」的是 refresh 与撤销路径，它们走的是
 * `sessions` 表本身，不是这枚 token。
 */
interface AccessTokenVerifierInterface
{
    /**
     * **全部** 401 共用的这一句文案。
     *
     * ============================================================================
     * ⚠️ 为什么它在接口上，而不是各实现里各写一份
     * ============================================================================
     * 拒绝一次带 Bearer 的请求有两个来源：
     *
     *   - {@see \App\Shared\Infrastructure\Http\AuthenticationListener}
     *     —— 压根没带 `Authorization`，或它不是 `Bearer <token>` 的形状；
     *   - 本接口的实现 —— 带了，但验不过（签名、`alg`、`kid`、claim 集…）。
     *
     * 两处各写各的文案，结果就是「没带 header」与「token 不对」在响应里**可区分**，
     * 而那恰好是这两个类的注释都说要避免的事。它今天不是一个安全洞
     *（攻击者当然知道自己带没带 header），但它是一条会随时间长歪的裂缝：
     * 下一个人在其中一处「顺便说清楚一点」，两条路径就真的分叉了。
     *
     * 放一个常量在两边唯一的公共地面上，让它**不可能**分叉。
     * `tests/Api/SessionLifecycleTest::testEveryFlavourOfABadBearerLooksTheSame()`
     * 从真实 HTTP 上钉死这一点。
     *
     * ⚠️ 「令牌过期」是**唯一**的例外，它有自己的文案与 code（`token_expired`）——
     * 因为客户端对它的处置不同：去刷新，而不是清会话跳登录。
     */
    public const REJECTED = 'The access token is not valid.';

    /**
     * @param string $token JWS 紧凑序列化形式，**不含** `Bearer ` 前缀
     *
     * @throws DomainException   `token_expired`（401）—— **仅** `exp` 已过。
     *                           客户端据此去刷新；
     *                           `token_invalid`（401）—— 其余全部情形（格式坏、
     *                           签名不过、`alg` 不对、`kid` 不认识、claim 集不对）。
     *                           客户端据此清空本地会话并跳登录。
     *                           ⚠️ 两者分开**只**是为了这个处置差异；
     *                           任何一种「签名相关」的失败都必须是 `token_invalid`，
     *                           不要为了好排查再细分下去 —— 那会把「这枚 token
     *                           哪里不对」变成一个免费的探测接口
     * @throws CryptoUnavailable 503 —— 取不到验签公钥（Vault 不可达 / 封印）。
     *                           ⚠️ 这**不是** 401：区别是「你的凭证不行」与
     *                           「我们现在验不了」，后者可重试，且不该让客户端清会话
     */
    public function verify(string $token, \DateTimeImmutable $now): AccessTokenClaims;
}
