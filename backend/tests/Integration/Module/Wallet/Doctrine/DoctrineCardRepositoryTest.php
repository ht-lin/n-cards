<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Wallet\Doctrine;

use App\Module\Wallet\Domain\Entity\Card;
use App\Module\Wallet\Domain\ValueObject\BarcodeFormat;
use App\Module\Wallet\Domain\ValueObject\EncryptionScheme;
use App\Module\Wallet\Infrastructure\Doctrine\DoctrineCardRepository;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;
use App\Tests\Double\Wallet\WalletEntities;
use App\Tests\Integration\Support\RequiresWalletSchema;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `cards` 的读写在**真 Postgres** 上的行为。
 *
 * ⚠️ 仓储直接 `new`，不从容器取 —— 它没有别的引用者，
 * `RemoveUnusedDefinitionsPass` 会把它从测试容器里删掉。
 * 口径同 `DoctrineUserRepositoryTest`。
 *
 * ⚠️ 每次写完都 `$em->clear()`：不清的话读到的是 UnitOfWork 里那个**同一个**
 * PHP 对象，于是自定义 DBAL 类型（uuid / ciphertext / hash_digest）的
 * 往返根本没被执行，而那正是这个文件要验的东西。
 */
#[CoversClass(DoctrineCardRepository::class)]
final class DoctrineCardRepositoryTest extends KernelTestCase
{
    use RequiresWalletSchema;

    private DoctrineCardRepository $cards;

    private Uuid $owner;

    protected function setUp(): void
    {
        $this->bootWalletSchema();

        $this->cards = new DoctrineCardRepository($this->entityManager);
        $this->owner = $this->seedOwner();
    }

    protected function tearDown(): void
    {
        $this->rollbackWalletSchema();

        parent::tearDown();
    }

    public function testEveryCustomTypeSurvivesARoundTrip(): void
    {
        $card = $this->card(note: 'Rückseite abgenutzt', expiresOn: new \DateTimeImmutable('2028-12-31'));

        $this->cards->save($card);
        $this->entityManager->clear();

        $loaded = $this->cards->find($card->id());

        self::assertInstanceOf(Card::class, $loaded);
        // uuid ↔ UUID
        self::assertTrue($loaded->id()->equals($card->id()));
        self::assertTrue($loaded->ownerId()->equals($this->owner));
        // ciphertext ↔ TEXT
        self::assertTrue($loaded->barcodeValueEncrypted()->equals($card->barcodeValueEncrypted()));
        self::assertTrue($loaded->noteEncrypted()?->equals($card->noteEncrypted() ?? $loaded->barcodeValueEncrypted()));
        // hash_digest ↔ BYTEA —— 裸 32 字节，不是 hex
        self::assertTrue($loaded->barcodeValueFingerprint()?->equals($card->barcodeValueFingerprint() ?? WalletEntities::digest('x')));
        // enum-type ↔ TEXT
        self::assertSame(BarcodeFormat::Ean13, $loaded->barcodeFormat());
        // ⚠️ 比的是**库里那一列**，不是 $loaded->encryptionScheme()。
        // 后者对静态分析恒真（一期只有一个 case），而这里真正要证的是
        // enum-type 映射把它写成了 TEXT 的 `server_v1` —— 写错的话
        // 二期 E2EE 上线时按 scheme 分流的逻辑会挑错整批行。
        $rawScheme = $this->connection->fetchOne(
            'SELECT encryption_scheme FROM cards WHERE id = :id',
            ['id' => $card->id()->toBytes()],
            ['id' => ParameterType::BINARY],
        );
        self::assertSame(EncryptionScheme::default()->value, $rawScheme);
        // date_immutable ↔ DATE
        self::assertSame('2028-12-31', $loaded->expiresOn()?->format('Y-m-d'));
    }

    /**
     * ⚠️ `revision` 的初值来自库里的 `DEFAULT 1`，不是 PHP 那个属性初始化。
     *
     * Doctrine 把 version 字段从 INSERT 里跳过，然后 SELECT 回来。
     * 这条用例证明那条链真的接上了 —— 迁移里漏掉 DEFAULT 的话它会以
     * NOT NULL 违约的形式红。
     */
    public function testANewCardLandsAtRevisionOne(): void
    {
        $card = $this->card();

        $this->cards->save($card);
        $this->entityManager->clear();

        self::assertSame(1, $this->cards->find($card->id())?->revision());
    }

    public function testDoctrineBumpsTheRevisionOnEveryUpdate(): void
    {
        $card = $this->card();
        $this->cards->save($card);

        $card->rename('DM', WalletEntities::now('2026-09-09T08:00:00+00:00'));
        $this->cards->saveWithRevision($card, 1);
        $this->entityManager->clear();

        self::assertSame(2, $this->cards->find($card->id())?->revision());
    }

    public function testAStaleIfMatchIsARevisionConflict(): void
    {
        $card = $this->card();
        $this->cards->save($card);

        $card->rename('DM', WalletEntities::now('2026-09-09T08:00:00+00:00'));

        try {
            // 客户端手里是 7，库里是 1。
            $this->cards->saveWithRevision($card, 7);
            self::fail('期待 revision_conflict。');
        } catch (DomainException $e) {
            self::assertSame(ErrorCode::RevisionConflict, $e->errorCode());
            self::assertSame(1, $e->current()['revision']);
        }
    }

    /**
     * ⚠️ 第二层保护：载入时 revision 是对的，但 flush 之前有人抢先改了。
     *
     * 造法是绕过 ORM 直接 UPDATE 一行（模拟另一个请求），Doctrine 自动加的
     * `WHERE revision = :old` 会命中 0 行。这一层是并发下唯一有用的那层 ——
     * 手写 `if ($card->revision() !== $expected)` 挡不住它。
     */
    public function testAConcurrentWriteBetweenLoadAndFlushIsAlsoAConflict(): void
    {
        $card = $this->card();
        $this->cards->save($card);
        $this->entityManager->clear();

        $loaded = $this->cards->find($card->id());
        self::assertInstanceOf(Card::class, $loaded);

        // 「另一个请求」抢先改了这一行。
        $this->connection->executeStatement(
            'UPDATE cards SET revision = revision + 1, title = :t WHERE id = :id',
            ['t' => 'jemand anderes war schneller', 'id' => $card->id()->toBytes()],
            ['id' => ParameterType::BINARY],
        );

        $loaded->rename('DM', WalletEntities::now('2026-09-09T08:00:00+00:00'));

        $this->expectException(DomainException::class);

        // 载入时 revision 是 1，If-Match 也是 1 —— 第一层放行，第二层拦住。
        $this->cards->saveWithRevision($loaded, 1);
    }

    public function testSoftDeletedCardsAreInvisibleToEveryQueryButOne(): void
    {
        $card = $this->card();
        $this->cards->save($card);

        $card->softDelete(WalletEntities::now());
        $this->cards->save($card);
        $this->entityManager->clear();

        self::assertNull($this->cards->find($card->id()));
        self::assertSame([], $this->cards->findOwnedPage($this->owner, null, 51));
        self::assertSame(0, $this->cards->countOwnedBy($this->owner));

        // 唯一的例外 —— POST /v1/cards 的幂等判定要靠它，否则重放会撞主键冲突。
        self::assertInstanceOf(Card::class, $this->cards->findIncludingDeleted($card->id()));
    }

    public function testTheListIsKeysetPaginatedByIdAscending(): void
    {
        $ids = [];

        for ($i = 1; $i <= 5; ++$i) {
            $card = $this->card(id: WalletEntities::id($i));
            $this->cards->save($card);
            $ids[] = $card->id()->toString();
        }

        $this->entityManager->clear();

        // 多取一行 —— has_more 的判据（PageRequest::fetchLimit()）。
        $first = $this->cards->findOwnedPage($this->owner, null, 3);
        self::assertCount(3, $first);
        self::assertSame(\array_slice($ids, 0, 3), array_map(static fn (Card $c): string => $c->id()->toString(), $first));

        // 游标取自**保留下来的最后一行**，且比较是严格大于。
        $second = $this->cards->findOwnedPage($this->owner, Uuid::fromString($ids[1]), 3);
        self::assertSame(\array_slice($ids, 2, 3), array_map(static fn (Card $c): string => $c->id()->toString(), $second));
    }

    public function testTheListAndCountAreScopedToTheOwner(): void
    {
        $otherOwner = $this->seedOwner();

        $this->cards->save($this->card(id: WalletEntities::id(1)));
        $this->cards->save($this->card(id: WalletEntities::id(2), ownerId: $otherOwner));
        $this->entityManager->clear();

        self::assertCount(1, $this->cards->findOwnedPage($this->owner, null, 51));
        self::assertSame(1, $this->cards->countOwnedBy($this->owner));
        self::assertSame(1, $this->cards->countOwnedBy($otherOwner));
    }

    /**
     * ⚠️ 库层的 CHECK 是第二道防线，不是第一道。
     *
     * 应用层（`LimitEnforcer`）挡住 101 个字符并返回 `422 limit_exceeded`；
     * 绕过它直接写库的话，PG 会拒。这条用例证明那道防线真的在
     * —— 它同时也是「§17.1 的 CHECK 没被迁移漏掉」的行为版断言。
     */
    public function testTheDatabaseStillRefusesAnOverlongTitle(): void
    {
        $card = $this->card(title: str_repeat('a', 101));

        $this->expectException(\Doctrine\DBAL\Exception::class);

        $this->cards->save($card);
    }

    private function card(
        ?Uuid $id = null,
        ?Uuid $ownerId = null,
        string $title = 'REWE Payback',
        ?string $note = null,
        ?\DateTimeImmutable $expiresOn = null,
    ): Card {
        return WalletEntities::card(
            id: $id ?? WalletEntities::id(1),
            ownerId: $ownerId ?? $this->owner,
            title: $title,
            note: $note,
            expiresOn: $expiresOn,
        );
    }
}
