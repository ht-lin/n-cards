<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Http\MeController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/me` 与 `PATCH /v1/me` 在**真实 HTTP + 真库**上的闭环（T-108）。
 *
 * ============================================================================
 * 本文件承载 T-107 移交过来的那半条验收标准
 * ============================================================================
 * `UsernameEndpointTest` 顶部写着：§13.4-6 的第 3 条「设定后 `POST` 与
 * `PATCH /v1/me`（含 `username` 字段）**均**返回 409」里的后半句归 T-108，
 * 因为那个端点是本卡的交付物。落点是
 * {@see testPatchRejectsAUsernameFieldAsImmutable()} 与
 * {@see testPatchWithAUsernameFieldChangesNothing()}。
 *
 * ============================================================================
 * 两个会咬人的测试环境事实（口径同 UsernameEndpointTest）
 * ============================================================================
 *  1. **每条请求换一个源 IP**。Redis 里的限流计数不在测试事务里、回滚不掉。
 *  2. 用户走**真实** `POST /v1/auth/otp/verify` 建出来 —— 那条路径建出的行
 *     `username` 恒为 NULL，正是 `GET /me` 最要紧的那种调用者。
 */
#[CoversClass(MeController::class)]
final class MeEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const PATH = '/v1/me';

    private const CODE = '773051';

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
    // GET —— onboarding 路由的依据
    // ========================================================================

    public function testReportsTheRegistrationIntermediateState(): void
    {
        $token = $this->register();

        $response = $this->send('GET', self::PATH, null, $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertResponseMatchesContract('get', self::PATH, $response);

        $user = self::decode($response)['user'];

        self::assertNull($user['username'], '§5.2 的注册中间态：username 为 null，而不是省略这个键');
        self::assertFalse($user['onboarding_complete'], '客户端正是靠这个字段路由到 username 设定页');
        self::assertSame('de', $user['locale']);

        // 键**恰好**这五个：契约的 `User` 是 required 全五项，多一个键意味着
        // 有人往响应里加了字段却没改契约（而 §8.2 的数据最小化管着这件事）。
        self::assertSame(
            ['id', 'username', 'locale', 'onboarding_complete', 'created_at'],
            array_keys($user),
        );
    }

    public function testReportsOnboardingCompleteOnceTheUsernameIsSet(): void
    {
        $token = $this->register();

        $this->assignUsername($token, 'anna_b');

        $user = self::decode($this->send('GET', self::PATH, null, $token))['user'];

        self::assertSame('anna_b', $user['username']);
        self::assertTrue($user['onboarding_complete']);
    }

    /**
     * §7.4：每个 `/v1` 响应都是用户特定的，不许任何中间层缓存它。
     */
    public function testIsNotCacheable(): void
    {
        $response = $this->send('GET', self::PATH, null, $this->register());

        // ⚠️ 断言「包含」而不是「等于」：Symfony 的 Response 会在 no-store 之外
        // 自己补一个 `private`，最终发出去的是 `no-store, private`
        // （口径同 OtpRequestEndpointTest）。
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testRequiresBearer(): void
    {
        self::assertIsProblemDetails($this->send('GET', self::PATH, null, null), ErrorCode::TokenInvalid);
    }

    // ========================================================================
    // PATCH —— 正常路径
    // ========================================================================

    public function testUpdatesTheLocale(): void
    {
        $token = $this->completeOnboarding();

        $response = $this->send('PATCH', self::PATH, json_encode(['locale' => 'en'], \JSON_THROW_ON_ERROR), $token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertResponseMatchesContract('patch', self::PATH, $response);
        self::assertSame('en', self::decode($response)['user']['locale']);

        // 真的落库了 —— 不是只在响应体里改了个字段。
        self::assertSame('en', self::decode($this->send('GET', self::PATH, null, $token))['user']['locale']);
    }

    /**
     * 重复发同一个 PATCH 是幂等的：`User::changeLocale()` 在同值时自身 no-op，
     * 所以 `users.updated_at` 不会被一串无意义的更新推着走。
     */
    public function testRepeatingTheSamePatchIsANoOp(): void
    {
        $token = $this->completeOnboarding();

        $this->send('PATCH', self::PATH, json_encode(['locale' => 'en'], \JSON_THROW_ON_ERROR), $token);
        $updatedAt = $this->updatedAtOf($token);

        $second = $this->send('PATCH', self::PATH, json_encode(['locale' => 'en'], \JSON_THROW_ON_ERROR), $token);

        self::assertSame(Response::HTTP_OK, $second->getStatusCode(), (string) $second->getContent());
        self::assertSame($updatedAt, $this->updatedAtOf($token));
    }

    // ========================================================================
    // PATCH —— username 那一条不变量（T-107 移交）
    // ========================================================================

    /**
     * §6.2 逐字：「若请求体出现 `username` 字段 → `409 username_immutable`，
     * **不静默忽略**」。
     *
     * ⚠️ 断言的是 409 而不是 `400 unknown_field` —— 两者的差别正是这条验收标准
     * 的全部内容：报 `unknown_field` 等于告诉客户端「没有这个字段」，
     * 那是「静默忽略」换了个说法。
     */
    #[DataProvider('usernameFieldShapes')]
    public function testPatchRejectsAUsernameFieldAsImmutable(mixed $username): void
    {
        $token = $this->completeOnboarding();

        $response = $this->send(
            'PATCH',
            self::PATH,
            json_encode(['username' => $username], \JSON_THROW_ON_ERROR),
            $token,
        );

        self::assertIsProblemDetails($response, ErrorCode::UsernameImmutable);
    }

    /**
     * 按**键是否存在**判定，不看值 —— `null`（想清空）与一个合法的新名字
     * 是同一件被禁止的事，而 §3.8 的不可变性里没有「清空」这个概念。
     *
     * @return iterable<string, array{mixed}>
     */
    public static function usernameFieldShapes(): iterable
    {
        yield '一个合法的新名字' => ['bob_x'];
        yield '当前的名字（「什么都没改」也不行）' => ['anna_b'];
        yield 'null（想清空）' => [null];
        yield '类型都不对' => [42];
    }

    /**
     * 409 之后 `users` 那一行**一个字段都没动** —— 包括同一个请求体里那个
     * 本来合法的 `locale`。username 的判定在最前面，整个请求是全或无的。
     */
    public function testPatchWithAUsernameFieldChangesNothing(): void
    {
        $token = $this->completeOnboarding();

        $response = $this->send(
            'PATCH',
            self::PATH,
            json_encode(['username' => 'bob_x', 'locale' => 'en'], \JSON_THROW_ON_ERROR),
            $token,
        );

        self::assertIsProblemDetails($response, ErrorCode::UsernameImmutable);

        $user = self::decode($this->send('GET', self::PATH, null, $token))['user'];

        self::assertSame('anna_b', $user['username']);
        self::assertSame('de', $user['locale'], 'username 的 409 必须在改 locale 之前发生');
    }

    // ========================================================================
    // PATCH —— 请求体形状
    // ========================================================================

    public function testRejectsAnUnknownField(): void
    {
        $response = $this->send(
            'PATCH',
            self::PATH,
            // `display_name` 是 v1.1 C10 移除的那个字段 —— 一个还在发它的
            // 客户端必须听到「这里没有这个东西」，而不是被静默忽略。
            json_encode(['locale' => 'de', 'display_name' => 'Anna'], \JSON_THROW_ON_ERROR),
            $this->completeOnboarding(),
        );

        $body = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertSame('display_name', $body['errors'][0]['field']);
        self::assertSame('unknown_field', $body['errors'][0]['code']);
    }

    public function testRejectsALocaleOutsideTheEnum(): void
    {
        $response = $this->send(
            'PATCH',
            self::PATH,
            json_encode(['locale' => 'fr'], \JSON_THROW_ON_ERROR),
            $this->completeOnboarding(),
        );

        $body = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        // 400 而不是 422：契约把 locale 写成 enum，发别的值是客户端 bug。
        self::assertSame('locale', $body['errors'][0]['field']);
        self::assertSame('invalid_format', $body['errors'][0]['code']);
    }

    /**
     * 空对象是 `400 malformed_request`，不是 200 no-op。
     *
     * ⚠️ 这个码来自 {@see \App\Shared\Http\Controller\AbstractApiController::decodeBody()}
     * ——它用 `array_is_list()` 判顶层，而 `array_is_list([])` 恒为 true，
     * 于是 `{}` 在解析层就被当成了「不是 JSON 对象」。本用例**钉住**这个行为：
     * 为 PATCH 单独放宽 `decodeBody()` 会动到每一个端点，而一个什么都不改的
     * PATCH 本来就只可能是客户端 bug。契约的 `MeUpdate` 因此写 `required: [locale]`。
     */
    public function testRejectsAnEmptyObject(): void
    {
        $response = $this->send('PATCH', self::PATH, '{}', $this->completeOnboarding());

        self::assertIsProblemDetails($response, ErrorCode::MalformedRequest);
    }

    // ========================================================================
    // 拦截器在这两个端点上的分工
    // ========================================================================

    /**
     * `PATCH /v1/me` **不在** `OnboardingListener::EXEMPT_ROUTES` 里 ——
     * §5.2 与 §6.2 的豁免清单里只有 `GET /me`。
     *
     * 与 {@see OnboardingCoverageTest} 不重复：那边遍历全部路由证明「默认被拦」，
     * 这边钉的是**这一对孪生路由的分工**（同一个路径、同一个控制器，
     * 一个豁免一个不豁免）—— 那正是最容易被一句「/v1/me 一律放行」抹掉的地方。
     */
    public function testPatchIsNotExemptFromTheOnboardingGate(): void
    {
        $token = $this->register();

        $response = $this->send('PATCH', self::PATH, json_encode(['locale' => 'en'], \JSON_THROW_ON_ERROR), $token);

        self::assertIsProblemDetails($response, ErrorCode::UsernameRequired);
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * 走**真实**的 `POST /v1/auth/otp/verify` 注册，返回 access token。
     * 建出的行 `username` 恒为 NULL（§5.2 的注册中间态）。
     */
    private function register(): string
    {
        $challenge = $this->seedChallenge(self::CODE);

        $response = $this->send('POST', '/v1/auth/otp/verify', json_encode([
            'challenge_id' => $challenge->id()->toString(),
            'code' => self::CODE,
            'device' => [
                'id' => self::uuid(),
                'platform' => 'android',
                'model' => 'Pixel 7a',
                'os_version' => '14',
                'app_version' => '1.4.0',
            ],
        ], \JSON_THROW_ON_ERROR), null);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        return (string) self::decode($response)['access_token'];
    }

    /** 注册 + 设 `anna_b`，也就是 `PATCH` 唯一的合法调用者。 */
    private function completeOnboarding(): string
    {
        $token = $this->register();

        $this->assignUsername($token, 'anna_b');

        return $token;
    }

    private function assignUsername(string $token, string $username): void
    {
        $response = $this->send(
            'POST',
            '/v1/me/username',
            json_encode(['username' => $username], \JSON_THROW_ON_ERROR),
            $token,
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * 直接读库 —— 响应体里没有 `updated_at`（契约的 `User` 只有五个字段），
     * 而「同值 PATCH 不推 updated_at」恰恰只能在库里看到。
     */
    private function updatedAtOf(string $token): string
    {
        $id = self::decode($this->send('GET', self::PATH, null, $token))['user']['id'];

        return (string) $this->connection->fetchOne('SELECT updated_at FROM users WHERE id = ?', [$id]);
    }

    private function send(string $method, string $path, ?string $body, ?string $accessToken): Response
    {
        $server = [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            // ⚠️ 每条请求一个新 IP —— 见类注释。
            'REMOTE_ADDR' => self::uniqueIp(),
        ];

        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$accessToken;
        }

        $this->client->request($method, $path, server: $server, content: $body ?? '');

        return $this->client->getResponse();
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
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
