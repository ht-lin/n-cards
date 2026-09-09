<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Module\Wallet\Http\CardController;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/v1/cards` 五个端点的端到端行为 + 契约断言（T-109 的验收标准）。
 *
 * ⚠️ 每条用例都先 {@see RequiresOtpStack::registerOnboardedUser()} ——
 * 从 T-108 起，没设 username 的用户打 `/v1/cards` 一律 `403 username_required`，
 * 而那个症状会把人引向「是不是鉴权坏了」。
 * 拿注册中间态当被测对象的是 `OnboardingCoverageTest`，不是这里。
 */
#[CoversClass(CardController::class)]
final class CardEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const CARD_ID = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';

    private string $token;

    private string $userId;

    protected function setUp(): void
    {
        $this->bootOtpStack();

        $session = $this->registerOnboardedUser();
        $this->token = $session['access_token'];
        $this->userId = $session['user_id'];
    }

    protected function tearDown(): void
    {
        $this->rollbackOtpStack();

        parent::tearDown();
    }

    // ========================================================================
    // POST /v1/cards
    // ========================================================================

    public function testCreatingACardReturns201WithALocationHeader(): void
    {
        $response = $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('/v1/cards/'.self::CARD_ID, $response->headers->get('Location'));

        $body = self::decode($response);

        self::assertSame(self::CARD_ID, $body['id']);
        self::assertSame('REWE Payback', $body['title']);
        // 码值**传输时是明文**（走 TLS），落库时才加密（§5.3）。
        self::assertSame('4012345678901', $body['barcode_value']);
        self::assertSame($this->userId, $body['owner_id']);
        self::assertSame(1, $body['revision']);
        self::assertSame('owner', $body['my_role']);
        self::assertTrue($body['can_edit']);

        // T-110：这两个现在**是真的** —— 建卡在同一个事务里写了一行
        // card_members(role='owner')，它们是那一行的列默认值，不是占位。
        self::assertSame(0, $body['sort_order']);
        self::assertFalse($body['is_pinned']);

        // ⚠️ 这两个仍然刻意**不发**：
        //   member_count —— §5.2 要求它只对 owner 可见（C11），而 M1 阶段它恒为 1，
        //                   连同那条按角色裁剪的逻辑一起留给 T-305；
        //   owner_username —— 要跨模块读 Identity 的 UserDirectoryInterface，
        //                     而 M1 阶段 owner 恒为调用者本人，用户名已经在
        //                     `GET /v1/me` 里了。
        // 发常量占位的话，客户端会把它当成真的存进本地库，等它变成假的那天
        // 本地与服务端不一致且没有任何信号提示要重拉。
        self::assertArrayNotHasKey('member_count', $body);
        self::assertArrayNotHasKey('owner_username', $body);

        self::assertResponseMatchesContract('post', '/v1/cards', $response);
    }

    /**
     * §5.4.3 的天然幂等：同一个 id 二次提交 → `200` + 现有实体，**请求体被忽略**。
     */
    public function testReplayingTheSameIdReturns200AndDoesNotApplyTheBody(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);
        $this->sendJson('PATCH', '/v1/cards/'.self::CARD_ID, ['title' => '用户后来改成的标题'], $this->token, ifMatch: '"1"');

        // 一个迟到的离线 outbox 条目带着旧 body 重放。
        $replay = $this->sendJson('POST', '/v1/cards', self::body(title: '离线队列里那个旧标题'), $this->token);

        self::assertSame(Response::HTTP_OK, $replay->getStatusCode(), (string) $replay->getContent());
        self::assertNull($replay->headers->get('Location'), '什么都没建，就不该有 Location。');

        $body = self::decode($replay);
        self::assertSame('用户后来改成的标题', $body['title'], '重放绝不能把用户改过的标题覆盖回去。');
        self::assertSame(2, $body['revision']);

        self::assertResponseMatchesContract('post', '/v1/cards', $replay);
    }

    public function testAnIdOwnedBySomeoneElseIsAConflict(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $other = $this->registerOnboardedUser();
        $response = $this->sendJson('POST', '/v1/cards', self::body(), $other['access_token']);

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        $problem = self::assertIsProblemDetails($response, ErrorCode::IdConflict);
        // 别人的卡的内容一个字都不能出现在错误里。
        self::assertStringNotContainsString('REWE', json_encode($problem, \JSON_THROW_ON_ERROR));
    }

    public function testAnOverlongTitleIsALimitNotAServerError(): void
    {
        $response = $this->sendJson('POST', '/v1/cards', self::body(title: str_repeat('a', 101)), $this->token);

        // 库层有 CHECK (char_length(title) <= 100) —— 不在应用层挡住就是 500。
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::LimitExceeded);
    }

    /**
     * ⚠️ 超长 note 是 `422 limit_exceeded`，**不是** `400 validation_failed`。
     *
     * 两者对客户端是两种处置：422 说「额度满了」（§7.5 的限额），
     * 400 说「这个字段的形状不对」。`CardFields` 只做类型与格式，
     * 长度归服务层的 `LimitEnforcer` —— 把 note 的 2000 挪进 `CardFields`
     * 会静默地把码从 422 改成 400，而契约里这个端点两个码都声明了，
     * 没有任何契约断言会红。
     */
    public function testAnOverlongNoteIsALimitNotAValidationError(): void
    {
        $response = $this->sendJson('POST', '/v1/cards', self::body(note: str_repeat('b', 2001)), $this->token);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::LimitExceeded);
    }

    /**
     * ⚠️ payload 的限额是 1024 **字节**，不是字符（§7.5）。
     *
     * 513 个 `ä` 在 UTF-8 里是 1026 字节但只有 513 个字符 —— 按 `mb_strlen`
     * 判的话它会一路通过。单测层 `CreateCardServiceTest` 已经钉过一次，
     * 这里再钉一次是因为 HTTP 层多了一次 JSON 解码：解码把 `ä` 还原成
     * 两个字节之后，长度才是服务层看到的那个长度。
     */
    public function testAnOverlongBarcodePayloadIsALimitCountedInBytes(): void
    {
        $response = $this->sendJson(
            'POST',
            '/v1/cards',
            self::body(barcodeValue: str_repeat('ä', 513)),
            $this->token,
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::LimitExceeded);
    }

    /**
     * §7.5 的第一句、T-111 的交付物正题：**第 501 张卡是 `422 limit_exceeded`**。
     *
     * ⚠️ 前 499 张经仓储直接种，不走 499 次 `POST /v1/cards` —— 那是一千多次
     * Vault 往返，会把这条用例变成几十秒，而被测的是**第 500、501 次**建卡。
     * 形状与理由同 {@see CardListPerformanceTest::seedCards()}。
     *
     * 第 500 张仍然走真实的 HTTP 路径：`enforceCanAdd()` 的判据是
     * `current >= max`，499 存量放行 / 500 存量拒绝，两侧都要真的被执行一次
     * 才叫「边界」。差一错误（写成 `>` 或忘了 +1）只在这两次之间现形。
     */
    public function testTheFiveHundredAndFirstCardIsRejectedWithLimitExceeded(): void
    {
        $this->seedCards(Uuid::fromString($this->userId), 499);

        $five_hundredth = $this->sendJson('POST', '/v1/cards', self::body(), $this->token);
        self::assertSame(Response::HTTP_CREATED, $five_hundredth->getStatusCode(), (string) $five_hundredth->getContent());

        $response = $this->sendJson(
            'POST',
            '/v1/cards',
            self::body(id: '0192f3a1-b2c3-7d4e-8f01-23456789ffff'),
            $this->token,
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        $problem = self::assertIsProblemDetails($response, ErrorCode::LimitExceeded);
        // 限额名是**面向客户端的稳定标识**（SystemLimit 的类注释）——
        // 客户端只对 code 分支，但支持工单要靠 detail 分辨是哪一条额度满了。
        self::assertStringContainsString('cards_per_user', $problem['detail']);

        self::assertResponseMatchesContract('post', '/v1/cards', $response);
    }

    public function testABodyThatIsNotContractShapedIsRejected(): void
    {
        $response = $this->sendJson('POST', '/v1/cards', [...self::body(), 'sort_order' => 3], $this->token);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $problem = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);
        // sort_order 属于 card_members（T-110 的 placement 端点），不是 cards。
        self::assertSame('sort_order', $problem['errors'][0]['field']);
        self::assertSame('unknown_field', $problem['errors'][0]['code']);
    }

    // ========================================================================
    // GET /v1/cards、GET /v1/cards/{id}
    // ========================================================================

    public function testTheListReturnsTheContractEnvelope(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $response = $this->sendJson('GET', '/v1/cards', null, $this->token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertCount(1, $body['items']);
        self::assertSame('4012345678901', $body['items'][0]['barcode_value']);
        // §6.1 的通用列表信封：has_more 为 false 时 next_cursor 恒为 null。
        self::assertFalse($body['has_more']);
        self::assertNull($body['next_cursor']);

        self::assertResponseMatchesContract('get', '/v1/cards', $response);
    }

    public function testTheListPagesThroughTheOpaqueCursor(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            $this->sendJson('POST', '/v1/cards', self::body(id: \sprintf('0192f3a1-b2c3-7d4e-8f01-00000000000%d', $i)), $this->token);
        }

        $first = self::decode($this->sendJson('GET', '/v1/cards?limit=2', null, $this->token));

        self::assertCount(2, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertIsString($first['next_cursor']);

        $second = self::decode($this->sendJson('GET', '/v1/cards?limit=2&cursor='.$first['next_cursor'], null, $this->token));

        self::assertCount(1, $second['items']);
        self::assertFalse($second['has_more']);
        self::assertNull($second['next_cursor']);

        // 两页不重不漏。
        $ids = [...array_column($first['items'], 'id'), ...array_column($second['items'], 'id')];
        self::assertSame($ids, array_unique($ids));
    }

    /**
     * §6.1：**不使用** offset。忽略它的话客户端会永远收到第一页而没有任何报错。
     */
    public function testOffsetStylePaginationIsRefused(): void
    {
        $response = $this->sendJson('GET', '/v1/cards?offset=50', null, $this->token);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);
    }

    public function testReadingSomeoneElsesCardTellsTheClientToDropIt(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $other = $this->registerOnboardedUser();
        $response = $this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $other['access_token']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        // 契约：客户端据此**从本地删除该卡**（共享已被撤销）。
        self::assertIsProblemDetails($response, ErrorCode::NotAMember);
    }

    public function testAMalformedCardIdIsNotFoundNotAValidationError(): void
    {
        $response = $this->sendJson('GET', '/v1/cards/not-a-uuid', null, $this->token);

        // 路径参数不是请求体字段：格式非法的 id 指向的资源不存在。
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::NotFound);
    }

    // ========================================================================
    // PATCH /v1/cards/{id}
    // ========================================================================

    public function testPatchingWithTheCurrentRevisionSucceedsAndBumpsIt(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(note: 'bleibt'), $this->token);

        $response = $this->sendJson(
            'PATCH',
            '/v1/cards/'.self::CARD_ID,
            ['title' => 'REWE Payback (Zweitkarte)'],
            $this->token,
            ifMatch: '"1"',
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame('REWE Payback (Zweitkarte)', $body['title']);
        self::assertSame(2, $body['revision']);
        // 没提到的字段一个都不能动。
        self::assertSame('bleibt', $body['note']);
        self::assertSame('4012345678901', $body['barcode_value']);

        self::assertResponseMatchesContract('patch', '/v1/cards/'.self::CARD_ID, $response);
    }

    /**
     * §7.5 的长度限额在 `PATCH` 上与 `POST` 上是同一组数字。
     *
     * ⚠️ 顺序也在这里被钉住：`UpdateCardService` 先查限额、**再**做乐观锁写入，
     * 所以这里给的是**正确**的 `If-Match`。给一个过期的 `If-Match` 也能拿到
     * 一个非 200，但那证明不了限额生效 —— 它只证明了 409 在 422 前面。
     */
    public function testALengthLimitAlsoAppliesOnPatch(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $response = $this->sendJson(
            'PATCH',
            '/v1/cards/'.self::CARD_ID,
            ['title' => str_repeat('a', 101)],
            $this->token,
            ifMatch: '"1"',
        );

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::LimitExceeded);

        self::assertResponseMatchesContract('patch', '/v1/cards/'.self::CARD_ID, $response);
    }

    public function testAStaleIfMatchIsAConflictCarryingTheCurrentState(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $response = $this->sendJson('PATCH', '/v1/cards/'.self::CARD_ID, ['title' => 'DM'], $this->token, ifMatch: '"7"');

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());

        $problem = self::assertIsProblemDetails($response, ErrorCode::RevisionConflict);

        // §5.4.3 的三方合并靠 `current` 里这两项。
        self::assertSame(1, $problem['current']['revision']);
        self::assertArrayHasKey('updated_at', $problem['current']);
    }

    public function testAMissingIfMatchIsAValidationError(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $response = $this->sendJson('PATCH', '/v1/cards/'.self::CARD_ID, ['title' => 'DM'], $this->token);

        // 契约把 If-Match 写成 required 参数；缺一个必填参数一律 400
        // validation_failed（不是 428 —— 见 IfMatch 的类注释）。
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $problem = self::assertIsProblemDetails($response, ErrorCode::ValidationFailed);
        self::assertSame('If-Match', $problem['errors'][0]['field']);
    }

    /**
     * ⚠️ 任务卡逐字：非 owner **一律** `403 insufficient_role`，
     * **即使请求体合法、revision 正确**。
     *
     * 这条用例把 revision 传对、body 写对 —— 只有归属判断排在乐观锁之前才会绿。
     */
    public function testANonOwnerCannotPatchEvenWithACorrectRevision(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $other = $this->registerOnboardedUser();
        $response = $this->sendJson(
            'PATCH',
            '/v1/cards/'.self::CARD_ID,
            ['title' => 'meins jetzt'],
            $other['access_token'],
            ifMatch: '"1"',
        );

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $problem = self::assertIsProblemDetails($response, ErrorCode::InsufficientRole);
        // 403 里绝不能带服务端状态 —— 否则非成员能拿不同的 If-Match 试出 revision。
        self::assertArrayNotHasKey('current', $problem);

        // 卡也没被改。
        $unchanged = self::decode($this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $this->token));
        self::assertSame('REWE Payback', $unchanged['title']);
        self::assertSame(1, $unchanged['revision']);
    }

    // ========================================================================
    // DELETE /v1/cards/{id}
    // ========================================================================

    public function testDeletingSoftDeletesTheCard(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $response = $this->sendJson('DELETE', '/v1/cards/'.self::CARD_ID, null, $this->token);

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('', $response->getContent());

        // 查不到了……
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $this->token)->getStatusCode(),
        );
        self::assertSame([], self::decode($this->sendJson('GET', '/v1/cards', null, $this->token))['items']);

        // ……但那一行还在（T-201 的墓碑同步要靠它），所以 id 仍然算被占用：
        // 重放建卡得到 200 而不是主键冲突。
        $replay = $this->sendJson('POST', '/v1/cards', self::body(), $this->token);
        self::assertSame(Response::HTTP_OK, $replay->getStatusCode());
    }

    public function testANonOwnerCannotDelete(): void
    {
        $this->sendJson('POST', '/v1/cards', self::body(), $this->token);

        $other = $this->registerOnboardedUser();
        $response = $this->sendJson('DELETE', '/v1/cards/'.self::CARD_ID, null, $other['access_token']);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertIsProblemDetails($response, ErrorCode::InsufficientRole);
    }

    // ========================================================================
    // 帮手
    // ========================================================================

    /**
     * @param array<string, mixed>|null $body
     */
    private function sendJson(
        string $method,
        string $path,
        ?array $body = null,
        ?string $accessToken = null,
        ?string $ifMatch = null,
    ): Response {
        $server = [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            'REMOTE_ADDR' => self::uniqueIp(),
            'CONTENT_TYPE' => 'application/json',
        ];

        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$accessToken;
        }

        if (null !== $ifMatch) {
            $server['HTTP_IF_MATCH'] = $ifMatch;
        }

        $this->client->request(
            $method,
            $path,
            server: $server,
            content: null === $body ? '' : json_encode($body, \JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(
        string $id = self::CARD_ID,
        string $title = 'REWE Payback',
        ?string $note = null,
        string $barcodeValue = '4012345678901',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'merchant_label' => 'REWE',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => $barcodeValue,
            'note' => $note,
            'expires_on' => null,
        ];
    }

    /**
     * 经仓储直接种 `$count` 张卡**及其 owner 成员行**，只为把配额顶到某个存量。
     *
     * ⚠️ **不**走 `$count` 次 `POST /v1/cards`：每张卡两次加密 + 一次 HMAC，
     * 499 张就是一千多次 Vault 往返。被测的是第 500、501 次建卡，不是这些种子。
     * 同一条论证与形状见 {@see CardListPerformanceTest::seedCards()}。
     *
     * ⚠️ 密文与指纹**只算一次，所有行共用**。`Card.orm.xml` 里
     * `barcode_value_fingerprint` 没有唯一索引（同一张会员卡在多个用户手里
     * 本来就该有相同的指纹 —— §5.3 的重复检测靠的正是它），所以复用是合法的，
     * 而它把这条用例的 Vault 往返从一千多次降到 1 次。
     * 这些卡在本用例里从不被读，明文是什么无所谓。
     *
     * ⚠️ 成员行要在这里自己补：绕开 `CreateCardService` 就绕开了它那个事务，
     * 而「每张卡都有一行 owner 成员记录」是 T-110 之后的库内不变量。
     *
     * ⚠️ `persist()` 一千行、**只 `flush()` 一次**，不走
     * `CardRepositoryInterface::save()` —— 那个方法每次调用都 flush，
     * 于是 998 次种子就是 998 次往返（实测 5.8 秒，占整个 Api 套件的五分之一）。
     * 这里不需要它的乐观锁异常翻译：种子不会冲突。
     */
    private function seedCards(Uuid $owner, int $count): void
    {
        $container = static::getContainer();

        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);

        $now = new \DateTimeImmutable('2026-09-08T12:00:00+00:00');
        $encrypted = $crypto->encrypt(CryptoKey::Card, 'kontingent');
        $fingerprint = HashDigest::fromRaw($hasher->hash('kontingent'));

        for ($i = 0; $i < $count; ++$i) {
            // ⚠️ 前缀避开 self::CARD_ID —— 撞上的话第 500 张会走成幂等重放（200），
            // 而这条用例要的是一次真的 201。
            $cardId = Uuid::fromString(\sprintf('0192f3a1-b2c3-7d4e-8f01-0000%08x', $i));

            $this->entityManager->persist(Card::create(
                $cardId,
                $owner,
                'Karte '.$i,
                'REWE',
                'blue_600',
                BarcodeFormat::Ean13,
                $encrypted,
                $fingerprint,
                null,
                null,
                $now,
            ));

            $this->entityManager->persist(CardMember::owner($cardId, $owner, $now));
        }

        $this->entityManager->flush();
        // 后面那两次 POST 要走真实的读路径，别让它们读到 UnitOfWork 里的对象。
        $this->entityManager->clear();
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
