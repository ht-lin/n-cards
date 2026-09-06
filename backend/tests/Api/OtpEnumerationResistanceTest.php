<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Identity\Application\Otp\RequestOtpService;
use App\Module\Identity\Application\Otp\VerifyOtpService;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * **T-103 与 T-104 共同的验收标准**：已注册与未注册的邮箱，在 request 与 verify
 * 两个端点上的响应体结构、状态码与耗时分布都不可区分（§3.8）。
 *
 * ============================================================================
 * ADR-0014 之后，request 侧这件事变简单了
 * ============================================================================
 * T-103 交付时是「两条路径 + 逐项配平」：邮箱不存在时建哑挑战、不发信。
 * T-104 发现那个设计让注册不可能（新用户永远收不到码），于是按 ADR-0014
 * 合并成一条 —— **无论邮箱是否注册都真发码**。
 *
 * 于是 request 侧的断言从「两条路径长得一样」变成了「只有一条路径」：
 * 下面 {@see testBothPathsLeaveTheSameKindOfRowBehind()} 现在断言两次请求
 * 留下的行**连 `is_decoy` 都相同**。
 *
 * ============================================================================
 * 攻击面搬到了 verify
 * ============================================================================
 * request 恒 202、恒发信之后，攻击者剩下的唯一入口是：对任意邮箱走完 request
 * （信进了受害者的收件箱，他看不到），拿到一个真实的 `challenge_id`，
 * 再随便编一个码去 verify。要挡的因此是这一对：
 *
 *     「**未注册**邮箱的 challenge + 错码 → 401」
 *     「**已注册**邮箱的 challenge + 错码 → 401」
 *
 * 本文件的 verify 那一节盯的就是它。
 *
 * ============================================================================
 * 断言分三层，强度递减
 * ============================================================================
 * 1. **响应形状**（本文件）—— 两次响应除了 id 与时间戳之外逐字节相同。
 * 2. **持久化足迹**（本文件）—— 两条路径在 `otp_challenges` 上留下的行完全一样。
 * 3. **耗时**（本文件的最后两条 + 单测）—— 墙钟只做**兜底**：它在共享 runner
 *    上抖动很大，容差必须开得很宽，于是它只能抓住「填充压根没生效」这一类回归。
 *    真正确定性的那两条断言是
 *    `RequestOtpServiceTest::testTheEndpointCannotTellWhetherTheAddressIsRegistered()`
 *    与 `VerifyOtpServiceTest::testEveryRejectionShapeDoesTheSameAmountOfWork()` ——
 *    它们数的是 Vault 与 DB 的往返次数，不受调度抖动影响。
 */
#[CoversClass(RequestOtpService::class)]
#[CoversClass(VerifyOtpService::class)]
final class OtpEnumerationResistanceTest extends WebTestCase
{
    use RequiresOtpStack;

    private const PATH = '/v1/auth/otp/request';

    private const VERIFY_PATH = '/v1/auth/otp/verify';

    /** 种进挑战的码。verify 那一节永远用一个**不等于**它的码。 */
    private const CODE = '418396';

    private const WRONG_CODE = '000000';

    /**
     * 墙钟采样次数。取小是因为每次请求都要睡满耗时预算（默认 150 ms），
     * 2 × 12 次已经是 ~4 秒 —— 再多就该拆成一个单独的性能作业了。
     */
    private const SAMPLES = 12;

    private string $registeredEmail;

    protected function setUp(): void
    {
        $this->bootOtpStack();

        $this->registeredEmail = self::uniqueEmail();
        $this->registerUser($this->registeredEmail);
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    /**
     * 第一层：客户端能看到的一切都一样。
     *
     * 这条如果红了，通常是有人「顺手」在响应里加了个字段（`is_new_user`、
     * `email_sent`……）。那种字段看起来无害，但它就是账号枚举接口本身。
     */
    public function testTheTwoResponsesAreIndistinguishableToTheClient(): void
    {
        $registered = $this->requestFor($this->registeredEmail);
        $unknown = $this->requestFor(self::uniqueEmail());

        self::assertSame(Response::HTTP_ACCEPTED, $registered->getStatusCode());
        self::assertSame($registered->getStatusCode(), $unknown->getStatusCode());

        self::assertSame(
            $registered->headers->get('Content-Type'),
            $unknown->headers->get('Content-Type'),
        );

        $a = self::decode($registered);
        $b = self::decode($unknown);

        self::assertSame(array_keys($a), array_keys($b), '两条路径的字段集必须逐字相同');
        self::assertSame($a['resend_after_seconds'], $b['resend_after_seconds']);

        // 只有 challenge_id 与 expires_at 允许不同，且必须是同一种形状。
        self::assertNotSame($a['challenge_id'], $b['challenge_id']);
        self::assertSame(\strlen($a['challenge_id']), \strlen($b['challenge_id']));
        self::assertSame(\strlen($a['expires_at']), \strlen($b['expires_at']));

        // 把两处可变值抹掉之后，剩下的应该完全一致 —— 包括字段顺序
        // （JSON 的键序是稳定的，一个按分支拼装的响应体很容易在这里露馅）。
        self::assertSame(self::redact($a), self::redact($b));
    }

    /**
     * 第二层：库里也一样。
     *
     * ⚠️ ADR-0014 之后这条比原来**更强**：两行现在连 `is_decoy` 都相同
     * （恒为 false）。原来那句「只差一个布尔」不再成立 ——
     * 服务端在这条路径上压根不知道邮箱注册过没有，所以也无从写出差别。
     *
     * 这条盯的是对内的一半：§8.4 的数据导出与将来的运维查询都可能把库层的
     * 差别泄露出去，所以两行必须逐列同样地被填上。
     */
    public function testBothPathsLeaveTheSameKindOfRowBehind(): void
    {
        $this->requestFor($this->registeredEmail);
        $known = $this->lastChallengeRow();

        $this->requestFor(self::uniqueEmail());
        $unknown = $this->lastChallengeRow();

        // ⚠️ 这一对断言是 ADR-0014 的核心：不再有「哑挑战」这个类别。
        self::assertFalse($known['is_decoy']);
        self::assertFalse($unknown['is_decoy'], 'ADR-0014: an unregistered address gets a real challenge, not a decoy.');

        // 每一列都必须「同样地被填上」。email_encrypted 与 locale 是 T-104 加的 ——
        // 它们是注册路径的输入，未注册那条**尤其**不能为空（正是它要用来建 users 行）。
        foreach (['code_hash', 'request_ip_hash', 'expires_at', 'created_at', 'purpose', 'email_encrypted', 'locale'] as $column) {
            self::assertNotNull($known[$column], $column.' 在已注册路径上不该为空');
            self::assertNotNull($unknown[$column], $column.' 在未注册路径上不该为空 —— 空值让这一行在库层可辨认');
        }

        self::assertSame($known['purpose'], $unknown['purpose']);
        self::assertSame($known['locale'], $unknown['locale']);
        self::assertSame(0, $known['attempts']);
        self::assertSame($known['attempts'], $unknown['attempts']);
        self::assertNull($known['consumed_at']);
        self::assertNull($unknown['consumed_at']);

        // 摘要长度相同（都是 32 字节的 BYTEA），且**不是**同一个值 ——
        // 后者会说明用了常量码。
        self::assertSame(\strlen($known['code_hash']), \strlen($unknown['code_hash']));
        self::assertNotSame($known['code_hash'], $unknown['code_hash']);

        // Magic Link 归 T-106：两条路径现在都不签发，所以这一列都得是 NULL。
        self::assertNull($known['magic_token_hash']);
        self::assertNull($unknown['magic_token_hash']);
    }

    /**
     * ⚠️ ADR-0014 的直接后果，也是它最容易被「优化」掉的一半：
     * **未注册的邮箱也必须真的收到一封信**。
     *
     * 不发的话新用户永远拿不到码，于是永远无法注册 —— 而契约里没有第二个
     * 注册端点。这条同时也是防枚举的一部分：一个只给已注册地址发信的服务端，
     * 会把「注册过没有」写进受害者的收件箱之外的每一处遥测里。
     *
     * 数的是 `messenger_messages` 的行数：`MailSenderInterface::send()` 只入队，
     * 真正的投递在 worker 里（本测试不跑 worker）。
     */
    public function testAnUnregisteredAddressAlsoGetsAMail(): void
    {
        $before = $this->queuedMailCount();

        $this->requestFor(self::uniqueEmail());

        self::assertSame($before + 1, $this->queuedMailCount(), 'ADR-0014: every address gets a real code.');

        $this->requestFor($this->registeredEmail);

        self::assertSame($before + 2, $this->queuedMailCount());
    }

    /**
     * 第三层（兜底）：墙钟中位数落在同一个量级。
     *
     * ⚠️ **容差刻意开得很宽**。这条抓的是「填充压根没生效」——
     * 比如有人把 `settle()` 挪到了 `if` 里、或者把预算配成了 0。
     * 几毫秒量级的真实差异它抓不住，那是上面两层与单测的活。
     *
     * 用中位数而不是均值：CI 上偶发的一次 GC 或调度停顿会把均值拉到没法断言。
     */
    public function testTheTwoPathsTakeAboutTheSameTime(): void
    {
        // ⚠️ 每次采样都要用一个**没用过**的已注册邮箱。
        // `otp_request_email` 是 1/min，对同一个地址连发 12 次的话，
        // 第 2 次开始全是 429 —— 而那看起来会像是「响应形状不对」。
        // 建号本身也要打 Vault，所以必须在计时开始**之前**全部建好。
        $registeredEmails = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            $registeredEmails[] = $this->registerUser(self::uniqueEmail());
        }

        $registered = [];
        $unknown = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            // 交替发，让两组均匀地摊到同一段时间里 —— 先发完一组再发另一组的话，
            // 机器负载的自然漂移会被读成两条路径的差异。
            $registered[] = self::timeOf(fn () => $this->requestFor($registeredEmails[$i]));
            $unknown[] = self::timeOf(fn () => $this->requestFor(self::uniqueEmail()));
        }

        $budget = self::budgetMillis();
        $delta = abs(self::median($registered) - self::median($unknown));

        self::assertLessThan(
            $budget * 0.5,
            $delta,
            \sprintf(
                "两条路径的耗时中位数差了 %.1f ms（预算 %d ms）。\n".
                "这通常意味着恒定耗时填充没生效 —— 检查 RequestOtpService 里的 settle()，\n".
                '以及日志里有没有 "Constant-time budget overrun"（那说明预算需要调高）。',
                $delta,
                $budget,
            ),
        );

        // 顺带确认填充确实在起作用：两组都应该 ≥ 预算。都远低于预算的话，
        // 说明均衡器根本没被调用，而上面那条差值断言会因为「两边都很快」而假绿。
        self::assertGreaterThanOrEqual($budget * 0.8, min(self::median($registered), self::median($unknown)));
    }

    // ========================================================================
    // verify 侧 —— ADR-0014 之后剩下的那个攻击面
    // ========================================================================

    /**
     * 第一层（verify）：两次 401 对客户端**逐字节相同**。
     *
     * 攻击者手上有两个真实的 `challenge_id`（一个背后有 users 行，一个没有），
     * 各配一个错码。两次拒绝若有任何可见差别 —— 状态码、`detail`、
     * `errors[]`、header —— 「这个邮箱注册过吗」就答出来了。
     */
    public function testTheTwoRejectionsAreIndistinguishableToTheClient(): void
    {
        $known = $this->rejectFor($this->registeredEmail);
        $unknown = $this->rejectFor(self::uniqueEmail());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $known->getStatusCode());
        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->headers->get('Content-Type'), $unknown->headers->get('Content-Type'));

        $a = self::decode($known);
        $b = self::decode($unknown);

        self::assertSame(array_keys($a), array_keys($b), '两次拒绝的字段集必须逐字相同');

        // `instance` 与 `request_id` 是本次请求的追踪 id，两次必然不同。
        // 其余每一个字节都必须相等 —— 包括 dev/test 才有的 `debug` 块：
        // 那里面有异常类名与消息，两条路径若走了不同的分支就会在那儿露馅。
        self::assertSame(self::redactProblem($a), self::redactProblem($b));
    }

    /**
     * 第二层（verify）：库里的足迹也一样 —— 两条挑战都只多了一次 attempts。
     *
     * 少写一次（比如「查不到用户就早退」）会让未注册那条少一次 UPDATE，
     * 而那是一次稳定可测的耗时差。
     */
    public function testBothRejectionsLeaveTheSameFootprint(): void
    {
        foreach ([$this->registeredEmail, self::uniqueEmail()] as $email) {
            $challenge = $this->seedChallenge(self::CODE, $email);

            $this->verifyWith($challenge->id()->toString(), self::WRONG_CODE);

            self::assertSame(
                1,
                (int) $this->connection->fetchOne('SELECT attempts FROM otp_challenges WHERE id = ?', [$challenge->id()->toString()]),
                '两条路径都必须记下这一次失败尝试',
            );
            self::assertNull(
                $this->connection->fetchOne('SELECT consumed_at FROM otp_challenges WHERE id = ?', [$challenge->id()->toString()]),
                '失败的尝试不该消费掉挑战',
            );
        }
    }

    /**
     * 第三层（verify，兜底）：墙钟中位数落在同一个量级。
     *
     * 容差与理由同 request 侧那条 —— 这里抓的是「有人在拒绝路径上按存在性
     * 加了一次查询」或者「settle() 被挪进了某个分支」。
     */
    public function testTheTwoRejectionsTakeAboutTheSameTime(): void
    {
        // 挑战要在计时**之前**全部种好：种一条要打两次 Vault。
        $known = [];
        $unknown = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            $known[] = $this->seedChallenge(self::CODE, $this->registeredEmail)->id()->toString();
            $unknown[] = $this->seedChallenge(self::CODE, self::uniqueEmail())->id()->toString();
        }

        $knownTimes = [];
        $unknownTimes = [];

        for ($i = 0; $i < self::SAMPLES; ++$i) {
            // 交替发，让机器负载的自然漂移均匀摊到两组上。
            $knownTimes[] = self::timeOf(fn () => $this->verifyWith($known[$i], self::WRONG_CODE));
            $unknownTimes[] = self::timeOf(fn () => $this->verifyWith($unknown[$i], self::WRONG_CODE));
        }

        $budget = self::verifyBudgetMillis();
        $delta = abs(self::median($knownTimes) - self::median($unknownTimes));

        self::assertLessThan(
            $budget * 0.5,
            $delta,
            \sprintf(
                "两条拒绝路径的耗时中位数差了 %.1f ms（预算 %d ms）。\n".
                "检查 VerifyOtpService 里的 settle()，以及那条布尔链有没有被改成\n".
                '按存在性短路；再看日志里有没有 "Constant-time budget overrun"。',
                $delta,
                $budget,
            ),
        );

        // 顺带确认填充确实在起作用 —— 都远低于预算的话，上面那条会因为
        // 「两边都很快」而假绿。
        self::assertGreaterThanOrEqual($budget * 0.8, min(self::median($knownTimes), self::median($unknownTimes)));
    }

    // ========================================================================
    // 支撑
    // ========================================================================

    /**
     * 种一条挑战（邮箱由调用方决定注册过没有），然后用**错码**去验它。
     */
    private function rejectFor(string $email): Response
    {
        $challenge = $this->seedChallenge(self::CODE, $email);

        return $this->verifyWith($challenge->id()->toString(), self::WRONG_CODE);
    }

    private function verifyWith(string $challengeId, string $code): Response
    {
        $this->client->request(
            'POST',
            self::VERIFY_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                'REMOTE_ADDR' => self::uniqueIp(),
            ],
            content: json_encode([
                'challenge_id' => $challengeId,
                'code' => $code,
                'device' => [
                    'id' => '0192f3a1-b2c3-7d4e-8f01-0000000000de',
                    'platform' => 'android',
                ],
            ], \JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse();
    }

    /**
     * 队列里现在有几封信。`MailSenderInterface::send()` 只入队，
     * 真正的投递在 worker 里（本测试不跑 worker）。
     */
    private function queuedMailCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM messenger_messages');
    }

    /**
     * 用应用**自己的** HMAC 与加密门面建一个用户 —— email_hash 必须与端点
     * 稍后算出来的完全一致，否则「已注册」那条路径根本走不到。
     */
    private function registerUser(string $email): string
    {
        $container = static::getContainer();

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var UuidGeneratorInterface $uuids */
        $uuids = $container->get(UuidGeneratorInterface::class);

        $repository = new DoctrineUserRepository($this->entityManager);

        $repository->save(User::register(
            $uuids->generate(),
            HashDigest::fromRaw($hasher->hash($email)),
            $crypto->encrypt(CryptoKey::Pii, $email),
            Locale::German,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        return $email;
    }

    private function requestFor(string $email): Response
    {
        $this->client->request(
            'POST',
            self::PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
                'REMOTE_ADDR' => self::uniqueIp(),
            ],
            content: json_encode(['email' => $email, 'locale' => 'de'], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();

        // 限流把用例打成 429 时，失败信息应该说清是限流而不是「形状不对」。
        self::assertSame(
            Response::HTTP_ACCEPTED,
            $response->getStatusCode(),
            'Expected 202, got '.$response->getStatusCode().': '.(string) $response->getContent(),
        );

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastChallengeRow(): array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT is_decoy, code_hash, magic_token_hash, request_ip_hash, purpose, attempts,'
            .' expires_at, consumed_at, created_at, email_encrypted, locale'
            .' FROM otp_challenges ORDER BY created_at DESC, id DESC LIMIT 1',
        );

        self::assertIsArray($row, 'No challenge was stored.');

        // pdo_pgsql 把 BYTEA 读成 stream，BOOLEAN 读成 bool ——
        // 断言之前统一成标量，免得比较的是两个 resource。
        foreach (['code_hash', 'magic_token_hash', 'request_ip_hash'] as $column) {
            if (\is_resource($row[$column])) {
                $row[$column] = stream_get_contents($row[$column]);
            }
        }

        $row['is_decoy'] = (bool) $row['is_decoy'];
        $row['attempts'] = (int) $row['attempts'];

        return $row;
    }

    /**
     * 抹掉两个允许不同的字段，剩下的必须完全一致。
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function redact(array $body): array
    {
        return [...$body, 'challenge_id' => '<redacted>', 'expires_at' => '<redacted>'];
    }

    /**
     * 抹掉 problem body 里两个按请求变化的追踪 id。
     *
     * @param array<string, mixed> $problem
     *
     * @return array<string, mixed>
     */
    private static function redactProblem(array $problem): array
    {
        return [...$problem, 'instance' => '<redacted>', 'request_id' => '<redacted>'];
    }

    private static function budgetMillis(): int
    {
        /** @var int $budget */
        $budget = static::getContainer()->getParameter('ncards.otp.request_budget_ms');

        return $budget;
    }

    private static function verifyBudgetMillis(): int
    {
        /** @var int $budget */
        $budget = static::getContainer()->getParameter('ncards.otp.verify_budget_ms');

        return $budget;
    }

    /**
     * @return float 毫秒
     */
    private static function timeOf(callable $work): float
    {
        $startedAt = hrtime(true);
        $work();

        return (hrtime(true) - $startedAt) / 1_000_000;
    }

    /**
     * @param list<float> $samples
     */
    private static function median(array $samples): float
    {
        sort($samples);

        $middle = intdiv(\count($samples), 2);

        return 0 === \count($samples) % 2
            ? ($samples[$middle - 1] + $samples[$middle]) / 2
            : $samples[$middle];
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
