<?php

declare(strict_types=1);

namespace App\Module\Wallet\Http;

use App\Module\Wallet\Application\Card\CardCreatePayload;
use App\Module\Wallet\Application\Card\CardPlacementPayload;
use App\Module\Wallet\Application\Card\CardQueryService;
use App\Module\Wallet\Application\Card\CardUpdatePayload;
use App\Module\Wallet\Application\Card\CardView;
use App\Module\Wallet\Application\Card\CreateCardService;
use App\Module\Wallet\Application\Card\DeleteCardService;
use App\Module\Wallet\Application\Card\UpdateCardPlacementService;
use App\Module\Wallet\Application\Card\UpdateCardService;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Pagination\Cursor;
use App\Shared\Http\Concurrency\IfMatch;
use App\Shared\Http\Controller\AbstractApiController;
use App\Shared\Http\Pagination\CursorPaginator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `/v1/cards` 的六个端点（§6.2，T-109 的五个 + T-110 的 placement）。
 *
 * 一个控制器六条路由，与 {@see \App\Module\Identity\Http\DeviceController}
 * 的三条同一个做法：它们是同一个资源的 CRUD，拆成六个文件只会让
 * 「路径参数怎么解析」「响应体怎么组装」各出现六份。
 *
 * ============================================================================
 * ⚠️ placement 改的是 Sharing 的表，为什么控制器还在 Wallet
 * ============================================================================
 * `PUT /v1/cards/{cardId}/placement` 写的是 `card_members`，而那张表属
 * **Sharing** 模块（§4.2）。直觉是把控制器也放过去，但那要付两次复制的代价，
 * 两次都是本仓库明文在拦的：
 *
 *   - `Sharing.Http` 的 deptrac 允许列表只有 `+_Ports`（各模块的 `.Port` 与
 *     `.Dto`），**看不见** `Wallet.Application` —— 于是要克隆一个装**明文码值**
 *     的 `CardView`，而它「只能由 `CardViewAssembler` 构造」这条不变量
 *     （＝「谁解的密只有一个答案」）当场作废。
 *   - 同样看不见 `Wallet.Http`，于是要克隆 {@see CardBody} —— 而那个类的注释
 *     写着它存在的全部理由就是「组装代码有第二份的话，加字段时必然漏一处，
 *     且漏掉的那个端点的契约测试照样是绿的」。T-110 正是给 `Card` 加两个字段
 *     的那次提交。
 *
 * 模块级还有一个真环（Wallet 要 Sharing 的角色，Sharing 要 Wallet 的渲染），
 * 而 deptrac 两条边都放行且**不做环检测** —— CI 抓不到它。
 *
 * 放在这里则只有两条边、单向：`Wallet.Http → Wallet.Application`、
 * `Wallet.Application → Sharing.Port`。「写的是别人的表」由那个端口满足，
 * Wallet 从头到尾不知道 `card_members` 存在。
 *
 * ============================================================================
 * 这五条路由自动进三张门禁表，不需要登记
 * ============================================================================
 *   - `OnboardingCoverageTest`：新 `/v1` 路由自动进数据提供者，会被要求对
 *     onboarding 未完成的用户返 `403 username_required`。**这正是要的** ——
 *     一个还没有 username 的用户没有任何理由能建卡。所以
 *     `OnboardingListener::EXEMPT_ROUTES` 与 `AuthenticationListener::PUBLIC_ROUTES`
 *     都**不要**动。
 *   - `AuthenticationCoverageTest`：拿 `PUBLIC_ROUTES` 与契约的 `security: []`
 *     对账。契约里 cards 全都要鉴权，所以同样不用动。
 *   - `OpenApiDocumentTest::testEveryProductApiRouteIsDeclaredInTheContract()`：
 *     路由名与路径必须在 `docs/api/openapi.yaml` 里找得到。
 *
 * ============================================================================
 * ⚠️ 路径参数解析失败是 404，不是 422
 * ============================================================================
 * 与 `DeviceController::deviceId()` 逐字相同的理由：`{cardId}` 不是请求体里的
 * 一个字段，它是**路径的一部分**。一个格式非法的 id 指向的资源不存在，
 * 而告诉客户端「你的 id 格式不对」与告诉它「没有这张卡」在信息量上没有差别 ——
 * 两种情况下它都拿不到那张卡。统一成 404 也省掉了一处「格式对但不存在」
 * 与「格式就不对」的分支。
 */
final class CardController extends AbstractApiController
{
    /** 游标载荷的版本号。形状与契约里那个示例游标解出来的一致。 */
    private const CURSOR_VERSION = 1;

    public function __construct(
        private readonly CardQueryService $query,
        private readonly CreateCardService $creator,
        private readonly UpdateCardService $updater,
        private readonly DeleteCardService $deleter,
        private readonly UpdateCardPlacementService $placement,
        private readonly CursorPaginator $paginator,
    ) {
    }

    /**
     * `GET /v1/cards` —— 全量列表（首次登录用），游标分页。
     */
    #[Route('/v1/cards', name: 'cards_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        // offset 风格的参数、非法 limit、坏游标都在这一步变成 400。
        $pageRequest = $this->paginator->pageRequest($request);

        $cards = $this->query->page(
            $this->authContext($request),
            self::after($pageRequest->cursor),
            // 多取一行 —— has_more 的判据，见 PageRequest::fetchLimit()。
            $pageRequest->fetchLimit(),
        );

        $page = $this->paginator->paginate(
            $cards,
            $pageRequest,
            static fn (CardView $card): array => ['v' => self::CURSOR_VERSION, 'after' => $card->id->toString()],
        );

        // Page<CardView> → Page<array>：信封本身由 Page::jsonSerialize() 组，
        // 这里只把每一项换成契约的形状。
        return $this->json([
            'items' => array_map(CardBody::of(...), $page->items),
            'next_cursor' => null === $page->nextCursor ? null : (string) $page->nextCursor,
            'has_more' => $page->hasMore,
        ]);
    }

    /**
     * `POST /v1/cards` —— 建卡。
     *
     * 两个成功码（§5.4.3）：`201` 真新建、`200` 该 id 已属于调用者。
     * 判据来自 {@see \App\Module\Wallet\Application\Card\CardCreated::$wasCreated}
     * —— 控制器自己是分辨不出来的，理由见那个 DTO 的类注释。
     */
    #[Route('/v1/cards', name: 'cards_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = CardCreatePayload::fromArray($this->decodeBody($request));

        $result = $this->creator->create($this->authContext($request), $payload);

        $body = CardBody::of($result->card);

        return $result->wasCreated
            ? $this->created($body, '/v1/cards/'.$result->card->id->toString())
            : $this->json($body);
    }

    #[Route('/v1/cards/{cardId}', name: 'cards_get', methods: ['GET'])]
    public function get(Request $request, string $cardId): JsonResponse
    {
        $card = $this->query->get($this->authContext($request), self::cardId($cardId));

        return $this->json(CardBody::of($card));
    }

    /**
     * `PATCH /v1/cards/{cardId}` —— 乐观锁下的部分更新。
     *
     * ⚠️ 这里的调用顺序看起来无关紧要，其实不是：`If-Match` 与请求体的解析
     * 都**不查库**，所以它们谁先谁后只影响一个畸形请求收到哪种 400。
     * 而「非 owner 一律 403」那条必须早于 revision 比较 —— 那一步在服务层，
     * 见 {@see UpdateCardService} 的类注释。
     */
    #[Route('/v1/cards/{cardId}', name: 'cards_update', methods: ['PATCH'])]
    public function update(Request $request, string $cardId): JsonResponse
    {
        $expectedRevision = IfMatch::revision($request);
        $payload = CardUpdatePayload::fromArray($this->decodeBody($request));

        $card = $this->updater->update(
            $this->authContext($request),
            self::cardId($cardId),
            $payload,
            $expectedRevision,
        );

        return $this->json(CardBody::of($card));
    }

    /**
     * `DELETE /v1/cards/{cardId}` —— 软删，`204` 无响应体。
     *
     * 没有 `If-Match`（契约的参数表里就没有）—— 理由见
     * {@see DeleteCardService} 的类注释。
     */
    #[Route('/v1/cards/{cardId}', name: 'cards_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $cardId): Response
    {
        $this->deleter->delete($this->authContext($request), self::cardId($cardId));

        return $this->noContent();
    }

    /**
     * `PUT /v1/cards/{cardId}/placement` —— 调用者自己对这张卡的排序与置顶。
     *
     * ⚠️ **没有** `IfMatch::revision()`：契约的参数表里没有 `If-Match`，
     * 而且这个端点不会递增卡的 `revision` —— 它改的是 `card_members`，
     * 不是这张卡。理由见 {@see UpdateCardPlacementService} 的类注释。
     *
     * ⚠️ owner 与 viewer **都能**调用（这是 viewer 唯一的上行写入端点），
     * 所以这里也没有任何角色判断 —— 鉴权判据是「有没有活跃成员行」，
     * 由服务层那次写内联完成。
     */
    #[Route('/v1/cards/{cardId}/placement', name: 'cards_placement_update', methods: ['PUT'])]
    public function updatePlacement(Request $request, string $cardId): JsonResponse
    {
        $payload = CardPlacementPayload::fromArray($this->decodeBody($request));

        $card = $this->placement->update(
            $this->authContext($request),
            self::cardId($cardId),
            $payload,
        );

        return $this->json(CardBody::of($card));
    }

    /**
     * 游标载荷里的位置。
     *
     * ⚠️ 载荷是客户端可控的（游标**不签名**，见 {@see Cursor} 的类注释 ——
     * 那里论证了为什么不签是安全的：服务端每次查询都重新施加归属过滤）。
     * 所以这里要像对待任何请求参数一样校验它，不能直接 `(string)` 丢给仓储。
     *
     * @throws DomainException `validation_failed`（400）
     */
    private static function after(?Cursor $cursor): ?Uuid
    {
        if (null === $cursor) {
            return null;
        }

        $raw = $cursor->payload()['after'] ?? null;
        $after = \is_string($raw) ? Uuid::tryFromString($raw) : null;

        if (null === $after) {
            // 复用 Cursor 自己那句「不透明，不解释哪里不对」的文案口径：
            // 把载荷结构讲给客户端听只会诱导它去构造游标。
            throw DomainException::validationFailed(new FieldError('cursor', FieldErrorCode::InvalidFormat, 'Cursor is not a valid pagination cursor.'));
        }

        return $after;
    }

    /**
     * @throws DomainException `not_found`（404）—— 理由见类注释
     */
    private static function cardId(string $raw): Uuid
    {
        $id = Uuid::tryFromString($raw);

        if (null === $id) {
            throw DomainException::notFound('No such card.');
        }

        return $id;
    }
}
