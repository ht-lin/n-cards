<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Identity\Application\Me;

use App\Module\Identity\Application\Me\AssignUsernameService;
use App\Module\Identity\Application\Me\UsernamePayload;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\UsernameRules;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Double\Identity\InMemoryUserRepository;
use App\Tests\Double\Metrics\RecordingMetrics;
use App\Tests\Double\Time\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `POST /v1/me/username` 的编排（T-107）。
 *
 * ============================================================================
 * 这个文件真正在守的东西
 * ============================================================================
 * **哪些失败消耗 §7.5 的 10 次预算**（ADR-0017 的决定 2）。那条策略没有任何
 * 结构性的东西替它把关：把 `recordUsernameAttempt()` 往上挪两行，所有别的
 * 用例照常绿，而后果是客户端本地校验的一个 bug 能在 10 次内把用户**永久**
 * 钉死在 onboarding —— username 不可变，T-108 的拦截器又会挡住注销路径。
 *
 * 所以下面每一条错误路径都同时断言「计数变没变」。
 */
#[CoversClass(AssignUsernameService::class)]
final class AssignUsernameServiceTest extends TestCase
{
    private const MAX_ATTEMPTS = 10;

    private const RESERVED = [
        'admin', 'support', 'ncards', 'help', 'root', 'system', 'info',
        'kontakt', 'datenschutz', 'impressum', 'hilfe', 'konto',
    ];

    private RecordingMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new RecordingMetrics();
    }

    // ========================================================================
    // 正常路径
    // ========================================================================

    public function testAssignsTheNormalisedUsernameAndReturnsTheProfile(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);

        // 验收标准逐字写的那个输入。
        $profile = $this->service($users)->assign(self::authFor($user), new UsernamePayload('Anna_B '));

        self::assertSame('anna_b', $profile->username);
        self::assertTrue($profile->onboardingComplete);
        self::assertTrue($user->id()->equals($profile->userId));
        self::assertSame('anna_b', $user->username());
        self::assertSame(['set'], self::results($this->metrics));
    }

    public function testASuccessfulAssignmentConsumesOneAttempt(): void
    {
        $user = IdentityEntities::user();

        $this->service(new InMemoryUserRepository($user))->assign(self::authFor($user), new UsernamePayload('anna_b'));

        self::assertSame(1, $user->usernameAttempts());
    }

    // ========================================================================
    // ① 已经设过了 → 409，**不消耗**
    // ========================================================================

    public function testRejectsAnAlreadySetUsernameAsImmutable(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());
        $users = new InMemoryUserRepository($user);

        $this->assertFailsWith(
            ErrorCode::UsernameImmutable,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload('anna_c')),
        );

        self::assertSame('anna_b', $user->username(), '旧值必须原封不动');
        self::assertSame(0, $user->usernameAttempts(), 'immutable 不消耗次数');
        self::assertSame(['immutable'], self::results($this->metrics));
    }

    /**
     * ⚠️ 顺序断言：immutable 的判定必须在**格式校验之前**。
     *
     * 反过来的话，一个已经设过名字的客户端发一个格式非法的值会拿到 422 ——
     * 而它的真实处境是「你的本机状态过期了，去拉 GET /me」，两种 UI 完全不同。
     */
    public function testImmutabilityIsCheckedBeforeTheFormat(): void
    {
        $user = IdentityEntities::user();
        $user->assignUsername('anna_b', IdentityEntities::now());
        $users = new InMemoryUserRepository($user);

        $this->assertFailsWith(
            ErrorCode::UsernameImmutable,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload('!!!')),
        );
    }

    // ========================================================================
    // ② 格式 / 保留词 → 422 username_invalid，**不消耗**
    // ========================================================================

    #[DataProvider('invalidUsernames')]
    public function testRejectsAnInvalidUsernameWithoutConsumingAnAttempt(string $raw): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);

        $this->assertFailsWith(
            ErrorCode::UsernameInvalid,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload($raw)),
        );

        self::assertSame(0, $user->usernameAttempts());
        self::assertNull($user->username());
        self::assertSame(['invalid'], self::results($this->metrics));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidUsernames(): iterable
    {
        yield 'too short' => ['ab'];
        yield 'too long' => [str_repeat('a', 21)];
        yield 'illegal character' => ['anna-b'];
        yield 'umlaut' => ['anna_müller'];
        yield 'reserved word' => ['admin'];
        yield 'reserved word, mixed case' => ['Datenschutz'];
    }

    /**
     * 本卡最值钱的一条：**十次格式错误之后，第十一次合法输入仍然成功。**.
     *
     * 这正是「客户端预校验有 bug」的场景。把 422 也计数的实现会在这里红。
     */
    public function testTenInvalidAttemptsDoNotLockTheUserOut(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);
        $service = $this->service($users);
        $auth = self::authFor($user);

        for ($i = 0; $i < 10; ++$i) {
            $this->assertFailsWith(
                ErrorCode::UsernameInvalid,
                fn () => $service->assign($auth, new UsernamePayload('anna-b')),
            );
        }

        self::assertSame(0, $user->usernameAttempts());
        self::assertSame('anna_b', $service->assign($auth, new UsernamePayload('anna_b'))->username);
    }

    // ========================================================================
    // ③ 预算耗尽 → 422 limit_exceeded
    // ========================================================================

    /**
     * 边界：已用 9 次时放行（这是第 10 次），已用 10 次时拒。
     */
    public function testAllowsTheTenthAttemptAndRejectsTheEleventh(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);
        $service = $this->service($users);
        $auth = self::authFor($user);

        // 九次「被占用」把计数打到 9 —— taken 是唯一消耗次数的失败路径。
        $users->save(self::squatterOwning('taken_name'));

        for ($i = 0; $i < 9; ++$i) {
            $this->assertFailsWith(
                ErrorCode::UsernameTaken,
                fn () => $service->assign($auth, new UsernamePayload('taken_name')),
            );
        }

        self::assertSame(9, $user->usernameAttempts());

        // 第 10 次仍然放行。
        $this->assertFailsWith(
            ErrorCode::UsernameTaken,
            fn () => $service->assign($auth, new UsernamePayload('taken_name')),
        );
        self::assertSame(10, $user->usernameAttempts());

        // 第 11 次是终局 —— 422 limit_exceeded，**不是** 429（ADR-0017）。
        $this->assertFailsWith(
            ErrorCode::LimitExceeded,
            fn () => $service->assign($auth, new UsernamePayload('anna_b')),
        );
        self::assertSame(10, $user->usernameAttempts(), '被拒的那次不再累加');
        self::assertNull($user->username());
    }

    public function testExhaustionUsesTwentyTwoAndNotFourTwentyNine(): void
    {
        $user = IdentityEntities::user();

        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $user->recordUsernameAttempt(self::MAX_ATTEMPTS);
        }

        $this->assertFailsWith(
            ErrorCode::LimitExceeded,
            fn () => $this->service(new InMemoryUserRepository($user))
                ->assign(self::authFor($user), new UsernamePayload('anna_b')),
        );

        self::assertSame(422, ErrorCode::LimitExceeded->httpStatus());
        self::assertSame(['exhausted'], self::results($this->metrics));
    }

    /**
     * ⚠️ 预算检查在**格式校验之后**：一个已经用尽次数的用户发一个非法的名字，
     * 拿到的仍然是 422 username_invalid。
     *
     * 这条顺序本身无关紧要（两者都是 422），但反过来会让上面那条
     * 「十次格式错误不锁人」的保证在预算边界附近失效。
     */
    public function testTheFormatIsCheckedBeforeTheBudget(): void
    {
        $user = IdentityEntities::user();

        for ($i = 0; $i < self::MAX_ATTEMPTS; ++$i) {
            $user->recordUsernameAttempt(self::MAX_ATTEMPTS);
        }

        $this->assertFailsWith(
            ErrorCode::UsernameInvalid,
            fn () => $this->service(new InMemoryUserRepository($user))
                ->assign(self::authFor($user), new UsernamePayload('anna-b')),
        );
    }

    // ========================================================================
    // ④ 已被占用 → 409 username_taken，**消耗**
    // ========================================================================

    /**
     * §13.4-6 逐字写的那一条：`Anna_B ` 与 `anna_b` 视为同一个，
     * 第二次返回 `username_taken`。
     */
    public function testRejectsATakenUsernameAcrossNormalisation(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user, self::squatterOwning('anna_b'));

        $this->assertFailsWith(
            ErrorCode::UsernameTaken,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload('Anna_B ')),
        );

        self::assertNull($user->username());
        self::assertSame(1, $user->usernameAttempts(), 'taken 是唯一真的探到了占用情况的路径');
        self::assertSame(['taken'], self::results($this->metrics));
    }

    /**
     * 预查漏掉的并发撞车由仓储（真库上是 `uq_users_username`）兜底，
     * 而服务必须把它照样报成 `username_taken` —— 对调用方是同一件事。
     */
    public function testTranslatesAConcurrentUniqueViolationToTaken(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);
        // 预查会放行（库里此刻确实没有这个名字），写入时才撞车。
        $users->failSaveNewUsernameFor('anna_b');

        $this->assertFailsWith(
            ErrorCode::UsernameTaken,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload('anna_b')),
        );

        self::assertSame(['taken'], self::results($this->metrics));
    }

    /**
     * ⚠️ 计数必须在**查重之前**就落库。
     *
     * 真库上 Doctrine 会在 flush 失败时关掉 EntityManager，两者挤在同一次 flush
     * 里的话，撞唯一约束那一路的计数会被一起丢 —— 于是这条限流对**唯一真正在
     * 试探的人**恰好失效。这里用调用顺序把那个约束钉住。
     */
    public function testPersistsTheAttemptBeforeLookingUpTheName(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user, self::squatterOwning('anna_b'));

        $this->assertFailsWith(
            ErrorCode::UsernameTaken,
            fn () => $this->service($users)->assign(self::authFor($user), new UsernamePayload('anna_b')),
        );

        self::assertSame(['findById', 'save', 'findByUsername'], $users->calls());
    }

    /**
     * 成功路径上的完整顺序：查人 → 记次数并落库 → 查重 → 写名字。
     * 两次 flush（`save` 与 `saveNewUsername`）是有意的，别合并 —— 见上一条。
     */
    public function testUsesTwoSeparateWritesOnTheHappyPath(): void
    {
        $user = IdentityEntities::user();
        $users = new InMemoryUserRepository($user);

        $this->service($users)->assign(self::authFor($user), new UsernamePayload('anna_b'));

        self::assertSame(['findById', 'save', 'findByUsername', 'saveNewUsername'], $users->calls());
    }

    // ========================================================================
    // 找不到用户
    // ========================================================================

    /**
     * 令牌验过签却找不到行 —— 只可能是 T-113 的清理任务在本次请求的窗口里
     * 删掉了它。那不是客户端能修的东西，**也不该是 404**：调用方拿着一个
     * 有效令牌却被告知自己不存在。
     */
    public function testTreatsAMissingUserAsAnInternalError(): void
    {
        $auth = new AuthContext(IdentityEntities::id(99), IdentityEntities::id(2), IdentityEntities::id(3));

        $this->assertFailsWith(
            ErrorCode::InternalError,
            fn () => $this->service(new InMemoryUserRepository())->assign($auth, new UsernamePayload('anna_b')),
        );
    }

    // ========================================================================
    // helpers
    // ========================================================================

    /**
     * @param callable(): mixed $act
     */
    private function assertFailsWith(ErrorCode $expected, callable $act): void
    {
        try {
            $act();
            self::fail(\sprintf('Expected DomainException with code %s.', $expected->value));
        } catch (DomainException $e) {
            self::assertSame($expected, $e->errorCode());
        }
    }

    private function service(InMemoryUserRepository $users): AssignUsernameService
    {
        $limits = new LimitEnforcer(500, 20, 500, 50, 100, 1024, 2000, 100, 3, 20, '^[a-z0-9_]{3,20}$', self::MAX_ATTEMPTS);

        return new AssignUsernameService(
            $users,
            new UsernameRules($limits, self::RESERVED),
            $limits,
            new FrozenClock(),
            $this->metrics,
        );
    }

    private static function authFor(User $user): AuthContext
    {
        return new AuthContext($user->id(), IdentityEntities::id(2), IdentityEntities::id(3));
    }

    /**
     * 另一个已经占了某个名字的用户。id 与 email_hash 都要与被测用户不同，
     * 否则 InMemoryUserRepository 的 upsert 会把两者合成一个。
     */
    private static function squatterOwning(string $username): User
    {
        $other = IdentityEntities::user(IdentityEntities::id(2), IdentityEntities::digest('bea'));
        $other->assignUsername($username, IdentityEntities::now());

        return $other;
    }

    /**
     * @return list<string> `username_set_total` 的 result 标签，按调用顺序
     */
    private static function results(RecordingMetrics $metrics): array
    {
        return array_map(
            static fn (array $labels): string => $labels['result'] ?? '?',
            $metrics->labelsFor('username_set_total'),
        );
    }
}
