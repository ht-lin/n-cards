<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Http\OtpVerifyController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/auth/otp/verify` 在**真实 HTTP** 上的形状（T-104）。
 *
 * ⚠️ 防枚举那条（拒绝路径不可区分）不在这里，在
 * {@see OtpEnumerationResistanceTest}。这里只验「端点本身是不是照契约干活」。
 *
 * 码是用例自己种进去的（{@see RequiresOtpStack::seedChallenge()}）——
 * 真码只在邮件里，而 test 环境的信躺在加密过的队列里。理由见那个方法的注释。
 */
#[CoversClass(OtpVerifyController::class)]
final class OtpVerifyEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const PATH = '/v1/auth/otp/verify';

    private const CODE = '418396';

    protected function setUp(): void
    {
        $this->bootOtpStack();
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    // ========================================================================
    // 正向
    // ========================================================================

    public function testReturns200WithTheContractedBody(): void
    {
        $response = $this->verify();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        // §7.4 / §7.3：令牌绝不许被任何中间层缓存。
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = self::decode($response);

        self::assertSame(['access_token', 'expires_in', 'refresh_token', 'user'], array_keys($body));
        // §7.1：15 分钟。
        self::assertSame(900, $body['expires_in']);
        self::assertSame(
            ['id', 'username', 'locale', 'onboarding_complete', 'created_at'],
            array_keys($body['user']),
        );
    }

    /**
     * §13.1 契约优先在响应方向上的强制点。
     */
    public function testTheResponseSatisfiesTheOpenApiContract(): void
    {
        self::assertResponseMatchesContract('post', self::PATH, $this->verify());
    }

    /**
     * §7.1：access token 是 JWT（EdDSA / Ed25519），也就是三段 base64url。
     *
     * 签名本身的正确性由
     * `tests/Unit/Shared/Infrastructure/Token/Ed25519AccessTokenSignerTest` 用
     * `sodium_crypto_sign_verify_detached()` 独立验过；这里只确认端点真的
     * 把签出来的那个东西原样发了出去，没有在某一层被转义或截断。
     */
    public function testTheAccessTokenIsACompactJwsWithTheSpecClaims(): void
    {
        $body = self::decode($this->verify());

        self::assertMatchesRegularExpression(
            '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/',
            $body['access_token'],
        );

        [$header, $payload] = array_map(self::decodeSegment(...), \array_slice(explode('.', $body['access_token']), 0, 2));

        self::assertSame('EdDSA', $header['alg']);
        self::assertArrayHasKey('kid', $header);
        self::assertSame(['sub', 'sid', 'did', 'jti', 'iat', 'exp'], array_keys($payload));
        self::assertSame($body['user']['id'], $payload['sub']);
        self::assertSame(self::DEVICE_ID, $payload['did']);
    }

    /**
     * §7.1：refresh 是 32 字节随机的 base64url，**不透明**。
     * 43 个字符正好是 32 字节去掉 padding 之后的长度。
     */
    public function testTheRefreshTokenIsThirtyTwoOpaqueRandomBytes(): void
    {
        $refresh = self::decode($this->verify())['refresh_token'];

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $refresh);
        self::assertSame(32, \strlen((string) base64_decode(strtr($refresh, '-_', '+/'), true)));
    }

    /**
     * ⚠️ **T-104 的验收标准**：首次验证成功即注册，且 `username IS NULL`。
     *
     * 那个 null 是 §5.2 三个候选方案里被显式选中的中间态 —— 用户此刻处于
     * `onboarding_incomplete`，客户端据 `onboarding_complete` 路由到
     * username 设定页（T-107），而那一页不可跳过。
     */
    public function testAFirstVerificationCreatesAUserRowWithoutAUsername(): void
    {
        $body = self::decode($this->verify());

        self::assertNull($body['user']['username']);
        self::assertFalse($body['user']['onboarding_complete']);

        // 直接读库，而不是只信响应体 —— 验收标准说的是「user 行」。
        self::assertSame(
            [null],
            $this->connection->fetchFirstColumn('SELECT username FROM users WHERE id = ?', [$body['user']['id']]),
        );
    }

    public function testAReturningUserKeepsTheirIdentity(): void
    {
        $email = self::uniqueEmail();
        $user = $this->seedUser($email);
        $challenge = $this->seedChallenge(self::CODE, $email);

        $body = self::decode($this->send(self::body($challenge->id()->toString())));

        self::assertSame($user->id()->toString(), $body['user']['id']);
    }

    public function testCreatesADeviceAndASessionRow(): void
    {
        $body = self::decode($this->verify());

        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM devices WHERE id = ?', [self::DEVICE_ID]),
        );
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM sessions WHERE user_id = ?', [$body['user']['id']]),
        );

        // §7.1：库里只存 refresh token 的 SHA-256（32 字节），明文绝不落库。
        // 长度在 SQL 里算 —— BYTEA 取回 PHP 侧是一个 stream 资源，量它的长度
        // 要先 stream_get_contents，那既啰嗦又容易写成「量了句柄」。
        self::assertSame(
            32,
            (int) $this->connection->fetchOne(
                'SELECT octet_length(refresh_token_hash) FROM sessions WHERE user_id = ?',
                [$body['user']['id']],
            ),
        );
    }

    // ========================================================================
    // 拒绝
    // ========================================================================

    /**
     * ⚠️ 五种拒绝形状在客户端看来必须**逐字相同**。细分等于把判据送给攻击者：
     * 「试太多次了」一旦可辨认，就能免费探测某条挑战被别人试过几次。
     */
    #[DataProvider('rejections')]
    public function testEveryRejectionIsTheSame401(string $shape): void
    {
        $response = $this->reject($shape);

        $problem = self::assertIsProblemDetails($response, ErrorCode::TokenInvalid);

        self::assertSame('The verification code is not valid.', $problem['detail']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejections(): iterable
    {
        yield 'wrong code' => ['wrong-code'];
        yield 'unknown challenge' => ['missing'];
        yield 'expired challenge' => ['expired'];
    }

    /**
     * §7.1：`attempts > 5` → 401，且**整条挑战作废** —— 第 6 次即使码是对的也不行。
     * 这是 §7.5「challenge_id 5 次总计」的落地，它在 `otp_challenges.attempts`
     * 这一列上，不在 Redis 里（rate_limiter.yaml 的页脚解释了为什么）。
     */
    public function testTheSixthAttemptFailsEvenWithTheRightCode(): void
    {
        $challenge = $this->seedChallenge(self::CODE);
        $ip = self::uniqueIp();

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(
                Response::HTTP_UNAUTHORIZED,
                $this->send(self::body($challenge->id()->toString(), '000000'), ip: $ip)->getStatusCode(),
            );
        }

        $response = $this->send(self::body($challenge->id()->toString(), self::CODE), ip: $ip);

        self::assertIsProblemDetails($response, ErrorCode::TokenInvalid);
        self::assertSame(
            5,
            (int) $this->connection->fetchOne('SELECT attempts FROM otp_challenges WHERE id = ?', [$challenge->id()->toString()]),
        );
    }

    /**
     * 一次性令牌不得重放（{@see \App\Module\Identity\Domain\Entity\OtpChallenge::consume()}）。
     */
    public function testTheSameChallengeCannotBeVerifiedTwice(): void
    {
        $challenge = $this->seedChallenge(self::CODE);

        self::assertSame(Response::HTTP_OK, $this->send(self::body($challenge->id()->toString()))->getStatusCode());
        self::assertIsProblemDetails($this->send(self::body($challenge->id()->toString())), ErrorCode::TokenInvalid);
    }

    /**
     * `devices.id` 是客户端生成的，撞上别人的安装是客户端 bug 或攻击。
     * 静默改绑会把受害者的设备行从他的设备管理页上挪走 —— 所以是 409。
     */
    public function testRefusesADeviceIdOwnedByAnotherAccount(): void
    {
        // 先让另一个账号占住这个 device id。
        $stranger = $this->seedChallenge(self::CODE, self::uniqueEmail());

        self::assertSame(
            Response::HTTP_OK,
            $this->send(self::body($stranger->id()->toString()))->getStatusCode(),
        );

        // 换一个账号，用**同一个** device id 再登一次。
        $challenge = $this->seedChallenge(self::CODE);

        self::assertIsProblemDetails($this->send(self::body($challenge->id()->toString())), ErrorCode::IdConflict);
    }

    /**
     * 一个 401 的 problem body 也必须过契约里声明的响应。
     */
    public function testTheRejectionProblemSatisfiesTheContract(): void
    {
        self::assertResponseMatchesContract('post', self::PATH, $this->reject('wrong-code'));
    }

    // ========================================================================
    // 请求体的畸形输入
    // ========================================================================

    public function testRejectsANonJsonContentType(): void
    {
        self::assertIsProblemDetails(
            $this->send(self::body(), contentType: 'text/plain'),
            ErrorCode::UnsupportedMediaType,
        );
    }

    /**
     * @param non-empty-string $body
     */
    #[DataProvider('malformedBodies')]
    public function testRejectsAMalformedBody(string $body): void
    {
        self::assertIsProblemDetails($this->post($body), ErrorCode::MalformedRequest);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'not json' => ['{'];
        yield 'a json list' => ['[{"code":"418396"}]'];
        yield 'a bare scalar' => ['"hello"'];
        yield 'whitespace only' => ['   '];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidPayloads')]
    public function testRejectsAnInvalidPayload(array $body, string $field, string $code): void
    {
        $problem = self::assertIsProblemDetails(
            $this->post(json_encode($body, \JSON_THROW_ON_ERROR)),
            ErrorCode::ValidationFailed,
        );

        self::assertContains(
            ['field' => $field, 'code' => $code],
            array_map(
                static fn (array $error): array => ['field' => $error['field'], 'code' => $error['code']],
                $problem['errors'],
            ),
        );
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $device = ['id' => self::DEVICE_ID, 'platform' => 'android'];

        yield 'challenge_id missing' => [['code' => self::CODE, 'device' => $device], 'challenge_id', 'required'];
        yield 'challenge_id malformed' => [['challenge_id' => 'nope', 'code' => self::CODE, 'device' => $device], 'challenge_id', 'invalid_format'];
        yield 'code missing' => [['challenge_id' => self::CHALLENGE_ID, 'device' => $device], 'code', 'required'];
        // ⚠️ 数字形态会丢掉前导零：`000007` → `7`，而 §7.1 的码里有百万分之一是那个形态。
        yield 'code as a number' => [['challenge_id' => self::CHALLENGE_ID, 'code' => 418396, 'device' => $device], 'code', 'invalid_type'];
        yield 'code not six digits' => [['challenge_id' => self::CHALLENGE_ID, 'code' => '12345', 'device' => $device], 'code', 'invalid_format'];
        yield 'device missing' => [['challenge_id' => self::CHALLENGE_ID, 'code' => self::CODE], 'device', 'required'];
        yield 'device.platform unknown' => [
            ['challenge_id' => self::CHALLENGE_ID, 'code' => self::CODE, 'device' => ['id' => self::DEVICE_ID, 'platform' => 'ios']],
            'device.platform',
            'invalid_format',
        ];
        // docs/api/README.md：契约在请求方向上比实现宽，未知字段服务端主动拒。
        yield 'unknown field' => [
            ['challenge_id' => self::CHALLENGE_ID, 'code' => self::CODE, 'device' => $device, 'refresh_token' => 'x'],
            'refresh_token',
            'unknown_field',
        ];
    }

    // ========================================================================
    // 横切：一行代码都没写，全部来自 T-004 的监听器链
    // ========================================================================

    /**
     * 验的是**本端点确实落在 `/v1/` 面内**，也就是四个监听器都会跑。
     * 路由少写一个 `/v1` 前缀的话，这条会从 400 变成 200。
     */
    public function testRequiresTheXClientHeader(): void
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: self::body(),
        );

        $problem = self::assertIsProblemDetails($this->client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('X-Client', $problem['errors'][0]['field']);
    }

    public function testEchoesTheRequestId(): void
    {
        $requestId = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

        $this->seedChallenge(self::CODE);

        $response = $this->send(self::body(), headers: ['HTTP_X_REQUEST_ID' => $requestId]);

        self::assertSame($requestId, $response->headers->get('X-Request-Id'));
    }

    /**
     * ⚠️ 幂等重放拿到**同一对令牌**，这是对的。
     *
     * 没有它的话，一次网络抖动导致的重试会建第二条 session、第二个 refresh token，
     * 而客户端只保存最后拿到的那个 —— 前一条就成了谁也管不着、要活满 90 天的孤儿，
     * 设备管理页上还会多一行来路不明的记录。
     */
    public function testReplaysTheSameTokenPairForTheSameIdempotencyKey(): void
    {
        $challenge = $this->seedChallenge(self::CODE);

        $sameOrigin = [
            // key 必须每次跑都是新的：幂等记录在 Redis 里存 24 小时，回滚不掉。
            'HTTP_IDEMPOTENCY_KEY' => self::uniqueIdempotencyKey(),
        ];
        $body = self::body($challenge->id()->toString());
        // 两次请求必须来自同一个 IP —— 认证落地之前 IdempotencyMiddleware
        // 回落到 IP 作用域（AnonymousIdempotencyScopeResolver 返回 null）。
        $ip = self::uniqueIp();

        $first = self::decode($this->send($body, headers: $sameOrigin, ip: $ip));
        $second = $this->send($body, headers: $sameOrigin, ip: $ip);

        self::assertSame(Response::HTTP_OK, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame('true', $second->headers->get('Idempotency-Replayed'));
        self::assertSame($first['refresh_token'], self::decode($second)['refresh_token']);
        // 重放短路在监听器里，控制器没跑 —— 所以只有一条会话。
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT count(*) FROM sessions WHERE user_id = ?', [$first['user']['id']]),
        );
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    private const CHALLENGE_ID = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

    private const DEVICE_ID = '0192f3a1-b2c3-7d4e-8f01-0000000000de';

    /**
     * 种一条挑战并用正确的码验它。
     */
    private function verify(): Response
    {
        $challenge = $this->seedChallenge(self::CODE);

        return $this->send(self::body($challenge->id()->toString()));
    }

    /**
     * 造出一种拒绝形状并发出去。
     */
    private function reject(string $shape): Response
    {
        return match ($shape) {
            'missing' => $this->send(self::body(self::CHALLENGE_ID)),
            'expired' => $this->send(self::body(
                $this->seedChallenge(self::CODE, expiresAt: new \DateTimeImmutable('-1 hour'))->id()->toString(),
            )),
            default => $this->send(self::body($this->seedChallenge(self::CODE)->id()->toString(), '000000')),
        };
    }

    /**
     * @return array{challenge_id: string, code: string, device: array{id: string, platform: string, model: string, os_version: string, app_version: string}}
     */
    private static function payload(string $challengeId, string $code): array
    {
        return [
            'challenge_id' => $challengeId,
            'code' => $code,
            'device' => [
                'id' => self::DEVICE_ID,
                'platform' => 'android',
                'model' => 'Pixel 7a',
                'os_version' => '14',
                'app_version' => '1.4.0',
            ],
        ];
    }

    private static function body(string $challengeId = self::CHALLENGE_ID, string $code = self::CODE): string
    {
        return json_encode(self::payload($challengeId, $code), \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(string $body, string $contentType = 'application/json', array $headers = [], ?string $ip = null): Response
    {
        return $this->post($body, $contentType, $headers, $ip);
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, string $contentType = 'application/json', array $headers = [], ?string $ip = null): Response
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: [
                'CONTENT_TYPE' => $contentType,
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                // 每条请求换一个源 IP，否则整组用例会撞上 `otp_verify_ip` 的 60/h。
                // Redis 里的计数不在测试事务里、回滚不掉。
                'REMOTE_ADDR' => $ip ?? self::uniqueIp(),
                ...$headers,
            ],
            content: $body,
        );

        return $this->client->getResponse();
    }

    private static function uniqueIdempotencyKey(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return implode('-', [
            bin2hex(substr($bytes, 0, 4)),
            bin2hex(substr($bytes, 4, 2)),
            bin2hex(substr($bytes, 6, 2)),
            bin2hex(substr($bytes, 8, 2)),
            bin2hex(substr($bytes, 10, 6)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeSegment(string $segment): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) base64_decode(strtr($segment, '-_', '+/'), true),
            true,
            8,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
