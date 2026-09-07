<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Http\UsernameController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/me/username` 在**真实 HTTP + 真库**上的闭环（T-107）。
 *
 * ============================================================================
 * 本文件承载 §13.4-6「username 不变性测试」
 * ============================================================================
 * 那条验收标准逐字要求的三件事，各有一条用例：
 *
 *  1. {@see testTreatsPaddedAndUppercasedInputAsTheSameName()}
 *     「`Anna_B ` 与 `anna_b` 视为同一个，且第二次返回 `username_taken`」
 *  2. {@see testRejectsAReservedWord()}
 *     「保留词被拒」
 *  3. {@see testASecondAssignmentIsRejectedAsImmutable()}
 *     「设定后 `POST` 返回 409，**不静默忽略**」
 *
 * ⚠️ 第 3 条在规格里还有后半句「`PATCH /v1/me`（含 `username` 字段）**也**返回 409」。
 * 那个端点是 **T-108** 的交付物，本卡一行都没有它 —— 所以后半句随它一起移交。
 * 强制点已经就位（`User::assignUsername()` 抛 `username_immutable`），
 * T-108 接 PATCH 时复用同一条不变量即可，不需要新写判断。
 *
 * 三条都必须跑在真库上：单测里那三条走的是 InMemory 仓储，证明不了
 * `uq_users_username` 真的在 Postgres 上生效，也证明不了归一化后的值
 * 真的过得了 `chk_users_username_format` 那条 CHECK。
 *
 * ============================================================================
 * ⚠️ 两个会咬人的测试环境事实（口径同 SessionLifecycleTest）
 * ============================================================================
 *  1. **每条请求换一个源 IP**。Redis 里的限流计数**不在**测试事务里、回滚不掉，
 *     而 `write_endpoints` 是 300/min。固定 IP 的话症状是「单跑绿、全跑红」。
 *  2. 用户是走**真实** `POST /v1/auth/otp/verify` 建出来的 —— 那条路径建出的行
 *     `username` 恒为 NULL（§5.2 的注册中间态），正是本端点唯一的合法调用者。
 */
#[CoversClass(UsernameController::class)]
final class UsernameEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const PATH = '/v1/me/username';

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
    // 正常路径
    // ========================================================================

    public function testAssignsTheUsernameAndReportsOnboardingComplete(): void
    {
        $token = $this->login();

        $response = $this->assign($token, 'anna_b');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = self::decode($response);

        self::assertSame(['user'], array_keys($body));

        /** @var array<string, mixed> $user */
        $user = $body['user'];

        self::assertSame(['id', 'username', 'locale', 'onboarding_complete', 'created_at'], array_keys($user));
        self::assertSame('anna_b', $user['username']);
        self::assertTrue($user['onboarding_complete']);
        self::assertSame('de', $user['locale']);
    }

    public function testTheResponseSatisfiesTheOpenApiContract(): void
    {
        $token = $this->login();

        $response = $this->assign($token, 'anna_b');

        // 传**真实**请求路径（含 /v1）—— PathFinder 自己把它对到契约里的 /me/username。
        self::assertResponseMatchesContract('POST', self::PATH, $response);
    }

    /**
     * 设定之后，库里存的是**归一化后的**值 —— 也就是 `chk_users_username_format`
     * 那条 CHECK 放行的形态。写进去的若是原样输入，这条 INSERT 会当场失败。
     */
    public function testPersistsTheNormalisedValue(): void
    {
        $token = $this->login();

        $this->assign($token, '  Anna_B  ');

        self::assertSame(
            'anna_b',
            $this->connection->fetchOne('SELECT username FROM users WHERE username IS NOT NULL'),
        );
    }

    // ========================================================================
    // §13.4-6 ①：归一化等价 + username_taken
    // ========================================================================

    /**
     * 验收标准逐字写的那条：`Anna_B ` 与 `anna_b` 是同一个名字，
     * 于是**第二个人**注册 `anna_b` 时拿到 `409 username_taken`。
     *
     * ⚠️ 这是 §3.8 有意保留的枚举面 —— 注册时必须告诉用户名字被占了，
     * 否则功能不可用。不要照搬邮箱那套「恒定 202」。
     */
    public function testTreatsPaddedAndUppercasedInputAsTheSameName(): void
    {
        $anna = $this->login();
        self::assertSame(Response::HTTP_OK, $this->assign($anna, 'Anna_B ')->getStatusCode());

        $bea = $this->login();
        $response = $this->assign($bea, 'anna_b');

        self::assertIsProblemDetails($response, ErrorCode::UsernameTaken);
    }

    /**
     * 反过来也成立：先占小写、再拿大写来抢。
     */
    public function testTreatsUppercasedInputAsTakenToo(): void
    {
        $anna = $this->login();
        self::assertSame(Response::HTTP_OK, $this->assign($anna, 'anna_b')->getStatusCode());

        $bea = $this->login();

        self::assertIsProblemDetails($this->assign($bea, 'ANNA_B'), ErrorCode::UsernameTaken);
    }

    // ========================================================================
    // §13.4-6 ②：保留词
    // ========================================================================

    /**
     * @param string $word `config/packages/ncards_username.yaml` 里的一条
     */
    #[DataProvider('reservedWords')]
    public function testRejectsAReservedWord(string $word): void
    {
        $token = $this->login();

        self::assertIsProblemDetails($this->assign($token, $word), ErrorCode::UsernameInvalid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedWords(): iterable
    {
        // 全表在 UsernameRulesTest 里逐条验；这里挑三个走真实 HTTP，
        // 一个通用词、一个德语法务页名、一个品牌名。
        yield 'admin' => ['admin'];
        yield 'datenschutz' => ['datenschutz'];
        yield 'ncards（品牌冒充）' => ['NCARDS'];
    }

    #[DataProvider('invalidUsernames')]
    public function testRejectsAnInvalidUsername(string $raw): void
    {
        $token = $this->login();

        self::assertIsProblemDetails($this->assign($token, $raw), ErrorCode::UsernameInvalid);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 21)];
        yield 'hyphen' => ['anna-b'];
        yield 'umlaut' => ['anna_müller'];
        yield 'email address' => ['anna@example.de'];
    }

    // ========================================================================
    // §13.4-6 ③：不可变
    // ========================================================================

    /**
     * ⚠️ 第二次**必须** 409，不能静默成功 —— 静默忽略会让客户端以为改成了。
     */
    public function testASecondAssignmentIsRejectedAsImmutable(): void
    {
        $token = $this->login();
        self::assertSame(Response::HTTP_OK, $this->assign($token, 'anna_b')->getStatusCode());

        $response = $this->assign($token, 'anna_c');

        self::assertIsProblemDetails($response, ErrorCode::UsernameImmutable);

        // 而且旧值原封不动。
        self::assertSame(
            'anna_b',
            $this->connection->fetchOne('SELECT username FROM users WHERE username IS NOT NULL'),
        );
    }

    /**
     * 连「设成同一个值」也是 409。放行的话客户端就有了一个可重复调用成功的端点，
     * 而 §7.5 的「10 次总计」会因此失去意义。
     */
    public function testEvenReassigningTheSameValueIsRejected(): void
    {
        $token = $this->login();
        $this->assign($token, 'anna_b');

        self::assertIsProblemDetails($this->assign($token, 'anna_b'), ErrorCode::UsernameImmutable);
    }

    /**
     * ⚠️ 不可变性是靠「**没有** `PATCH` / `PUT` 对应端点」保证的（§3.8、§6.2）。
     *
     * 这条用例把那句话变成一条会红的断言：路由表里除了 `POST`，
     * 这个路径上不该有任何别的方法。加一个 `PATCH /v1/me/username` 会在这里红，
     * 而不是在某次 code review 里靠人想起来。
     */
    #[DataProvider('writeMethodsThatMustNotExist')]
    public function testNoOtherWriteMethodExistsOnThisPath(string $method): void
    {
        $token = $this->login();

        $response = $this->send($method, self::PATH, json_encode(['username' => 'anna_c'], \JSON_THROW_ON_ERROR), $token);

        self::assertIsProblemDetails($response, ErrorCode::MethodNotAllowed);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writeMethodsThatMustNotExist(): iterable
    {
        yield 'PATCH' => ['PATCH'];
        yield 'PUT' => ['PUT'];
        yield 'DELETE' => ['DELETE'];
    }

    // ========================================================================
    // §7.5：10 次总计
    // ========================================================================

    /**
     * 预算耗尽 → **422 `limit_exceeded`**，不是 429。
     *
     * 429 在契约里强制带 `Retry-After`，而这个计数永不恢复 ——
     * 只能编一个假秒数让客户端永远退避重试。论证见 ADR-0017。
     *
     * ⚠️ 只有 `username_taken` 消耗次数，所以这里用「反复去抢一个已被占用的
     * 名字」把预算打满。若哪天 `username_invalid` 也开始计数，本条会在
     * 第 11 次拿到 `limit_exceeded` 之前就先红（次数对不上）。
     */
    public function testExhaustingTheAttemptBudgetYieldsLimitExceeded(): void
    {
        $squatter = $this->login();
        self::assertSame(Response::HTTP_OK, $this->assign($squatter, 'taken_name')->getStatusCode());

        $token = $this->login();

        for ($i = 1; $i <= 10; ++$i) {
            self::assertIsProblemDetails($this->assign($token, 'taken_name'), ErrorCode::UsernameTaken);
        }

        // 第 11 次是终局，而且换一个**没被占用**的名字也一样。
        self::assertIsProblemDetails($this->assign($token, 'anna_b'), ErrorCode::LimitExceeded);
        self::assertSame(422, ErrorCode::LimitExceeded->httpStatus());

        self::assertSame(
            10,
            (int) $this->connection->fetchOne('SELECT MAX(username_attempts) FROM users'),
        );
    }

    /**
     * ⚠️ 本卡最值钱的一条：**格式错误不消耗预算。**.
     *
     * 消耗的话，客户端本地预校验（T-010 / T-151）的一个 bug 就能在 10 次之内
     * 把用户**永久**钉死在 onboarding —— username 不可变、T-108 的拦截器
     * 又会挡住注销路径，那个账号再也没有出路。
     */
    public function testInvalidAttemptsDoNotConsumeTheBudget(): void
    {
        $token = $this->login();

        for ($i = 0; $i < 12; ++$i) {
            self::assertIsProblemDetails($this->assign($token, 'anna-b'), ErrorCode::UsernameInvalid);
        }

        self::assertSame(Response::HTTP_OK, $this->assign($token, 'anna_b')->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT MAX(username_attempts) FROM users'));
    }

    // ========================================================================
    // 横切
    // ========================================================================

    public function testRequiresBearer(): void
    {
        $response = $this->assign(null, 'anna_b');

        self::assertIsProblemDetails($response, ErrorCode::TokenInvalid);
    }

    public function testRequiresTheXClientHeader(): void
    {
        $token = $this->login();

        $this->client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => self::uniqueIp(),
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: json_encode(['username' => 'anna_b'], \JSON_THROW_ON_ERROR));

        $problem = self::assertIsProblemDetails($this->client->getResponse(), ErrorCode::ValidationFailed);

        self::assertSame('X-Client', $problem['errors'][0]['field']);
    }

    public function testRejectsANonJsonContentType(): void
    {
        $token = $this->login();

        $this->client->request('POST', self::PATH, server: [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            'REMOTE_ADDR' => self::uniqueIp(),
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], content: 'username=anna_b');

        self::assertIsProblemDetails($this->client->getResponse(), ErrorCode::UnsupportedMediaType);
    }

    /**
     * 请求体的形状问题是 **400 `validation_failed`**（客户端 bug），
     * 与「名字不行」的 422 `username_invalid`（用户输入问题）是两回事 ——
     * Android 侧要按这个区别决定显示哪种 UI。
     *
     * @param string $body 原样的 JSON 文本 —— **不是** PHP 数组。
     *                     `json_encode([])` 会编出 `[]`（一个 JSON 数组），
     *                     那在 `decodeBody()` 那一层就被拒成 `malformed_request` 了，
     *                     根本走不到本卡的校验
     */
    #[DataProvider('malformedPayloads')]
    public function testRejectsAMalformedPayload(string $body, string $expectedField, string $expectedCode): void
    {
        $token = $this->login();

        $response = $this->send('POST', self::PATH, $body, $token);

        $problem = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        /** @var list<array{field: string, code: string}> $errors */
        $errors = $problem['errors'] ?? [];

        self::assertContains(
            ['field' => $expectedField, 'code' => $expectedCode],
            array_map(
                static fn (array $e): array => ['field' => $e['field'], 'code' => $e['code']],
                $errors,
            ),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedPayloads(): iterable
    {
        // ⚠️ 空对象 `{}` **走不到**本卡的校验：AbstractApiController::decodeBody()
        // 用 array_is_list() 判「顶层是不是 JSON 对象」，而 json_decode('{}', true)
        // 给的是一个空 PHP 数组，array_is_list([]) 恒为 true —— 于是它在那一层
        // 就成了 malformed_request。所以「省略字段」这个场景要用一个**非空**的
        // 对象来构造，见下面那条。两者都是 400，客户端拿到的分类不同而已；
        // 那是 T-004 的既有行为，不在本卡范围内。
        yield 'explicit null' => ['{"username":null}', 'username', 'required'];
        // 省略与显式 null 是同一个 code —— 见 UsernamePayload 的注释：
        // username 不可变，「清空」这件事不存在，null 表达不了任何东西。
        yield 'field omitted from a non-empty object' => ['{"locale":"de"}', 'username', 'required'];
        yield 'wrong type' => ['{"username":42}', 'username', 'invalid_type'];
        yield 'wrong type, array' => ['{"username":["anna_b"]}', 'username', 'invalid_type'];
        // v1.1 移除了 display_name（C10）。客户端还在发它 = 用的是旧契约，
        // 静默忽略会让那个 bug 一直活着。
        yield 'unknown field' => ['{"username":"anna_b","display_name":"Anna"}', 'display_name', 'unknown_field'];
    }

    /**
     * 空对象在 `decodeBody()` 那一层就被拒 —— 见 {@see malformedPayloads()} 的注释。
     * 钉住它是为了让「哪个 400 从哪一层出来」有据可查。
     */
    public function testRejectsAnEmptyJsonObjectAsMalformed(): void
    {
        $token = $this->login();

        self::assertIsProblemDetails($this->send('POST', self::PATH, '{}', $token), ErrorCode::MalformedRequest);
    }

    /**
     * ⚠️ `detail` 与 `instance` 里都**不能**出现候选 username（§3.8-C4）：
     * 两者都会进日志与 Sentry，而 username 是可检索的公开伪名 ——
     * 把它顺手记进每一条错误里就是枚举者要的那份目录。
     */
    public function testTheProblemBodyNeverEchoesTheCandidate(): void
    {
        $token = $this->login();

        $problem = self::assertIsProblemDetails($this->assign($token, 'anna-b-müller'), ErrorCode::UsernameInvalid);

        self::assertStringNotContainsString('anna-b-müller', json_encode($problem, \JSON_THROW_ON_ERROR));
    }

    // ========================================================================
    // helpers
    // ========================================================================

    private function assign(?string $token, string $username): Response
    {
        return $this->send('POST', self::PATH, json_encode(['username' => $username], \JSON_THROW_ON_ERROR), $token);
    }

    /**
     * 走**真实**的 `POST /v1/auth/otp/verify` 注册一个用户，返回它的 access token。
     * 那条路径建出的行 `username` 恒为 NULL —— 正是本端点唯一的合法调用者。
     */
    private function login(): string
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

        $body = self::decode($response);

        self::assertNull($body['user']['username'], '刚注册的用户必须处于 §5.2 的注册中间态');

        return (string) $body['access_token'];
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
        return \sprintf(
            '%08x-%04x-7%03x-%04x-%012x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFF),
            random_int(0x8000, 0xBFFF),
            random_int(0, 0xFFFFFFFFFFFF),
        );
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
