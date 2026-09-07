<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Application\Magic\ConsumeMagicLinkService;
use App\Module\Identity\Application\Magic\MagicLinkConsumptionPayload;
use App\Module\Identity\Http\MagicConsumeController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/auth/magic/consume` 在**真实 HTTP** 上的形状（T-106）。
 *
 * ============================================================================
 * 任务卡的验收标准，以及它在这里为什么只剩一半
 * ============================================================================
 * 任务卡写的是两条：
 *
 *   ① 集成测试断言 `GET` 与 `HEAD` 不改变 `consumed_at`；
 *   ② `POST` 消费一次后重复 POST 返回 401。
 *
 * ② 就在下面（{@see testASecondPostWithTheSameTokenIsRejected()}）。
 *
 * ① 在这里**写不出来**，因为落地页根本不在后端：它是一份由 Caddy 直接吐出的
 * 静态 HTML（`infra/caddy/site/l/magic/index.html`，ADR-0016）。
 * 「GET 不消费」因此不是一条需要测的行为，而是一条结构事实 ——
 * 后端在 `/l/` 下没有任何路由可以被 GET。
 *
 * 替代的三个强制点：
 *
 *   - {@see RouteInventoryTest::testTheBackendServesNothingUnderTheAppLinksPath()}
 *     断言后端不注册任何 `/l/` 路由（本条最接近①的原意）；
 *   - `scripts/ci/smoke-app-site.sh` 在真栈上 curl `GET` / `HEAD` 落地页；
 *   - 下面 {@see testOnlyPostIsRouted()} 断言本端点自己不接 GET/HEAD。
 *
 * ⚠️ 令牌是用例自己种进去的（{@see RequiresOtpStack::seedChallenge()}）——
 * 真令牌只在邮件里，而 test 环境的信躺在加密过的队列里。理由见那个方法的注释。
 */
#[CoversClass(MagicConsumeController::class)]
#[CoversClass(ConsumeMagicLinkService::class)]
#[CoversClass(MagicLinkConsumptionPayload::class)]
final class MagicConsumeEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;

    use RequiresOtpStack;

    private const PATH = '/v1/auth/magic/consume';

    /** 与生产同形：32 字节 base64url = 43 字符。 */
    private const TOKEN = 'bF0pR4hN8mV2wY6cL5dJ0aG3eU7iO1kS49tK3zQ1sX7';

    private const DEVICE_ID = '0192f3a1-b2c3-7d4e-8f01-0000000000de';

    /** 与本次登录无关的另一条挑战上的码，用来验「两条入口互斥」。 */
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
        $response = $this->consume();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/json; charset=utf-8', $response->headers->get('Content-Type'));
        // §7.4 / §7.3：令牌绝不许被任何中间层缓存。
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = self::decode($response);

        self::assertSame(['access_token', 'expires_in', 'refresh_token', 'user'], array_keys($body));
        // §7.1：15 分钟 —— 与 otp/verify 签出来的是同一种令牌。
        self::assertSame(900, $body['expires_in']);
        self::assertSame(
            ['id', 'username', 'locale', 'onboarding_complete', 'created_at'],
            array_keys($body['user']),
        );
    }

    /**
     * §13.1 契约优先在响应方向上的强制点。契约里本端点的 200 与 `otp/verify`
     * 引用的是**同一个** `Session` schema。
     */
    public function testTheResponseSatisfiesTheOpenApiContract(): void
    {
        self::assertResponseMatchesContract('post', self::PATH, $this->consume());
    }

    /**
     * §5.2 / §6.3.1 / ADR-0014：**首次消费即注册**。
     *
     * ⚠️ 这条与 `otp/verify` 上的同名用例是一对：Magic Link 是第二条注册入口，
     * 而不是「只有老用户能用的快捷方式」。写成后者的话，一个从没登录过的人
     * 点信里的链接会拿到 401，而他手上的码明明是有效的。
     */
    public function testAFirstConsumptionRegistersAUserWithoutAUsername(): void
    {
        $body = self::decode($this->consume());

        self::assertNull($body['user']['username'], '刚注册的用户还没有 username（§5.2）。');
        self::assertFalse($body['user']['onboarding_complete']);

        $row = $this->connection->fetchAssociative(
            'SELECT username FROM users WHERE id = ?',
            [$body['user']['id']],
        );

        self::assertIsArray($row);
        self::assertNull($row['username']);
    }

    /**
     * 消费成功后 `consumed_at` 落盘 —— 这是「一次性」的全部机制。
     */
    public function testASuccessfulConsumptionStampsConsumedAt(): void
    {
        $challenge = $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        self::assertNull($this->consumedAt($challenge->id()->toString()));

        $this->post(self::body());

        self::assertNotNull($this->consumedAt($challenge->id()->toString()));
    }

    // ========================================================================
    // 拒绝 —— 任务卡验收标准的后半条，以及形状不可区分
    // ========================================================================

    /**
     * ⚠️ **任务卡的验收标准**：「`POST` 消费一次后重复 POST 返回 401」。
     */
    public function testASecondPostWithTheSameTokenIsRejected(): void
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        self::assertSame(Response::HTTP_OK, $this->post(self::body())->getStatusCode());

        $second = $this->post(self::body());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $second->getStatusCode());
        self::assertIsProblemDetails($second, ErrorCode::TokenInvalid);
    }

    /**
     * 码与链接挂在**同一行**挑战上，共用一个 `consumed_at`：谁先来谁赢。
     *
     * ⚠️ 这条不是锦上添花。若两者各自独立地可消费，一封信就等于**两次**登录机会 ——
     * 而 §7.1 的「单次登录只允许一个活跃 challenge」以及 `attempts` 的 5 次预算
     * 都是按一次算的。
     */
    public function testConsumingTheLinkAlsoBurnsTheSixDigitCode(): void
    {
        $challenge = $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        self::assertSame(Response::HTTP_OK, $this->post(self::body())->getStatusCode());

        $verify = $this->postTo('/v1/auth/otp/verify', json_encode([
            'challenge_id' => $challenge->id()->toString(),
            'code' => self::CODE,
            'device' => self::device(),
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $verify->getStatusCode());
    }

    /** 反向：先用码登录，链接随即失效。 */
    public function testConsumingTheCodeAlsoBurnsTheLink(): void
    {
        $challenge = $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        $verify = $this->postTo('/v1/auth/otp/verify', json_encode([
            'challenge_id' => $challenge->id()->toString(),
            'code' => self::CODE,
            'device' => self::device(),
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_OK, $verify->getStatusCode(), (string) $verify->getContent());
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->post(self::body())->getStatusCode());
    }

    /**
     * 三种拒绝返回**逐字相同**的 401。
     *
     * ⚠️ 「已经用过了」一旦可辨认，就等于告诉一个持有窃得令牌的人
     * 「你来晚了，但这个链接确实是真的」—— 那是一条免费的确认信道。
     *
     * @param 'unknown'|'expired'|'consumed' $shape
     */
    #[DataProvider('rejectionShapes')]
    public function testEveryRejectionLooksExactlyTheSame(string $shape): void
    {
        $response = $this->reject($shape);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

        $problem = self::assertIsProblemDetails($response, ErrorCode::TokenInvalid);

        self::assertSame('The sign-in link is not valid.', $problem['detail']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectionShapes(): iterable
    {
        yield 'unknown' => ['unknown'];
        yield 'expired' => ['expired'];
        yield 'consumed' => ['consumed'];
    }

    /**
     * 一个**过期**的挑战不会被消费掉 —— 拒绝路径不写 `consumed_at`。
     *
     * 写了的话，「这个令牌过期了」与「这个令牌不存在」在库层就可辨认了。
     */
    public function testAnExpiredTokenIsNotStampedAsConsumed(): void
    {
        $challenge = $this->seedChallenge(
            self::CODE,
            expiresAt: new \DateTimeImmutable('-1 hour'),
            magicToken: self::TOKEN,
        );

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->post(self::body())->getStatusCode());
        self::assertNull($this->consumedAt($challenge->id()->toString()));
    }

    /**
     * 设备 id 属于别人 → 409，不是静默改绑。与 `otp/verify` 同一条规则
     * （两者共用 {@see \App\Module\Identity\Application\Session\SessionIssuer}）。
     */
    public function testADeviceIdOwnedBySomebodyElseIsRejected(): void
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);
        $this->post(self::body());

        // 另一个人、另一条挑战，但复用同一个安装 id。
        $this->seedChallenge(self::CODE, magicToken: self::OTHER_TOKEN);

        $response = $this->post(self::body(self::OTHER_TOKEN));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::IdConflict);
    }

    // ========================================================================
    // 请求体校验
    // ========================================================================

    /**
     * ⚠️ 令牌**绝不**回显进 detail —— 它一步就能换到一个会话。
     */
    public function testAnInvalidTokenIsNeverEchoedBack(): void
    {
        $secret = str_repeat('S', 600);

        $response = $this->post(json_encode(
            ['token' => $secret, 'device' => self::device()],
            \JSON_THROW_ON_ERROR,
        ));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertStringNotContainsString($secret, (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testRejectsInvalidBodies(array $body, string $field): void
    {
        $response = $this->post(json_encode($body, \JSON_THROW_ON_ERROR));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $problem = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertSame($field, $problem['errors'][0]['field']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'token missing' => [['device' => self::device()], 'token'];
        yield 'token too short' => [['token' => 'abc', 'device' => self::device()], 'token'];
        yield 'token not a string' => [['token' => 42, 'device' => self::device()], 'token'];
        yield 'device missing' => [['token' => self::TOKEN], 'device'];
        yield 'device not an object' => [['token' => self::TOKEN, 'device' => []], 'device'];
        yield 'unknown top-level field' => [
            ['token' => self::TOKEN, 'device' => self::device(), 'challenge_id' => self::DEVICE_ID],
            'challenge_id',
        ];
        yield 'unknown device field' => [
            ['token' => self::TOKEN, 'device' => self::device() + ['nickname' => 'x']],
            'device.nickname',
        ];
    }

    // ========================================================================
    // 横切
    // ========================================================================

    /**
     * 本端点在契约里是 `security: []`，所以**不带** Bearer 也要能用 ——
     * 信里的链接是给还没登录的人的。
     *
     * `AuthenticationCoverageTest` 拿白名单与契约逐条对账，这里验的是行为。
     */
    public function testNoBearerTokenIsRequired(): void
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        self::assertSame(Response::HTTP_OK, $this->post(self::body())->getStatusCode());
    }

    /**
     * ⚠️ 只有 POST。GET / HEAD 打到本路径必须 405 ——
     * 企业邮件安全网关会自动 GET 邮件里的每个链接（§7.1）。
     *
     * 信里给的其实是落地页而不是本端点，所以这条是第二道保险：
     * 就算哪天有人「顺手」把 API 地址放进模板，GET 它也什么都不会消费。
     *
     * @param 'GET'|'HEAD' $method
     */
    #[DataProvider('unsafeMethods')]
    public function testOnlyPostIsRouted(string $method): void
    {
        $challenge = $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        $this->client->request($method, self::PATH, server: [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            'REMOTE_ADDR' => self::uniqueIp(),
        ]);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->consumedAt($challenge->id()->toString()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeMethods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    /** §3.10：`/v1` 下的一切都要带 `X-Client`。 */
    public function testRequiresTheClientHeader(): void
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        $this->client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => self::uniqueIp(),
        ], content: self::body());

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    /**
     * 幂等重放回放**同一对**令牌，而不是签出第二条会话。
     * 理由见 {@see MagicConsumeController} 的类注释。
     */
    public function testIdempotentReplayReturnsTheSameTokens(): void
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        $key = self::uniqueIdempotencyKey();
        $ip = self::uniqueIp();

        $first = $this->post(self::body(), headers: ['HTTP_IDEMPOTENCY_KEY' => $key], ip: $ip);
        $second = $this->post(self::body(), headers: ['HTTP_IDEMPOTENCY_KEY' => $key], ip: $ip);

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertSame(Response::HTTP_OK, $second->getStatusCode(), '重放必须回放那条 200，而不是撞上「已消费」的 401。');
        self::assertSame(self::decode($first), self::decode($second));
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /** 第二个令牌，用于「同一台设备撞上别人的挑战」那条。 */
    private const OTHER_TOKEN = 'zQ1sX7bF0pR4hN8mV2wY6cL5dJ0aG3eU7iO1kS49tK3';

    private function consume(): Response
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);

        return $this->post(self::body());
    }

    /**
     * @param 'unknown'|'expired'|'consumed' $shape
     */
    private function reject(string $shape): Response
    {
        return match ($shape) {
            'expired' => $this->rejectExpired(),
            'consumed' => $this->rejectConsumed(),
            // 从来没有存在过的令牌。长度合法，只是查不到。
            default => $this->post(self::body(self::OTHER_TOKEN)),
        };
    }

    private function rejectExpired(): Response
    {
        $this->seedChallenge(self::CODE, expiresAt: new \DateTimeImmutable('-1 hour'), magicToken: self::TOKEN);

        return $this->post(self::body());
    }

    private function rejectConsumed(): Response
    {
        $this->seedChallenge(self::CODE, magicToken: self::TOKEN);
        $this->post(self::body());

        return $this->post(self::body());
    }

    private function consumedAt(string $challengeId): ?string
    {
        /** @var string|false $value */
        $value = $this->connection->fetchOne(
            'SELECT consumed_at FROM otp_challenges WHERE id = ?',
            [$challengeId],
        );

        return false === $value ? null : $value;
    }

    /**
     * @return array{id: string, platform: string, model: string, os_version: string, app_version: string}
     */
    private static function device(): array
    {
        return [
            'id' => self::DEVICE_ID,
            'platform' => 'android',
            'model' => 'Pixel 7a',
            'os_version' => '14',
            'app_version' => '1.4.0',
        ];
    }

    private static function body(string $token = self::TOKEN): string
    {
        return json_encode(['token' => $token, 'device' => self::device()], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, array $headers = [], ?string $ip = null): Response
    {
        return $this->postTo(self::PATH, $body, $headers, $ip);
    }

    /**
     * @param array<string, string> $headers
     */
    private function postTo(string $path, string $body, array $headers = [], ?string $ip = null): Response
    {
        $this->client->request(
            'POST',
            $path,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                // 每条请求换一个源 IP，否则整组用例会撞上 `magic_consume_ip` 的 60/h。
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
     * 契约把 token 的长度定在 32–512。用例里那两个常量必须落在里面，
     * 否则「拒绝」可能来自校验而不是查不到 —— 那会让上面几条用例测错东西。
     */
    public function testTheFixtureTokensSatisfyTheContract(): void
    {
        foreach ([self::TOKEN, self::OTHER_TOKEN] as $token) {
            self::assertGreaterThanOrEqual(MagicLinkConsumptionPayload::TOKEN_MIN_LENGTH, \strlen($token));
            self::assertLessThanOrEqual(MagicLinkConsumptionPayload::TOKEN_MAX_LENGTH, \strlen($token));
        }
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
}
