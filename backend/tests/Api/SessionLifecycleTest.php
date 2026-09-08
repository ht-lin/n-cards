<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Module\Identity\Http\DeviceController;
use App\Module\Identity\Http\LogoutController;
use App\Module\Identity\Http\TokenRefreshController;
use App\Shared\Domain\Error\ErrorCode;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * 令牌生命周期在**真实 HTTP + 真库**上的闭环（T-105）。
 *
 * ============================================================================
 * 本文件承载本卡的两条验收标准
 * ============================================================================
 *  1. {@see testReplayingAnOldRefreshTokenRevokesTheWholeFamilyAndQueuesTheAlert()}
 *     「重放旧 refresh token → 整个会话家族被撤销且发出提醒邮件」
 *  2. {@see testRemotelyRevokingADeviceInvalidatesItsRefreshTokenImmediately()}
 *     「远程登出某设备后该设备的 refresh 立即失效」
 *
 * 两条都必须跑在真库上：单测里那两条走的是 InMemory 仓储，
 * 证明不了 `previous_token_hash` 这一列真的被写进了 Postgres、
 * 也证明不了 `revoked_reason` 的 enum 映射是对的。
 *
 * ============================================================================
 * ⚠️ 两个会咬人的测试环境事实
 * ============================================================================
 *  1. **每条请求换一个源 IP**。Redis 里的限流计数**不在**测试事务里、回滚不掉，
 *     而 `write_endpoints` 是 300/min。固定 IP 的话症状是「单跑绿、全跑红」。
 *  2. **邮件断言查 `messenger_messages` 的行数，不解析内容**。信被
 *     `EncryptedMailSerializer` 用 Vault Transit 加密过（T-102），
 *     解它会把这里绑死在那个序列化格式上。要断言模板与变量的话，
 *     单测里已经有了（`RefreshTokenServiceTest`）。
 */
#[CoversClass(TokenRefreshController::class)]
#[CoversClass(LogoutController::class)]
#[CoversClass(DeviceController::class)]
final class SessionLifecycleTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const REFRESH_PATH = '/v1/auth/token/refresh';

    private const LOGOUT_PATH = '/v1/auth/logout';

    private const DEVICES_PATH = '/v1/me/devices';

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
    // 轮换
    // ========================================================================

    public function testRefreshReturns200WithTheContractedBody(): void
    {
        $session = $this->login();

        $response = $this->refresh($session['refresh_token']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $body = self::decode($response);

        self::assertSame(['access_token', 'expires_in', 'refresh_token', 'user'], array_keys($body));
        self::assertSame(900, $body['expires_in']);

        // 轮换 = 换一对新的。返回同一个令牌说明 rotate() 根本没被调用。
        self::assertNotSame($session['refresh_token'], $body['refresh_token']);
        self::assertNotSame($session['access_token'], $body['access_token']);
    }

    /**
     * §13.1 契约优先在响应方向上的强制点。
     */
    public function testTheRefreshResponseSatisfiesTheOpenApiContract(): void
    {
        self::assertResponseMatchesContract('post', self::REFRESH_PATH, $this->refresh($this->login()['refresh_token']));
    }

    /**
     * 轮换之后**新令牌立刻可用** —— 否则客户端刷新一次就掉线。
     *
     * 这条看起来平凡，但它是「库里存的摘要」与「发回客户端的明文」
     * 用同一个算法算出来的唯一证明。
     */
    public function testTheRotatedTokenIsImmediatelyUsableAgain(): void
    {
        $first = $this->login()['refresh_token'];

        $second = self::decode($this->refresh($first))['refresh_token'];
        $third = self::decode($this->refresh($second))['refresh_token'];

        self::assertNotSame($second, $third);
    }

    // ========================================================================
    // ⚠️ 验收标准第一条
    // ========================================================================

    /**
     * 重放一个已经被轮换掉的 refresh token → 判定令牌被窃。
     *
     * 断言四件：401、会话行的 `revoked_reason = reuse_detected`、
     * 提醒信已入队、以及**刚刚合法签发的那枚新令牌也一起失效**
     * （「家族全部令牌」）。
     */
    public function testReplayingAnOldRefreshTokenRevokesTheWholeFamilyAndQueuesTheAlert(): void
    {
        $session = $this->login();
        $old = $session['refresh_token'];

        $fresh = self::decode($this->refresh($old))['refresh_token'];

        $mailBefore = $this->queuedMailCount();

        // 小偷拿着偷来的旧令牌来了。
        $replayed = $this->refresh($old);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $replayed->getStatusCode());
        self::assertIsProblemDetails($replayed, ErrorCode::TokenInvalid);

        // 库里那一行被撤销，且原因是安全事件。
        self::assertSame(
            SessionRevokedReason::ReuseDetected->value,
            $this->connection->fetchOne(
                'SELECT revoked_reason FROM sessions WHERE id = ?',
                [$session['session_id']],
            ),
        );

        // 提醒信入队（内容不解析，见类注释）。
        self::assertSame($mailBefore + 1, $this->queuedMailCount(), 'A security alert mail must be queued.');

        // ⚠️ 「家族全部令牌」：小偷还没用过的那枚新令牌现在也死了。
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->refresh($fresh)->getStatusCode());
    }

    /**
     * ⚠️ 与上一条成对：被窃检测**不能**从响应里看出来。
     *
     * 能看出来的话，攻击者就有了一个「我这枚令牌是不是刚被换掉的」的
     * 免费预言机。
     */
    public function testAReplayedTokenLooksExactlyLikeAnUnknownOne(): void
    {
        $old = $this->login()['refresh_token'];
        $this->refresh($old);

        $replayed = $this->refresh($old);
        $unknown = $this->refresh(str_repeat('z', 43));

        self::assertSame($unknown->getStatusCode(), $replayed->getStatusCode());

        self::assertSame(self::clientVisible($unknown), self::clientVisible($replayed));
    }

    // ========================================================================
    // logout
    // ========================================================================

    /**
     * ⚠️ `POST /v1/auth/logout` 是 auth 组里**唯一需要 Bearer** 的端点。
     *
     * 这条断言挡的是一个具体的实现错误：把免鉴权白名单写成路径前缀
     * `/v1/auth/` 的话，这个端点会被一起放过去，于是任何人都能撤销任何会话 ——
     * 而所有其他测试照常绿（logout 本来就返回 204）。
     */
    public function testLogoutRequiresBearerEvenThoughItIsUnderTheAuthPrefix(): void
    {
        $response = $this->post(self::LOGOUT_PATH, null, null);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::TokenInvalid);
    }

    public function testLogoutRevokesTheSessionSoRefreshStopsWorking(): void
    {
        $session = $this->login();

        $response = $this->post(self::LOGOUT_PATH, null, $session['access_token']);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        self::assertSame(
            SessionRevokedReason::Logout->value,
            $this->connection->fetchOne('SELECT revoked_reason FROM sessions WHERE id = ?', [$session['session_id']]),
        );

        // §7.1：「会话撤销后 refresh 立即失效」。
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->refresh($session['refresh_token'])->getStatusCode());
    }

    /**
     * 契约规定幂等 —— 重复登出仍然 204，客户端重试才能收敛。
     */
    public function testLogoutIsIdempotent(): void
    {
        $session = $this->login();

        $this->post(self::LOGOUT_PATH, null, $session['access_token']);
        $second = $this->post(self::LOGOUT_PATH, null, $session['access_token']);

        self::assertSame(Response::HTTP_NO_CONTENT, $second->getStatusCode());
    }

    /**
     * ⚠️ 登出**不会**让 access token 失效（§7.1 不做黑名单，15 分钟窗口可接受）。
     *
     * 这条断言的是一个刻意的**弱点**，写在 ADR-0015 的 Consequences 里。
     * 有人「顺手加个黑名单」把它变绿的反面时，应该先去读那条 ADR。
     */
    public function testTheAccessTokenKeepsWorkingAfterLogoutWithinItsWindow(): void
    {
        $session = $this->login();

        $this->post(self::LOGOUT_PATH, null, $session['access_token']);

        self::assertSame(
            Response::HTTP_OK,
            $this->get(self::DEVICES_PATH, $session['access_token'])->getStatusCode(),
            'ADR-0015: no access-token blacklist; the 15-minute window is accepted.',
        );
    }

    // ========================================================================
    // 设备管理
    // ========================================================================

    public function testTheDeviceListMarksTheCallingDeviceAndHidesThePushToken(): void
    {
        $session = $this->login();

        $response = $this->get(self::DEVICES_PATH, $session['access_token']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertSame(['devices'], array_keys($body));
        self::assertCount(1, $body['devices']);

        $device = $body['devices'][0];

        self::assertSame($session['device_id'], $device['id']);
        self::assertTrue($device['is_current']);
        // ⚠️ 令牌本身绝不回显（ROPA §8.2：它是设备行里唯一发给第三方的字段）。
        self::assertArrayNotHasKey('push_token', $device);
        self::assertArrayHasKey('push_token_updated_at', $device);
    }

    public function testTheDeviceListSatisfiesTheOpenApiContract(): void
    {
        self::assertResponseMatchesContract(
            'get',
            self::DEVICES_PATH,
            $this->get(self::DEVICES_PATH, $this->login()['access_token']),
        );
    }

    // ========================================================================
    // ⚠️ 验收标准第二条
    // ========================================================================

    /**
     * 远程登出某设备后，**该设备的 refresh 立即失效**。
     *
     * 这条挡的是最容易漏的一步：`Device::revoke()` 只标设备本身，
     * 会话的撤销是编排的事。只调前者的话这条会红，而设备列表看起来完全正常 ——
     * 被踢的设备还能安静地刷 90 天令牌。
     */
    public function testRemotelyRevokingADeviceInvalidatesItsRefreshTokenImmediately(): void
    {
        $victim = $this->login();

        // 用同一个账号在另一台设备上登录 —— 那台负责发出撤销指令。
        $admin = $this->login(email: $victim['email']);

        $response = $this->delete(self::DEVICES_PATH.'/'.$victim['device_id'], $admin['access_token']);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

        // 被踢那台的 refresh 当场失效。
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->refresh($victim['refresh_token'])->getStatusCode());

        // 而下指令那台不受影响 —— 撤销一台不该把用户从所有设备上踢下线。
        self::assertSame(Response::HTTP_OK, $this->refresh($admin['refresh_token'])->getStatusCode());

        self::assertSame(
            SessionRevokedReason::UserRevoked->value,
            $this->connection->fetchOne('SELECT revoked_reason FROM sessions WHERE id = ?', [$victim['session_id']]),
        );

        // 撤销后不再出现在列表里，且 push_token 已被清（ROPA §8.2）。
        $devices = self::decode($this->get(self::DEVICES_PATH, $admin['access_token']))['devices'];

        self::assertSame([$admin['device_id']], array_column($devices, 'id'));
        self::assertNull($this->connection->fetchOne('SELECT push_token FROM devices WHERE id = ?', [$victim['device_id']]));
    }

    /**
     * ⚠️ 别人的设备一律 **404**，不是 403 —— 403 等于确认「这个 id 存在，
     * 只是不是你的」，而设备 id 会出现在另一个用户的设备管理页上。
     */
    public function testAnotherUsersDeviceIsNotFound(): void
    {
        $mine = $this->login();
        $theirs = $this->login();

        $response = $this->delete(self::DEVICES_PATH.'/'.$theirs['device_id'], $mine['access_token']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::NotFound);

        // 而且真的什么都没动 —— 那台设备照常能刷新。
        self::assertSame(Response::HTTP_OK, $this->refresh($theirs['refresh_token'])->getStatusCode());
    }

    /**
     * 格式非法的 id 同样 404（不是 422），理由同上：这个端点对任何一个
     * 当前用户拿不到的设备都必须给出同一个答案。
     */
    public function testAMalformedDeviceIdIsAlsoJustNotFound(): void
    {
        $session = $this->login();

        $response = $this->delete(self::DEVICES_PATH.'/not-a-uuid', $session['access_token']);

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::NotFound);
    }

    public function testThePushTokenIsStoredAndReflectedInTheTimestamp(): void
    {
        $session = $this->login();

        $response = $this->put(
            self::DEVICES_PATH.'/'.$session['device_id'].'/push-token',
            json_encode(['push_token' => 'fcm-token-value'], \JSON_THROW_ON_ERROR),
            $session['access_token'],
        );

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode(), (string) $response->getContent());

        self::assertSame(
            'fcm-token-value',
            $this->connection->fetchOne('SELECT push_token FROM devices WHERE id = ?', [$session['device_id']]),
        );

        $device = self::decode($this->get(self::DEVICES_PATH, $session['access_token']))['devices'][0];

        self::assertNotNull($device['push_token_updated_at']);
        self::assertArrayNotHasKey('push_token', $device);
    }

    /**
     * `null` 是「清除」，与「省略该字段」不同 —— 后者是 422。
     */
    public function testANullPushTokenClearsItWhileOmittingTheFieldIsRejected(): void
    {
        $session = $this->login();
        $path = self::DEVICES_PATH.'/'.$session['device_id'].'/push-token';

        $this->put($path, json_encode(['push_token' => 'fcm-token-value'], \JSON_THROW_ON_ERROR), $session['access_token']);

        $cleared = $this->put($path, json_encode(['push_token' => null], \JSON_THROW_ON_ERROR), $session['access_token']);
        self::assertSame(Response::HTTP_NO_CONTENT, $cleared->getStatusCode());
        self::assertNull($this->connection->fetchOne('SELECT push_token FROM devices WHERE id = ?', [$session['device_id']]));

        // ⚠️ 都是 **400**，不是 422 —— 本仓库把 `validation_failed` 映射到 400
        // （`ErrorCode::httpStatus()`）。422 留给 `username_invalid` /
        // `limit_exceeded` / `idempotency_key_reused` 那一类「语义上不可处理」。

        // 一个真实的客户端 bug：字段名写成了驼峰。走到 PushTokenPayload，
        // 于是 `push_token` 缺失那条 required 分支在这里现形。
        $typo = $this->put($path, '{"pushToken":"fcm"}', $session['access_token']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $typo->getStatusCode());
        $problem = self::assertIsProblemDetails($typo, ErrorCode::ValidationFailed);
        self::assertSame(
            ['pushToken' => 'unknown_field', 'push_token' => 'required'],
            array_column($problem['errors'], 'code', 'field'),
        );

        // ⚠️ 而**空对象** `{}` 走的是另一条路：`json_decode('{}', true)` 给出
        // 一个空 PHP 数组，而 `array_is_list([])` 为 true —— 于是
        // `AbstractApiController::decodeBody()` 先一步判它「不是 JSON 对象」，
        // 返回 `malformed_request`。请求体压根到不了 PushTokenPayload。
        //
        // 这是 T-004 那层共享代码的既有行为（PHP 关联数组分不出 `{}` 与 `[]`），
        // 不是本端点的特例。断言它是为了让「为什么这里不是 validation_failed」
        // 有一个答案，而不是让下一个人以为哪里坏了。
        $empty = $this->put($path, '{}', $session['access_token']);
        self::assertSame(Response::HTTP_BAD_REQUEST, $empty->getStatusCode());
        self::assertIsProblemDetails($empty, ErrorCode::MalformedRequest);
    }

    // ========================================================================
    // 鉴权本身
    // ========================================================================

    /**
     * 三种坏 Bearer 都是 401，且**逐字**同一个 problem body ——
     * 可辨认的差异会给探测者一个免费的预言机。
     */
    public function testEveryFlavourOfABadBearerLooksTheSame(): void
    {
        $bodies = [];

        foreach ([null, 'not-a-jwt', 'a.b.c'] as $token) {
            $response = $this->get(self::DEVICES_PATH, $token);

            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());

            $bodies[] = self::clientVisible($response);
        }

        self::assertSame([$bodies[0]], array_unique($bodies, \SORT_REGULAR));
    }

    /**
     * ⚠️ 刷新端点**不需要** Bearer（契约里 `security: []`）——
     * 凭据是请求体里那个不透明的 refresh token 本身。
     *
     * 把它误加进需要鉴权的那一侧，症状是「access token 一过期就再也刷不回来」，
     * 也就是所有用户在 15 分钟后被永久锁在外面。
     */
    public function testRefreshWorksWithoutAnyBearerHeader(): void
    {
        $session = $this->login();

        self::assertSame(Response::HTTP_OK, $this->refresh($session['refresh_token'])->getStatusCode());
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * 走真实的 `POST /v1/auth/otp/verify` 拿一对令牌，**并完成 onboarding**。
     *
     * ============================================================================
     * ⚠️ 为什么这里要顺手设一个 username（T-108）
     * ============================================================================
     * `otp/verify` 建出来的行 `username` 恒为 NULL（§5.2 的注册中间态），
     * 而从 T-108 起 `OnboardingListener` 会把这种用户拦在除
     * `GET /me`、`POST /me/username`、`POST /auth/logout` 之外的所有 `/v1` 之外。
     * 本文件有十来处打 `/v1/me/devices*` —— 不设 username 的话它们全变
     * `403 username_required`，而那个症状会把人引向「是不是鉴权坏了」。
     *
     * 设备管理这组用例要测的是**会话与设备**的语义，onboarding 是它们的前置条件
     * 而不是被测对象。真正拿注册中间态当被测对象的是 `UsernameEndpointTest`
     * 与 `OnboardingCoverageTest`，那两个文件**不**走这条路径。
     *
     * ⚠️ 同邮箱第二次登录（造「同一用户的两台设备」）时用户已经有 username 了，
     * 再设一次会得到 `409 username_immutable` —— 所以按响应里的
     * `onboarding_complete` 判断，不能无条件设。
     *
     * @param string|null $email 复用同一个邮箱即同一个账号（用来造「同一用户的两台设备」）
     *
     * @return array{access_token: string, refresh_token: string, session_id: string, device_id: string, email: string}
     */
    private function login(?string $email = null): array
    {
        $email ??= self::uniqueEmail();
        $deviceId = self::uuid();

        $challenge = $this->seedChallenge(self::CODE, $email);

        $response = $this->post('/v1/auth/otp/verify', json_encode([
            'challenge_id' => $challenge->id()->toString(),
            'code' => self::CODE,
            'device' => [
                'id' => $deviceId,
                'platform' => 'android',
                'model' => 'Pixel 7a',
                'os_version' => '14',
                'app_version' => '1.4.0',
            ],
        ], \JSON_THROW_ON_ERROR), null);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        if (false === $body['user']['onboarding_complete']) {
            $assigned = $this->post(
                '/v1/me/username',
                json_encode(['username' => self::uniqueUsername()], \JSON_THROW_ON_ERROR),
                $body['access_token'],
            );

            self::assertSame(
                Response::HTTP_OK,
                $assigned->getStatusCode(),
                'onboarding 没走完的话，本文件里所有 /v1/me/devices 的用例都会变 403 username_required。'
                .(string) $assigned->getContent(),
            );
        }

        return [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'],
            // `sid` 是 JWT 的一个 claim，而这里只想要那个 id 用于查库 ——
            // 直接解 payload 段（不验签：本用例信任自己刚拿到的响应）。
            'session_id' => self::claim($body['access_token'], 'sid'),
            'device_id' => $deviceId,
            'email' => $email,
        ];
    }

    private function refresh(string $refreshToken): Response
    {
        return $this->post(
            self::REFRESH_PATH,
            json_encode(['refresh_token' => $refreshToken], \JSON_THROW_ON_ERROR),
            null,
        );
    }

    private function get(string $path, ?string $accessToken): Response
    {
        return $this->send('GET', $path, null, $accessToken);
    }

    private function post(string $path, ?string $body, ?string $accessToken): Response
    {
        return $this->send('POST', $path, $body, $accessToken);
    }

    private function put(string $path, string $body, ?string $accessToken): Response
    {
        return $this->send('PUT', $path, $body, $accessToken);
    }

    private function delete(string $path, ?string $accessToken): Response
    {
        return $this->send('DELETE', $path, null, $accessToken);
    }

    private function send(string $method, string $path, ?string $body, ?string $accessToken): Response
    {
        $server = [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            // ⚠️ 每条请求一个新 IP。Redis 里的限流计数不在测试事务里、回滚不掉，
            // 而 write_endpoints 是 300/min。固定 IP 的症状是「单跑绿、全跑红」。
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

    /**
     * 队列里的待发邮件数（`messenger_messages`）。
     *
     * 只数行，不解内容 —— 信被 EncryptedMailSerializer 加密过，
     * 解它会把这里绑死在 T-102 的序列化格式上。
     */
    private function queuedMailCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }

    /**
     * 从 JWT 的 payload 段读一个 claim。**不验签** —— 这是用例自己刚拿到的响应。
     */
    private static function claim(string $token, string $name): string
    {
        $payload = json_decode(
            (string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true),
            true,
            8,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($payload);
        self::assertIsString($payload[$name] ?? null);

        return $payload[$name];
    }

    /**
     * problem body 里**客户端真的会看到**的那几个成员。
     *
     * 剥掉三样：
     *   - `request_id` / `instance` —— 每次请求本来就不同；
     *   - `debug` —— 只在 `APP_DEBUG` 下出现（含文件名、行号与调用栈）。
     *     它当然逐条不同，但那是**测试环境**的产物，与「两种拒绝在客户端眼里
     *     是否可区分」这个命题无关。生产响应里没有它。
     *
     * @return array<string, mixed>
     */
    private static function clientVisible(Response $response): array
    {
        $body = self::decode($response);

        unset($body['request_id'], $body['instance'], $body['debug']);

        return $body;
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
}
