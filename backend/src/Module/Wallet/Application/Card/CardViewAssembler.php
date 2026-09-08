<?php

declare(strict_types=1);

namespace App\Module\Wallet\Application\Card;

use App\Module\Wallet\Domain\Entity\Card;
use App\Shared\Application\Crypto\BatchDecryptorInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Identity\Uuid;

/**
 * `Card` 实体 → {@see CardView}，**一次批量解密**（§5.3 的硬要求）。
 *
 * ============================================================================
 * ⚠️ 这是全模块唯一解密卡片的地方，而且它没有单条版本
 * ============================================================================
 * §5.3 逐字：「必须使用 Vault Transit 的 batch 接口，一次请求解密全部。
 * **禁止在循环中逐条调用 Vault**。」§9.1 的预算说明了为什么：
 * `GET /v1/sync/bootstrap`（200 张卡）P95 ≤ 700 ms，而 200 次 HTTP 往返
 * 光是往返就能吃掉大半。
 *
 * 所以本类**只有** {@see assemble()} 一个收数组的入口 ——
 * `GET /v1/cards/{id}` 那种单卡场景也走它（传一个只有一张卡的数组）。
 * 留一个 `assembleOne()` 就等于留了一个会被抄进 foreach 的示范，
 * 而那正是上面那条禁令针对的写法。
 *
 * ============================================================================
 * 键的形状
 * ============================================================================
 * {@see BatchDecryptorInterface} 明说「键关联由调用方决定，入参与出参同键」——
 * 它的类注释还专门警告过按下标对齐是这类批量 API 最经典的错位 bug
 * （一条失败、数组被压缩，于是 A 的卡号显示成了 B 的）。
 *
 * 一张卡有**两个**密文列，所以键不能只是卡 id。这里用
 * `b:<cardId>` / `n:<cardId>` 两个前缀。前缀而不是二维数组：
 * `decryptAll()` 收的是一个平数组，而把两批分开调等于两次往返。
 *
 * ============================================================================
 * 空批次不打 Vault
 * ============================================================================
 * `decryptAll([])` 按接口约定返回空数组且不发请求，所以「一页 0 张卡」
 * 不需要在这里特判。
 */
final readonly class CardViewAssembler
{
    private const BARCODE_PREFIX = 'b:';
    private const NOTE_PREFIX = 'n:';

    public function __construct(private BatchDecryptorInterface $decryptor)
    {
    }

    /**
     * @param list<Card> $cards
     * @param Uuid       $viewerId 调用者 —— `my_role` / `can_edit` 随他而变
     *
     * @return list<CardView>
     */
    public function assemble(array $cards, Uuid $viewerId): array
    {
        $plaintexts = $this->decryptor->decryptAll(CryptoKey::Card, $this->ciphertexts($cards));

        $views = [];

        foreach ($cards as $card) {
            $id = $card->id()->toString();

            $views[] = new CardView(
                $card->id(),
                $card->title(),
                $card->merchantLabel(),
                $card->color(),
                $card->barcodeFormat()->value,
                $plaintexts[self::BARCODE_PREFIX.$id],
                $plaintexts[self::NOTE_PREFIX.$id] ?? null,
                $card->expiresOn(),
                $card->ownerId(),
                // T-109 阶段这两个恒为 owner / true —— 不是占位，是**真的**：
                // 没有 card_members，能看到一张卡的只有它的 owner，而
                // 服务层已经把非 owner 挡在外面了。T-110 接上成员表后，
                // 这两行会变成一次真正的角色查找，而调用方一行都不用改。
                $card->isOwnedBy($viewerId) ? 'owner' : 'viewer',
                $card->isOwnedBy($viewerId),
                $card->revision(),
                $card->updatedAt(),
            );
        }

        return $views;
    }

    /**
     * 这一批卡的全部密文，键已经带好前缀。
     *
     * @param list<Card> $cards
     *
     * @return array<string, Ciphertext>
     */
    private function ciphertexts(array $cards): array
    {
        $ciphertexts = [];

        foreach ($cards as $card) {
            $id = $card->id()->toString();

            $ciphertexts[self::BARCODE_PREFIX.$id] = $card->barcodeValueEncrypted();

            // 备注可空。null 的那些**不进批次** —— 塞一个假密文进去，
            // Vault 会整批失败（BatchDecryptorInterface：任意一条失败就整批抛）。
            $note = $card->noteEncrypted();

            if (null !== $note) {
                $ciphertexts[self::NOTE_PREFIX.$id] = $note;
            }
        }

        return $ciphertexts;
    }
}
