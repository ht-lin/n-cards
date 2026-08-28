<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Vault;

use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Infrastructure\Vault\AppRoleTokenProvider;
use App\Shared\Infrastructure\Vault\StaticTokenProvider;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * AppRole 的 token 生命周期（§3.3 / §7.4：token TTL 1h，自动续期）。
 *
 * 这些分支在生产里每小时都会走一遍，但在开发与 CI 里**永远不会** ——
 * 那两个环境用的是 StaticTokenProvider（dev 模式 root token）。
 * 所以它们只有在这里被测到，没有第二次机会。
 */
#[CoversClass(AppRoleTokenProvider::class)]
#[CoversClass(StaticTokenProvider::class)]
final class AppRoleTokenProviderTest extends TestCase
{
    /**
     * 起点时刻（Unix 毫秒）。具体值无所谓 —— 用例只关心**相对**推进量，
     * 而 AppRoleTokenProvider 的判定全部基于「剩余多少秒」。
     */
    private const BASE_MILLIS = 1_787_000_000_000;

    /** @var list<array{url: string, body: array<string, mixed>, raw: string, token: ?string}> */
    private array $requests = [];

    public function testLogsInOnceAndReusesTheToken(): void
    {
        $provider = $this->provider([$this->auth('token-1', 3600)]);

        self::assertSame('token-1', $provider->token());
        self::assertSame('token-1', $provider->token());
        self::assertSame('token-1', $provider->token());

        self::assertCount(1, $this->requests, 'token 还没到续期阈值就该直接复用 —— 每次调用都登录会让每个请求多一次往返。');
        self::assertStringEndsWith('/v1/auth/approle/login', $this->requests[0]['url']);
        self::assertSame('role-abc', $this->requests[0]['body']['role_id']);
        self::assertSame('secret-xyz', $this->requests[0]['body']['secret_id']);
    }

    /**
     * 剩余 TTL 低于 10 分钟就续期。阈值取 10 分钟而不是 30 秒，是为了避免
     * 「token 在一个请求的中途过期」这种竞态：发出去时还有效，Vault 处理时已过期。
     */
    public function testRenewsWhenTheTokenIsCloseToExpiry(): void
    {
        $clock = new FrozenClock(self::BASE_MILLIS);
        $provider = $this->provider([$this->auth('token-1', 3600), $this->auth('token-1-renewed', 3600)], $clock);

        self::assertSame('token-1', $provider->token());

        // 走到还剩 9 分钟 —— 低于阈值。
        $clock->advance((3600 - 540) * 1000);

        self::assertSame('token-1-renewed', $provider->token());
        self::assertCount(2, $this->requests);
        self::assertStringEndsWith('/v1/auth/token/renew-self', $this->requests[1]['url']);
        // renew-self 要带着当前 token —— 这就是 §17.4 policy 里那条路径的用途。
        self::assertSame('token-1', $this->requests[1]['token']);
    }

    /**
     * ⚠️ 回归守卫：renew-self 的请求体必须是 `{}`，**不能**是 `[]`。
     *
     * PHP 的 `json_encode([])` 出的是 JSON 数组 `[]`，而 Vault 的请求体解析器要对象 ——
     * 它会回 400。这个 bug 极其隐蔽：续期永远失败，provider 静默回落到重新 login，
     * 功能看起来完全正常，只是每小时多一次登录，而 §17.4 里
     * `auth/token/renew-self` 那条授权成了摆设。
     *
     * 断言的是**原始请求体字符串** —— json_decode 之后 `{}` 与 `[]` 在 PHP 里
     * 都是空数组，分辨不出来，那正是当初没被发现的原因。
     */
    public function testRenewSendsAnEmptyJsonObjectNotAnArray(): void
    {
        $clock = new FrozenClock(self::BASE_MILLIS);
        $provider = $this->provider([$this->auth('token-1', 3600), $this->auth('token-2', 3600)], $clock);

        $provider->token();
        $clock->advance((3600 - 540) * 1000);
        $provider->token();

        self::assertSame('{}', $this->requests[1]['raw'], 'Vault 对 `[]` 回 400 —— 见 VaultClient::jsonBody() 的注释。');
    }

    public function testDoesNotRenewWhileComfortablyValid(): void
    {
        $clock = new FrozenClock(self::BASE_MILLIS);
        $provider = $this->provider([$this->auth('token-1', 3600)], $clock);

        $provider->token();
        // 还剩 11 分钟，高于阈值。
        $clock->advance((3600 - 660) * 1000);

        self::assertSame('token-1', $provider->token());
        self::assertCount(1, $this->requests);
    }

    /**
     * token 活过 token_max_ttl（24h）之后就是不可续的 —— 那时重新登录才是正解。
     * 所以续期失败不是错误路径的终点。
     */
    public function testFallsBackToLoginWhenRenewalFails(): void
    {
        $clock = new FrozenClock(self::BASE_MILLIS);
        $provider = $this->provider([
            $this->auth('token-1', 3600),
            new MockResponse('{"errors":["token is not renewable"]}', ['http_code' => 403]),
            $this->auth('token-2', 3600),
        ], $clock);

        $provider->token();
        $clock->advance((3600 - 60) * 1000);

        self::assertSame('token-2', $provider->token());
        self::assertCount(3, $this->requests);
        self::assertStringEndsWith('/v1/auth/token/renew-self', $this->requests[1]['url']);
        self::assertStringEndsWith('/v1/auth/approle/login', $this->requests[2]['url']);
    }

    /**
     * VaultClient 收到 403 时调 forget()，下一次 token() 必须重新登录。
     * 这是「token 被提前吊销」的恢复路径。
     */
    public function testForgetForcesAFreshLogin(): void
    {
        $provider = $this->provider([$this->auth('token-1', 3600), $this->auth('token-2', 3600)]);

        self::assertSame('token-1', $provider->token());
        $provider->forget();
        self::assertSame('token-2', $provider->token());

        self::assertCount(2, $this->requests);
        self::assertStringEndsWith('/v1/auth/approle/login', $this->requests[1]['url']);
    }

    /**
     * lease_duration 缺失或为 0 时，不能永远信任这个 token —— 给 60 秒的保守值，
     * 于是下次调用会去续期而不是一直用下去。
     */
    public function testTreatsAMissingLeaseDurationConservatively(): void
    {
        $clock = new FrozenClock(self::BASE_MILLIS);
        $provider = $this->provider([
            new MockResponse('{"auth":{"client_token":"token-1"}}', [
                'http_code' => 200,
                'response_headers' => ['content-type' => 'application/json'],
            ]),
            $this->auth('token-2', 3600),
        ], $clock);

        self::assertSame('token-1', $provider->token());

        // 60 秒的保守 TTL 已经低于 10 分钟阈值，所以下一次调用就会去续期。
        self::assertSame('token-2', $provider->token());
        self::assertCount(2, $this->requests);
    }

    public function testLoginFailureBecomesServiceUnavailable(): void
    {
        $provider = $this->provider([
            new MockResponse('{"errors":["invalid role or secret id"]}', ['http_code' => 400]),
        ]);

        $this->expectException(CryptoUnavailable::class);

        $provider->token();
    }

    public function testTransportFailureBecomesServiceUnavailable(): void
    {
        $provider = $this->provider([new MockResponse('', ['error' => 'connection refused'])]);

        $this->expectException(CryptoUnavailable::class);

        $provider->token();
    }

    /**
     * ⚠️ secret_id 是凭据，绝不能出现在异常消息里。
     */
    public function testFailuresNeverLeakTheSecretId(): void
    {
        $provider = $this->provider([
            new MockResponse('{"errors":["invalid role or secret id"]}', ['http_code' => 400]),
        ]);

        try {
            $provider->token();
            self::fail('应当抛出 CryptoUnavailable。');
        } catch (CryptoUnavailable $e) {
            for ($e2 = $e; null !== $e2; $e2 = $e2->getPrevious()) {
                self::assertStringNotContainsString('secret-xyz', $e2->getMessage());
            }
        }
    }

    public function testMissingCredentialsFailFastWithoutTouchingTheNetwork(): void
    {
        $provider = $this->provider([], null, '', '');

        try {
            $provider->token();
            self::fail('应当抛出 CryptoUnavailable。');
        } catch (CryptoUnavailable $e) {
            self::assertStringContainsString('VAULT_ROLE_ID', $e->getMessage());
        }

        self::assertSame([], $this->requests, '凭据都没配就不该发请求 —— 那只会得到一个指向「权限不足」的误导性 403。');
    }

    /**
     * 静态 token 的 forget() 是空操作：重登也拿不到新的。
     * 于是 dev 下的 403 会原样再失败一次并如实抛出 —— 那正是我们要的，
     * dev 下的 403 是配置错了，不该被一次假装成功的重登掩盖。
     */
    public function testStaticProviderForgetIsANoOp(): void
    {
        $provider = new StaticTokenProvider('root-token');

        self::assertSame('root-token', $provider->token());
        $provider->forget();
        self::assertSame('root-token', $provider->token());
    }

    public function testStaticProviderRejectsAnEmptyToken(): void
    {
        $this->expectException(CryptoUnavailable::class);

        (new StaticTokenProvider(''))->token();
    }

    // ========================================================================
    // 夹具
    // ========================================================================

    private function auth(string $token, int $leaseSeconds): MockResponse
    {
        return new MockResponse(
            json_encode(['auth' => ['client_token' => $token, 'lease_duration' => $leaseSeconds]], \JSON_THROW_ON_ERROR),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(
        array $responses,
        ?FrozenClock $clock = null,
        string $roleId = 'role-abc',
        string $secretId = 'secret-xyz',
    ): AppRoleTokenProvider {
        $this->requests = [];

        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            /** @var array{headers?: list<string>, body?: string} $options */
            $token = null;

            foreach ($options['headers'] ?? [] as $header) {
                if (str_starts_with(strtolower($header), 'x-vault-token:')) {
                    $token = trim(substr($header, \strlen('x-vault-token:')));
                }
            }

            /** @var array<string, mixed> $body */
            $body = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];

            $this->requests[] = ['url' => $url, 'body' => $body, 'raw' => (string) ($options['body'] ?? ''), 'token' => $token];

            return array_shift($responses) ?? new MockResponse('{}', ['http_code' => 500]);
        });

        return new AppRoleTokenProvider(
            $http,
            $clock ?? new FrozenClock(self::BASE_MILLIS),
            'http://vault.test:8200',
            $roleId,
            $secretId,
        );
    }
}
