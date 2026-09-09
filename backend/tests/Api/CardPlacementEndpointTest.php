<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Sharing\Domain\Repository\CardMemberRepositoryInterface;
use App\Module\Wallet\Http\CardController;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Time\ClockInterface;
use App\Tests\Api\Support\OpenApiContract;
use App\Tests\Api\Support\ProblemDetailsAssertions;
use App\Tests\Api\Support\RequiresOtpStack;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * `PUT /v1/cards/{cardId}/placement` 的端到端行为 + 契约断言（T-110）。
 *
 * ============================================================================
 * 这个文件覆盖 T-110 验收标准的第二条
 * ============================================================================
 * 任务卡：「集成测试断言：尝试插入第二行 owner 被索引拒绝；**两个成员各自的
 * placement 互不影响**。」第一条在 `SharingSchemaTest`（那是库层的事），
 * 第二条在这里 —— 它要的是两个**真实用户**经 HTTP 各改各的，
 * 而不是两个对象各持一份状态。
 *
 * ⚠️ M1 还没有邀请端点（T-304），所以第二个成员的 viewer 行是经仓储直接种的。
 * 这不是取巧：本端点的鉴权判据就是「有没有活跃成员行」，
 * 那一行怎么来的对它没有区别。
 */
#[CoversClass(CardController::class)]
final class CardPlacementEndpointTest extends WebTestCase
{
    use OpenApiContract;
    use ProblemDetailsAssertions;
    use RequiresOtpStack;

    private const CARD_ID = '0192f3a1-b2c3-7d4e-8f01-23456789abcd';
    private const PATH = '/v1/cards/'.self::CARD_ID.'/placement';

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
    // 正常路径
    // ========================================================================

    public function testPlacingACardReturnsTheUpdatedCard(): void
    {
        $this->createCard();

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 5, 'is_pinned' => true], $this->token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertSame(5, $body['sort_order']);
        self::assertTrue($body['is_pinned']);
        self::assertSame(self::CARD_ID, $body['id']);
        self::assertSame('owner', $body['my_role']);
        self::assertTrue($body['can_edit']);

        self::assertResponseMatchesContract('put', '/v1/cards/{cardId}/placement', $response);
    }

    /**
     * ⚠️ 契约逐字：placement「**不会**递增卡的 `revision`」。
     *
     * 客户端的乐观锁靠它 —— 一个会动 revision 的 placement 会让每次拖拽排序
     * 都使所有其他设备手里的 `If-Match` 失效，于是 §5.4.3 的冲突解决被
     * 一个纯本地的操作反复触发。
     */
    public function testPlacementDoesNotBumpTheRevision(): void
    {
        $created = self::decode($this->createCard());

        $placed = self::decode($this->sendJson(
            'PUT',
            self::PATH,
            ['sort_order' => 9, 'is_pinned' => true],
            $this->token,
        ));

        self::assertSame($created['revision'], $placed['revision']);

        // 再从 GET 读一次 —— 确认库里那张卡也没动。
        $fetched = self::decode($this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $this->token));

        self::assertSame($created['revision'], $fetched['revision']);
        self::assertSame($created['updated_at'], $fetched['updated_at']);
    }

    /** 契约的参数表里没有 `If-Match`，所以不带它必须是 200 而不是 400。 */
    public function testNoIfMatchHeaderIsRequired(): void
    {
        $this->createCard();

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => false], $this->token);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testTheNewPlacementIsVisibleOnTheListEndpoint(): void
    {
        $this->createCard();

        $this->sendJson('PUT', self::PATH, ['sort_order' => 7, 'is_pinned' => true], $this->token);

        $list = self::decode($this->sendJson('GET', '/v1/cards', null, $this->token));

        self::assertSame(7, $list['items'][0]['sort_order']);
        self::assertTrue($list['items'][0]['is_pinned']);
    }

    public function testANewCardStartsAtZeroAndUnpinned(): void
    {
        // 建卡那一行 card_members 用的是列默认值（§17.1）。
        $body = self::decode($this->createCard());

        self::assertSame(0, $body['sort_order']);
        self::assertFalse($body['is_pinned']);
    }

    // ========================================================================
    // ⚠️ T-110 验收标准第二条：两个成员各自的 placement 互不影响
    // ========================================================================

    public function testTwoMembersPlaceTheSameCardIndependently(): void
    {
        $this->createCard();

        // 第二个真实账号 + 一行 viewer 成员记录（M1 还没有邀请端点）。
        $viewer = $this->registerOnboardedUser();
        $this->seedViewer(Uuid::fromString($viewer['user_id']));

        $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => true], $this->token);

        // viewer 后写。它的 200 响应体就是**调用者视角**的那张卡（契约如此），
        // 所以这里读它而不是再打一次 GET —— 见下面那条用例，M1 阶段 viewer
        // 的 `GET /v1/cards/{id}` 还是 403。
        $viewerView = self::decode(
            $this->sendJson('PUT', self::PATH, ['sort_order' => 99, 'is_pinned' => false], $viewer['access_token']),
        );

        self::assertSame(99, $viewerView['sort_order']);
        self::assertFalse($viewerView['is_pinned']);

        // owner 再读一次 —— 他的值必须**没被覆盖**。这是本条用例的重点：
        // 两行 card_members，两个人各改各的。
        $ownerView = self::decode($this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $this->token));

        self::assertSame(1, $ownerView['sort_order'], 'owner 的排序被 viewer 的写入覆盖了 —— placement 不是每成员私有的。');
        self::assertTrue($ownerView['is_pinned']);
    }

    /**
     * ⚠️ **已知缺口，钉在这里免得它被当成 bug 修错地方。**.
     *
     * T-110 之后，**角色**来自 `card_members`（assembler），而 `GET` 的
     * **可见性**仍来自 `Card::isOwnedBy()`（`CardQueryService::get()`）——
     * 两个真相来源，M1 里恰好一致只因为没有 viewer。
     *
     * 于是出现这个不对称：viewer **能** `PUT …/placement`（上面那条用例），
     * 却**不能** `GET` 同一张卡。T-110 刻意不改可见性（那要跨模块分页查询，
     * §4.2 规则 5 不许 JOIN），修法见 `CardQueryService` 的类注释：
     * T-305 把检查**挪到**成员表上，而不是再加一次查询。
     *
     * 这条用例现在断言的是「已知的错」。T-305 落地时它会红 —— **那时候
     * 应该改的是这条用例**（改成断言 200），不是回头去改 placement。
     */
    public function testAViewerStillCannotGetTheCardUntilT305(): void
    {
        $this->createCard();

        $viewer = $this->registerOnboardedUser();
        $this->seedViewer(Uuid::fromString($viewer['user_id']));

        $response = $this->sendJson('GET', '/v1/cards/'.self::CARD_ID, null, $viewer['access_token']);

        $this->assertIsProblemDetails($response, ErrorCode::NotAMember);
    }

    /**
     * ⚠️ **这是 viewer 唯一的上行写入端点**（§5.2 的角色矩阵）。
     *
     * 拿 `insufficient_role` 挡住他是本卡最容易犯的错 —— 那会让 viewer
     * 连自己钱包里卡的位置都摆不了，而那是他仅有的两项权利之一。
     */
    public function testAViewerMayPlaceASharedCard(): void
    {
        $this->createCard();

        $viewer = $this->registerOnboardedUser();
        $this->seedViewer(Uuid::fromString($viewer['user_id']));

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 2, 'is_pinned' => true], $viewer['access_token']);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);

        self::assertSame('viewer', $body['my_role']);
        // 摆放位置可以改，卡的内容不行 —— 客户端用 can_edit gate 编辑 UI。
        self::assertFalse($body['can_edit']);
        self::assertSame(2, $body['sort_order']);
    }

    /** viewer 立了墓碑之后就不再是成员 —— §7.2 的 T20（权限残留）。 */
    public function testAViewerWhoLeftCanNoLongerPlaceTheCard(): void
    {
        $this->createCard();

        $viewer = $this->registerOnboardedUser();
        $viewerId = Uuid::fromString($viewer['user_id']);
        $this->seedViewer($viewerId);

        $this->connection()->executeStatement(
            'UPDATE card_members SET left_at = now() WHERE card_id = ? AND user_id = ?',
            [self::CARD_ID, $viewerId->toString()],
        );

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => true], $viewer['access_token']);

        $this->assertIsProblemDetails($response, ErrorCode::NotAMember);
    }

    // ========================================================================
    // 错误路径
    // ========================================================================

    public function testANonMemberGetsNotAMember(): void
    {
        $this->createCard();

        $stranger = $this->registerOnboardedUser();

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => true], $stranger['access_token']);

        // ⚠️ not_a_member，不是 insufficient_role：契约给前者的语义是
        // 「客户端应据此从本地删除该卡」，那正是共享被撤销时该做的事。
        $this->assertIsProblemDetails($response, ErrorCode::NotAMember);
    }

    public function testAnUnknownCardIsNotFound(): void
    {
        $response = $this->sendJson(
            'PUT',
            '/v1/cards/0192f3a1-b2c3-7d4e-8f01-ffffffffffff/placement',
            ['sort_order' => 1, 'is_pinned' => true],
            $this->token,
        );

        $this->assertIsProblemDetails($response, ErrorCode::NotFound);
    }

    /** 畸形的路径参数是 404 而不是 422 —— 口径同其余五个端点。 */
    public function testAMalformedCardIdIsNotFound(): void
    {
        $response = $this->sendJson(
            'PUT',
            '/v1/cards/not-a-uuid/placement',
            ['sort_order' => 1, 'is_pinned' => true],
            $this->token,
        );

        $this->assertIsProblemDetails($response, ErrorCode::NotFound);
    }

    /**
     * 软删的卡是 404。
     *
     * ⚠️ 成员行**还在**（T-110 的迁移刻意给软删的卡也回填了，§5.2 的墓碑
     * audience 需要它），所以挡住这一条的只有「先判卡、再判成员」那个顺序。
     */
    public function testASoftDeletedCardIsNotFound(): void
    {
        $this->createCard();
        $this->sendJson('DELETE', '/v1/cards/'.self::CARD_ID, null, $this->token);

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => true], $this->token);

        $this->assertIsProblemDetails($response, ErrorCode::NotFound);
    }

    public function testAMissingFieldIsRejected(): void
    {
        $this->createCard();

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1], $this->token);

        $problem = $this->assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertSame('is_pinned', $problem['errors'][0]['field']);
        self::assertSame('required', $problem['errors'][0]['code']);
    }

    /**
     * ⚠️ `card_members.sort_order` 是 PG 的 `INTEGER`。没有那道范围校验的话，
     * 这个请求会一路走到 flush 然后变成一个 **500**。
     */
    public function testAnOversizedSortOrderIsA400NotA500(): void
    {
        $this->createCard();

        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => \PHP_INT_MAX, 'is_pinned' => false], $this->token);

        $problem = $this->assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertSame('sort_order', $problem['errors'][0]['field']);
        self::assertSame('out_of_range', $problem['errors'][0]['code']);
    }

    /**
     * 卡的内容字段走 `PATCH`（有 `If-Match` 保护），不走这里 ——
     * 收下它就是开了第二个不带乐观锁的改卡入口。
     */
    public function testCardContentFieldsAreRejected(): void
    {
        $this->createCard();

        $response = $this->sendJson(
            'PUT',
            self::PATH,
            ['sort_order' => 1, 'is_pinned' => false, 'title' => '偷偷改标题'],
            $this->token,
        );

        $problem = $this->assertIsProblemDetails($response, ErrorCode::ValidationFailed);

        self::assertSame('title', $problem['errors'][0]['field']);
        self::assertSame('unknown_field', $problem['errors'][0]['code']);
    }

    public function testAnAnonymousRequestIsUnauthorized(): void
    {
        $response = $this->sendJson('PUT', self::PATH, ['sort_order' => 1, 'is_pinned' => true]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    // ========================================================================
    // 装配
    // ========================================================================

    private function createCard(): Response
    {
        return $this->sendJson('POST', '/v1/cards', [
            'id' => self::CARD_ID,
            'title' => 'REWE Payback',
            'merchant_label' => 'REWE',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
            'note' => null,
            'expires_on' => null,
        ], $this->token);
    }

    /**
     * 直接种一行 viewer 成员记录。
     *
     * M1 没有邀请端点（T-304 才有），而本端点的鉴权判据只是「有没有活跃成员
     * 行」—— 那一行怎么来的对它没有区别。
     */
    private function seedViewer(Uuid $viewerId): void
    {
        $container = static::getContainer();

        /** @var CardMemberRepositoryInterface $members */
        $members = $container->get(CardMemberRepositoryInterface::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);

        $members->save(CardMember::viewer(
            Uuid::fromString(self::CARD_ID),
            $viewerId,
            Uuid::fromString($this->userId),
            $clock->now(),
        ));
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);

        return $connection;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function sendJson(
        string $method,
        string $path,
        ?array $body = null,
        ?string $accessToken = null,
    ): Response {
        $server = [
            'HTTP_X_CLIENT' => 'android/1.4.0 (26)',
            'REMOTE_ADDR' => self::uniqueIp(),
            'CONTENT_TYPE' => 'application/json',
        ];

        if (null !== $accessToken) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$accessToken;
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
    private static function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true, 32, \JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);

        return $decoded;
    }
}
