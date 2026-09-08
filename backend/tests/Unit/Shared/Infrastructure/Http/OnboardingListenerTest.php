<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Application\Token\AccessTokenVerifierInterface;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Http\RequestAttributes;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Onboarding\OnboardingState;
use App\Shared\Infrastructure\Http\OnboardingListener;
use App\Tests\Double\Identity\StubOnboardingStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * `OnboardingListener` 的分支（T-108）。
 *
 * 端到端的证明在 `tests/Api/OnboardingCoverageTest`（它真的发请求，
 * 且会随每个新端点自动生长）。本文件补的是那边看不见的两件事：
 *
 *  1. 四条 early return 是**结构性**跳过，不是碰巧问出了一个放行的答案
 *     —— 用 {@see StubOnboardingStatus::calls()} 断言「一次都没查库」；
 *  2. `UserUnknown` 那一格返回的是 401 而不是 403，且文案与验签器逐字相同。
 *     真库上很难造出「token 有效但行没了」，而那正是最容易被写成 403 的一格。
 */
#[CoversClass(OnboardingListener::class)]
final class OnboardingListenerTest extends TestCase
{
    // ========================================================================
    // 四条 early return —— 一次都不该查库
    // ========================================================================

    public function testIgnoresSubRequests(): void
    {
        $status = new StubOnboardingStatus(OnboardingState::Incomplete);

        $this->dispatch($status, self::request('/v1/cards', 'cards_list', self::auth()), HttpKernelInterface::SUB_REQUEST);

        self::assertSame(0, $status->calls());
    }

    /**
     * 非 `/v1` 的东西（`/health/live` 等）与产品 API 的横切规则无关。
     */
    public function testIgnoresNonProductApiPaths(): void
    {
        $status = new StubOnboardingStatus(OnboardingState::Incomplete);

        $this->dispatch($status, self::request('/health/ready', 'health_ready', self::auth()));

        self::assertSame(0, $status->calls());
    }

    /**
     * 三条豁免路由都不查库 —— 它们本来就该放行，多查一次纯属浪费。
     */
    #[DataProvider('exemptRoutes')]
    public function testLetsTheExemptRoutesThroughWithoutAskingAtAll(string $route, string $path): void
    {
        $status = new StubOnboardingStatus(OnboardingState::Incomplete);

        $this->dispatch($status, self::request($path, $route, self::auth()));

        self::assertSame(0, $status->calls());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function exemptRoutes(): iterable
    {
        yield 'me_get' => ['me_get', '/v1/me'];
        yield 'me_username_set' => ['me_username_set', '/v1/me/username'];
        yield 'auth_logout' => ['auth_logout', '/v1/auth/logout'];
    }

    /**
     * ⚠️ 没有 `AuthContext` 即放行 —— 那是一条免鉴权路由
     * （`AuthenticationListener` 对它们是在写 attribute **之前**返回的）。
     *
     * 写成「没有身份就 403」的话，`POST /v1/auth/otp/request` 与
     * `/v1/auth/otp/verify` 会一起被挡住 —— 也就是**登录本身**变得不可能，
     * 而症状是一个说「你得先设 username」的 403。
     */
    public function testLetsUnauthenticatedRoutesThrough(): void
    {
        $status = new StubOnboardingStatus(OnboardingState::Incomplete);

        $this->dispatch($status, self::request('/v1/auth/otp/verify', 'auth_otp_verify', null));

        self::assertSame(0, $status->calls());
    }

    // ========================================================================
    // 三格状态
    // ========================================================================

    public function testLetsACompleteUserThrough(): void
    {
        $status = new StubOnboardingStatus(OnboardingState::Complete);

        $this->dispatch($status, self::request('/v1/me/devices', 'me_devices_list', self::auth()));

        self::assertSame(1, $status->calls());
    }

    public function testBlocksAnIncompleteUser(): void
    {
        try {
            $this->dispatch(
                new StubOnboardingStatus(OnboardingState::Incomplete),
                self::request('/v1/me/devices', 'me_devices_list', self::auth()),
            );
            self::fail('未完成 onboarding 的用户必须被拦成 403 username_required');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::UsernameRequired, $e->errorCode());
            self::assertSame(403, $e->errorCode()->httpStatus());
        }
    }

    /**
     * ⚠️ 行不见了是 **401**，不是 403。
     *
     * 403 会把客户端指向 `POST /me/username`（就在豁免表里），而那条路径的
     * 查找同样落空、抛 500 —— 客户端在 403 与 500 之间打转，且两个码都不会
     * 让它清掉本地会话。401 + 与验签器逐字相同的文案则让 T-150 的
     * Authenticator 去静默刷新，刷新同样失败，于是它清会话跳登录。
     */
    public function testTreatsAVanishedUserAsAnInvalidToken(): void
    {
        try {
            $this->dispatch(
                new StubOnboardingStatus(OnboardingState::UserUnknown),
                self::request('/v1/me/devices', 'me_devices_list', self::auth()),
            );
            self::fail('用户行不存在时必须抛 token_invalid');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::TokenInvalid, $e->errorCode());
            self::assertSame(
                AccessTokenVerifierInterface::REJECTED,
                $e->detail(),
                '与验签器逐字相同的文案 —— 否则「用户已删」与「token 是编的」对探测者可区分（ADR-0015 决策 6）',
            );
        }
    }

    // ========================================================================
    // helpers
    // ========================================================================

    private function dispatch(
        StubOnboardingStatus $status,
        Request $request,
        int $type = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        // stub 而不是 mock：`RequestEvent` 只是把它存起来，本监听器一次都不调它。
        $kernel = self::createStub(HttpKernelInterface::class);

        (new OnboardingListener($status))->onRequest(new RequestEvent($kernel, $request, $type));
    }

    private static function request(string $path, string $route, ?AuthContext $auth): Request
    {
        $request = Request::create($path);
        $request->attributes->set('_route', $route);

        if (null !== $auth) {
            $request->attributes->set(RequestAttributes::AUTH_CONTEXT, $auth);
        }

        return $request;
    }

    private static function auth(): AuthContext
    {
        $id = Uuid::fromString('01941f29-7c00-70ab-8000-000000000001');

        return new AuthContext($id, $id, $id);
    }
}
