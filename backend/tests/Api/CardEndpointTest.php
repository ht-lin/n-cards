<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Module\Wallet\Http\CardController;
use App\Shared\Domain\Error\ErrorCode;
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

        // ⚠️ T-109 刻意**不发**这四个 —— 它们来自 card_members（T-110）与 Identity。
        // 发常量占位的话，客户端会把 sort_order: 0 当成用户真实的排序存进本地库。
        self::assertArrayNotHasKey('sort_order', $body);
        self::assertArrayNotHasKey('is_pinned', $body);
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
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'merchant_label' => 'REWE',
            'color' => 'blue_600',
            'barcode_format' => 'EAN_13',
            'barcode_value' => '4012345678901',
            'note' => $note,
            'expires_on' => null,
        ];
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
