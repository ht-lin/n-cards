<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * 全仓库**唯一**打 Vault HTTP API 的地方。
 *
 * 与 T-004 的 {@see \App\Shared\Infrastructure\Redis\RedisConnectionFactory} 同一个定位：
 * 一处超时策略、一处错误映射、一处将来换客户端时要改的地方。
 *
 * ============================================================================
 * ⚠️⚠️ 绝不记录请求体与响应体
 * ============================================================================
 * 这是本类最重要的一条约束，比下面所有内容都重要。
 *
 * `transit/encrypt` 的请求体里就是**明文的 base64**，`transit/decrypt` 的响应体里
 * 同样是明文的 base64。base64 不是加密，任何人拿到日志就等于拿到了会员卡号与邮箱。
 * 而 §8 的 ROPA 里没有把日志算作存储卡号的地方 —— 真写进去就是一次合规事故
 * （见 `PiiRedactionProcessor` 的类注释）。
 *
 * 具体落法：
 *   - 本类**不注入 logger**，不写任何日志。没有 logger 就不可能有这种 bug。
 *   - 异常一律重新包装成 {@see CryptoFailed} / {@see CryptoUnavailable}，
 *     detail 是固定文案；HttpClient 的原始异常只挂在 `previous` 上。
 *     （Symfony 的 `HttpExceptionTrait` 会把 JSON 错误体里的 `errors` 字段拼进
 *     异常消息，Vault 的错误串本身不含明文，所以 previous 进日志是安全的。）
 *   - 自动注入的 HttpClient 只在 debug 级别记 method + URL，不记 body。
 *     URL 里只有 key 名（`transit/decrypt/ncards-card`），没有载荷。
 *
 * 改本类时请重读这一段。「加条日志方便排查」在这里是不成立的理由。
 *
 * ============================================================================
 * 403 会重试一次
 * ============================================================================
 * Vault 对「token 过期/被吊销」与「policy 不允许」都回 403，响应体也区分不出来。
 * 前者在生产里是常态（token TTL 1h，见 {@see AppRoleTokenProvider}），
 * 所以收到 403 时先 `forget()` 再重登重试一次；仍然 403 才是真的权限问题。
 *
 * 只重试**一次**，且只对 403。对 5xx 不重试 —— 那是 Vault 自己有问题，
 * 立刻 fail-closed 交给调用方（§9.4）比在这里堆重试更有用。
 */
final class VaultClient
{
    /**
     * 单次请求的**总**耗时上限（秒）。
     *
     * 这是「一个卡死的 Vault 最多能拖慢一个请求多久」的上限，也是 §9.2 那 99.5%
     * 可用性预算的护栏。取 5 秒的依据：§9.1 给 200 条 batch decrypt 的预算是
     * P95 ≤ 80 ms，5 秒是它的六十多倍 —— 正常流量永远碰不到，真碰到就说明
     * Vault 已经不健康了，此时快速失败比继续等更有价值。
     *
     * ⚠️ 与 `timeout` 不是一回事：Symfony HttpClient 的 `timeout` 是**空闲**超时
     * （两个数据块之间最多等多久），只配它挡不住一个「每 1 秒吐一个字节」的连接。
     * 两个都要配。
     */
    public const MAX_DURATION_SECONDS = 5.0;

    /**
     * 空闲超时（秒）—— 两个数据块之间最多等多久，同时也是建连超时。
     *
     * Vault 是同一个 docker 网络里的容器（§4.1），握手是亚毫秒级的；
     * 与 `RedisConnectionFactory::READ_WRITE_TIMEOUT_SECONDS` 取同一个量级。
     * 不配它的话会沿用 curl 的默认值（无限），于是一个「接受连接但不回包」的
     * Vault 会把请求挂到 `max_duration` 才断 —— 那条注释在 RedisConnectionFactory
     * 里有完整版，症状一模一样。
     */
    public const IDLE_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly VaultTokenProviderInterface $tokens,
        #[Autowire('%env(VAULT_ADDR)%')]
        private readonly string $address,
    ) {
    }

    /**
     * 对 Vault 的一个路径发 POST（Transit 的 encrypt/decrypt/hmac 全是 POST）。
     *
     * @param string               $path    不含 `/v1/` 前缀，例如 `transit/encrypt/ncards-card`
     * @param array<string, mixed> $payload 请求体
     *
     * @return array<string, mixed> 响应里的 `data` 对象；没有 `data` 时返回空数组
     *
     * @throws CryptoUnavailable 传输故障 / 封印 / 未初始化 / 认证失败 —— 503
     * @throws CryptoFailed      4xx 业务错误（密文损坏、key 不存在等）—— 500
     */
    public function write(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * 对 Vault 的一个路径发 GET。
     *
     * 加解密路径上**用不到**它 —— Transit 的 encrypt/decrypt/hmac 全是 POST。
     * 调用方有三类：
     *   - `VaultKvSigningKeyProvider` 读 `secret/data/ncards/jwt/current`（T-104）——
     *     生产路径上唯一的一个，也是 ncards-app policy 里唯一的可读路径
     *   - 运维/测试读 `auth/approle/role/ncards-app/role-id` 这类元数据
     *   - T-404 读 `transit/keys/<name>` 判断 rewrap 进度（那用的是 ncards-ops 身份）
     *
     * @param string $path 不含 `/v1/` 前缀
     *
     * @return array<string, mixed> 响应里的 `data` 对象
     *
     * @throws CryptoUnavailable 传输故障 / 封印 / 未初始化 / 认证失败 —— 503
     * @throws CryptoFailed      4xx 业务错误 —— 500
     */
    public function read(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, mixed>|null $payload null = GET，不带请求体
     *
     * @return array<string, mixed>
     *
     * @throws CryptoUnavailable
     * @throws CryptoFailed
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $response = $this->send($method, $path, $payload, $this->tokens->token());

        // 403 有两种可能：token 过期（常态）与 policy 不允许（配置错）。
        // 分不出来，所以先按前者处理：忘掉 token 重登一次。
        if (403 === $response['status']) {
            $this->tokens->forget();
            $response = $this->send($method, $path, $payload, $this->tokens->token());
        }

        return $this->decode($path, $response['status'], $response['body']);
    }

    /**
     * 免认证的 `sys/health`，给 {@see \App\Shared\Infrastructure\Health\VaultHealthCheck} 用。
     *
     * 单独一个方法而不是复用 {@see write()}，因为它是 GET、不带 token，
     * 而且**状态码本身就是返回值**（Vault 用 200/429/501/503 表达四种状态），
     * 不能走那套「非 2xx 一律翻成异常」的映射。
     *
     * @return int HTTP 状态码
     *
     * @throws CryptoUnavailable 连都连不上
     */
    public function healthStatus(): int
    {
        try {
            return $this->httpClient->request('GET', $this->url('sys/health'), [
                // standbyok：单节点部署（§14.2）用不上，但配上以后加副本时行为不变。
                'query' => ['standbyok' => 'true'],
                'timeout' => self::IDLE_TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'headers' => ['Accept' => 'application/json'],
            ])->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new CryptoUnavailable('Vault is unreachable.', $e);
        }
    }

    /**
     * @param array<string, mixed>|null $payload null = 不带请求体（GET）
     *
     * @return array{status: int, body: string}
     *
     * @throws CryptoUnavailable
     */
    private function send(string $method, string $path, ?array $payload, string $token): array
    {
        $options = [
            'headers' => [
                'X-Vault-Token' => $token,
                'Accept' => 'application/json',
            ],
            'timeout' => self::IDLE_TIMEOUT_SECONDS,
            'max_duration' => self::MAX_DURATION_SECONDS,
        ];

        if (null !== $payload) {
            $options['json'] = self::jsonBody($payload);
        }

        try {
            $response = $this->httpClient->request($method, $this->url($path), $options);

            // getStatusCode() 不抛；getContent(false) 的 false 关掉「非 2xx 就抛」，
            // 好让状态码的判定统一收敛到 decode()，而不是散在两套错误处理里。
            return ['status' => $response->getStatusCode(), 'body' => $response->getContent(false)];
        } catch (TransportExceptionInterface $e) {
            // 连不上、DNS 解不出、超时 —— Vault 没了，503。
            throw new CryptoUnavailable('Vault is unreachable.', $e);
        } catch (HttpClientExceptionInterface $e) {
            // 兜底：getContent(false) 理论上只剩 TransportException 一条抛出路径，
            // 但 HttpClient 的异常层级将来可能扩，不留兜底会让新异常变成 500 internal_error。
            throw new CryptoUnavailable('Vault request failed.', $e);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws CryptoUnavailable
     * @throws CryptoFailed
     */
    private function decode(string $path, int $status, string $body): array
    {
        if ($status >= 200 && $status < 300) {
            // ⚠️ 2xx 不等于「有 JSON body」。Vault 的写入端点里有一大类回的是
            // **204 No Content**，body 是空串，而 `json_decode('')` 抛 JsonException。
            //
            // 那条异常不是 DomainException，于是它会绕过本类全部的
            // CryptoFailed/CryptoUnavailable 映射：`ApiProblemExceptionListener`
            // 认不出它，Symfony 的 ErrorListener 把它记成 CRITICAL，客户端拿到一个
            // 光秃秃的 500 —— 明明只是「这个端点没有返回内容」。
            //
            // 今天所有调用方都恰好打在 200 端点上（transit 的 encrypt/decrypt/hmac、
            // approle 的 login/renew-self、transit/keys/<name>/rotate），所以踩不到；
            // 但 204 的端点就在隔壁，随手一加就中招：
            //   - `transit/keys/<name>/config`（T-404 轮换要配 min_decryption_version）
            //   - `auth/approle/role/<name>`、`sys/policies/acl/<name>`（运维/测试用）
            if ('' === trim($body)) {
                return [];
            }

            try {
                $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // 2xx 但 body 不是 JSON —— 现实里通常是中间挡了个反向代理或门户，
                // 回的是一张 HTML 错误页。归 CryptoFailed 而不是 CryptoUnavailable：
                // 重试没用，是部署拓扑要修。
                // ⚠️ 绝不把 $body 拼进消息 —— decrypt 的响应体里就是明文（见类注释）。
                throw new CryptoFailed(\sprintf('Vault returned a non-JSON body for "%s".', $path), $e);
            }

            if (!\is_array($decoded)) {
                throw new CryptoFailed('Vault returned a malformed response.');
            }

            $data = $decoded['data'] ?? [];

            if (!\is_array($data)) {
                throw new CryptoFailed('Vault returned a malformed response.');
            }

            /* @var array<string, mixed> $data */
            return $data;
        }

        // ⚠️ 下面的 detail 里只放**路径**，绝不放请求体或响应体。
        // 路径形如 `transit/decrypt/ncards-card`，不含载荷。
        throw match (true) {
            // 503 = 封印（生产每次重启后的常态，见 vault.hcl）；
            // 501 = 未初始化（首次部署，runbook 第一步还没做）。
            // 两者都是「等人处理」，都是可重试的 503，不是 bug。
            503 === $status, 501 === $status => new CryptoUnavailable(
                \sprintf('Vault is sealed or uninitialised (HTTP %d).', $status),
            ),
            // 重登之后仍然 403 —— 这次是真的权限问题（policy 少了一条，或 key 名打错）。
            // 归 503 而不是 500：应用侧无 bug，是 Vault 配置要修，而且修好即恢复。
            403 === $status => new CryptoUnavailable(
                \sprintf('Vault denied access to "%s"; check the ncards-app policy.', $path),
            ),
            $status >= 500 => new CryptoUnavailable(\sprintf('Vault returned HTTP %d.', $status)),
            // 400（密文损坏、参数不合法）、404（key 不存在）等 —— 这些是我们的 bug。
            default => new CryptoFailed(\sprintf('Vault rejected the request to "%s" (HTTP %d).', $path, $status)),
        };
    }

    private function url(string $path): string
    {
        return rtrim($this->address, '/').'/v1/'.ltrim($path, '/');
    }

    /**
     * ⚠️ 空数组必须编成 `{}` 而不是 `[]`，否则 Vault 回 **400**。
     *
     * PHP 的 `json_encode([])` 出的是 `[]`（JSON 数组），而 Vault 的请求体解析器
     * 要的是一个 JSON **对象**。踩中它的是所有「不需要参数」的端点，
     * 而那恰好是最容易被忽略的一类：
     *
     *   - `auth/token/renew-self`（{@see AppRoleTokenProvider::renew()}）——
     *     这条尤其隐蔽：续期永远 400，провider 会静默回落到重新 login，
     *     功能看起来是好的，只是每小时多一次登录，而 §17.4 里
     *     `auth/token/renew-self` 那条授权成了摆设。
     *   - `auth/approle/role/<name>/secret-id`
     *   - `transit/keys/<name>/rotate`
     *
     * 强制点：`tests/Integration/Shared/Vault/AppRolePolicyTest::testAppRoleCanRenewItsOwnToken`
     * 会真的调一次 renew-self。
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function jsonBody(array $payload): array|\stdClass
    {
        return [] === $payload ? new \stdClass() : $payload;
    }
}
