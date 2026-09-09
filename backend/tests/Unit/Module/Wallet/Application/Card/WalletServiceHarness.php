<?php

declare(strict_types=1);

namespace App\Tests\Unit\Module\Wallet\Application\Card;

use App\Module\Sharing\Application\Membership\CardMembershipService;
use App\Module\Sharing\Domain\Entity\CardMember;
use App\Module\Wallet\Application\Card\CardSecrets;
use App\Module\Wallet\Application\Card\CardViewAssembler;
use App\Module\Wallet\Application\Card\CreateCardService;
use App\Module\Wallet\Application\Card\DeleteCardService;
use App\Module\Wallet\Application\Card\UpdateCardPlacementService;
use App\Module\Wallet\Application\Card\UpdateCardService;
use App\Module\Wallet\Domain\Entity\Card;
use App\Shared\Domain\Http\AuthContext;
use App\Shared\Domain\Identity\Uuid;
use App\Shared\Domain\Limit\LimitEnforcer;
use App\Tests\Double\Crypto\InMemoryBatchDecryptor;
use App\Tests\Double\Crypto\InMemoryCryptoService;
use App\Tests\Double\Crypto\RecordingHmacHasher;
use App\Tests\Double\Sharing\InMemoryCardMemberRepository;
use App\Tests\Double\Time\FrozenClock;
use App\Tests\Double\Transaction\RecordingTransactionRunner;
use App\Tests\Double\Wallet\InMemoryCardRepository;
use App\Tests\Double\Wallet\WalletEntities;

/**
 * Wallet 四个服务共用的装配。
 *
 * 每条用例都要拼同一堆替身（仓储 + 两个 crypto + 限额 + 时钟），
 * 各写一份的话「限额用的是不是真值」这种事会在四个文件里各答一次。
 *
 * ⚠️ {@see limits()} 用的是 `config/packages/ncards_limits.yaml` 里的**真值**，
 * 不是随手编的小数字 —— 口径同 `LimitEnforcerTest`：
 * 编一个「上限 3」的限额能让边界用例好写，但它证明不了 500 那一条真的接上了。
 */
final class WalletServiceHarness
{
    public InMemoryCardRepository $cards;

    public InMemoryCryptoService $crypto;

    public InMemoryBatchDecryptor $decryptor;

    public FrozenClock $clock;

    public InMemoryCardMemberRepository $members;

    public RecordingTransactionRunner $transactions;

    /**
     * T-110。构造器给每张传进来的卡自动补一行 owner 成员记录 ——
     * 见 {@see seedOwnerRows()}。
     */
    public function __construct(Card ...$cards)
    {
        $this->cards = new InMemoryCardRepository(...$cards);
        $this->crypto = new InMemoryCryptoService();
        $this->decryptor = new InMemoryBatchDecryptor();
        $this->members = new InMemoryCardMemberRepository();
        $this->transactions = new RecordingTransactionRunner();
        // FrozenClock 收的是毫秒，不是 DateTimeImmutable。
        $this->clock = new FrozenClock(WalletEntities::now()->getTimestamp() * 1000);

        $this->seedOwnerRows(...$cards);
    }

    public function creator(): CreateCardService
    {
        return new CreateCardService(
            $this->cards,
            $this->secrets(),
            $this->assembler(),
            self::limits(),
            $this->clock,
            $this->membership(),
            $this->transactions,
        );
    }

    public function placement(): UpdateCardPlacementService
    {
        return new UpdateCardPlacementService($this->cards, $this->membership(), $this->assembler());
    }

    /** Sharing 的三个端口，一个实现类 —— 与生产接线一致。 */
    public function membership(): CardMembershipService
    {
        return new CardMembershipService($this->members, $this->transactions);
    }

    /**
     * 给预置的卡补上 owner 成员行。
     *
     * ⚠️ 这不是方便，是**保真**：T-110 之后每张卡在库里都必然有一行 owner
     * 成员记录（建卡时同事务写入，历史卡由迁移回填），而
     * {@see CardViewAssembler} 会对缺失的成员行直接抛。
     * 不补的话，每一条「预置一张卡然后读它」的既有用例都会红在一个
     * 与它无关的地方。
     *
     * 反过来说：要测「成员行缺失」那一支，别用这个构造器 —— 直接把卡塞进
     * `$this->cards` 而不碰 `$this->members`。
     */
    private function seedOwnerRows(Card ...$cards): void
    {
        foreach ($cards as $card) {
            $this->members->save(CardMember::owner($card->id(), $card->ownerId(), $card->createdAt()));
        }
    }

    public function updater(): UpdateCardService
    {
        return new UpdateCardService($this->cards, $this->secrets(), $this->assembler(), self::limits(), $this->clock);
    }

    public function deleter(): DeleteCardService
    {
        return new DeleteCardService($this->cards, $this->clock);
    }

    public function assembler(): CardViewAssembler
    {
        return new CardViewAssembler($this->decryptor, $this->membership());
    }

    public function secrets(): CardSecrets
    {
        return new CardSecrets($this->crypto, new RecordingHmacHasher());
    }

    public static function auth(?Uuid $userId = null): AuthContext
    {
        return new AuthContext(
            $userId ?? WalletEntities::id(0xA11A),
            WalletEntities::id(0x5E55),
            WalletEntities::id(0xDE1),
        );
    }

    /**
     * §7.5 的真值。
     */
    public static function limits(): LimitEnforcer
    {
        return new LimitEnforcer(
            cardsPerUser: 500,
            membersPerCard: 20,
            friendsPerUser: 500,
            friendRequestsPerDay: 50,
            shareInvitesPerDay: 100,
            barcodePayloadBytes: 1024,
            noteChars: 2000,
            titleChars: 100,
            usernameMinChars: 3,
            usernameMaxChars: 20,
            usernamePattern: '^[a-z0-9_]{3,20}$',
            usernameAttemptsPerUser: 10,
        );
    }
}
