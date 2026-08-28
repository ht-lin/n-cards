<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Time\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * AppRole 登录 + token 自动续期（§3.3 / §7.4 / §17.4）——  staging 与 prod 的认证方式。
 *
 * ============================================================================
 * 生命周期
 * ============================================================================
 * ```
 * 首次 token()  ──► auth/approle/login ──► client_token + lease_duration(1h)
 * 后续 token()  ──► 剩余 TTL > 10min ? 直接返回 : auth/token/renew-self
 *                   renew 失败（超过 token_max_ttl=24h，或被吊销）──► 重新 login
 * 收到 403      ──► forget() ──► 下次 token() 重新 login
 * ```
 *
 * `auth/token/renew-self` 就是 §17.4 那份 policy 里唯一一条非 transit 路径的用途。
 *
 * ============================================================================
 * 为什么进程内缓存就够，且**绝不**放 Redis
 * ============================================================================
 * §4.1 的 app 跑在 FrankenPHP 上，worker 进程是长驻的，所以一次登录能覆盖成千上万个
 * 请求，1 小时的 TTL 不会退化成「每请求登录一次」。
 *
 * 放 Redis 共享看起来能省几次登录，但那等于把一个**活的 Vault token** 明文写进
 * 另一个服务。§3.3 承诺防护的是「数据库/备份泄露」，而 Redis 里躺着的 token
 * 可以直接换来全部明文 —— 那条防护线会从「拿到备份也解不开」退化成
 * 「拿到 Redis 就全解开」。省下的那点登录开销买不起这个。
 *
 * 代价是每个 worker 各自登录一次。§9.3 的规模下（峰值约 15 req/s）这完全不值一提。
 *
 * ⚠️ 不加 `readonly`：本类**有状态**（缓存 token 与到期时刻），这是刻意的。
 */
final class AppRoleTokenProvider implements VaultTokenProviderInterface
{
    /**
     * 剩余 TTL 低于这个秒数就续期。
     *
     * 10 分钟是「续期失败还来得及重新登录，且不至于频繁续期」的折中。
     * 取太小（比如 30 秒）的风险很实际：token 在**一个请求的中途**过期 ——
     * batch decrypt 发出去时还有效，Vault 处理时已过期，于是这个请求 503。
     * 10 分钟的余量让这种竞态不可能发生。
     */
    private const RENEW_THRESHOLD_SECONDS = 600;

    private ?string $token = null;

    /** Unix 秒。token 为 null 时无意义。 */
    private int $expiresAt = 0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly string $address,
        private readonly string $roleId,
        private readonly string $secretId,
    ) {
    }

    public function token(): string
    {
        if (null === $this->token) {
            return $this->login();
        }

        if ($this->secondsUntilExpiry() > self::RENEW_THRESHOLD_SECONDS) {
            return $this->token;
        }

        // 续期失败不是错误路径的终点：token 活过 token_max_ttl(24h) 之后就是不可续的，
        // 那时重新登录才是正解。所以这里吞掉续期的失败，回落到 login()。
        try {
            return $this->renew();
        } catch (CryptoUnavailable) {
            $this->forget();

            return $this->login();
        }
    }

    public function forget(): void
    {
        $this->token = null;
        $this->expiresAt = 0;
    }

    /**
     * @throws CryptoUnavailable
     */
    private function login(): string
    {
        if ('' === $this->roleId || '' === $this->secretId) {
            throw new CryptoUnavailable('VAULT_ROLE_ID / VAULT_SECRET_ID are not configured.');
        }

        // ⚠️ secret_id 是凭据，绝不进日志或异常消息。本类同样不注入 logger
        // （理由见 VaultClient 的类注释）。
        $auth = $this->request('auth/approle/login', [
            'role_id' => $this->roleId,
            'secret_id' => $this->secretId,
        ], null);

        return $this->remember($auth, 'Vault AppRole login returned no client token.');
    }

    /**
     * @throws CryptoUnavailable
     */
    private function renew(): string
    {
        $auth = $this->request('auth/token/renew-self', [], $this->token);

        return $this->remember($auth, 'Vault token renewal returned no client token.');
    }

    /**
     * @param array<string, mixed> $auth Vault 响应里的 `auth` 对象
     *
     * @throws CryptoUnavailable
     */
    private function remember(array $auth, string $failureDetail): string
    {
        $token = $auth['client_token'] ?? null;
        $lease = $auth['lease_duration'] ?? null;

        if (!\is_string($token) || '' === $token) {
            throw new CryptoUnavailable($failureDetail);
        }

        $this->token = $token;
        // lease_duration 缺失或为 0（root token 是不过期的 0）时，按「立刻需要续期」
        // 之外的另一端处理：给一个保守的 60 秒，于是下次调用会去续期/重登，
        // 而不是永远信任一个我们并不知道有效期的 token。
        $this->expiresAt = $this->nowSeconds() + (\is_int($lease) && $lease > 0 ? $lease : 60);

        return $token;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> 响应里的 `auth` 对象
     *
     * @throws CryptoUnavailable
     */
    private function request(string $path, array $payload, ?string $token): array
    {
        $headers = ['Accept' => 'application/json'];

        if (null !== $token) {
            $headers['X-Vault-Token'] = $token;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->address, '/').'/v1/'.$path, [
                // ⚠️ 空数组要编成 `{}` 而不是 `[]` —— renew-self 不带参数，
                // 而 `[]` 会让 Vault 回 400。完整说明见 VaultClient::jsonBody()。
                'json' => [] === $payload ? new \stdClass() : $payload,
                'headers' => $headers,
                'timeout' => VaultClient::IDLE_TIMEOUT_SECONDS,
                'max_duration' => VaultClient::MAX_DURATION_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $e) {
            throw new CryptoUnavailable('Vault is unreachable.', $e);
        } catch (HttpClientExceptionInterface $e) {
            throw new CryptoUnavailable('Vault authentication request failed.', $e);
        }

        if ($status < 200 || $status >= 300) {
            // 认证失败一律 503：应用侧没有 bug，是 role_id/secret_id 配错或 Vault 封印，
            // 两者都是「修好即恢复」。detail 不含任何凭据。
            throw new CryptoUnavailable(\sprintf('Vault authentication failed at "%s" (HTTP %d).', $path, $status));
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        $auth = $decoded['auth'] ?? null;

        if (!\is_array($auth)) {
            throw new CryptoUnavailable('Vault authentication response has no auth payload.');
        }

        /* @var array<string, mixed> $auth */
        return $auth;
    }

    private function secondsUntilExpiry(): int
    {
        return $this->expiresAt - $this->nowSeconds();
    }

    private function nowSeconds(): int
    {
        return intdiv($this->clock->nowMillis(), 1000);
    }
}
