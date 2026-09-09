<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared\Doctrine;

use App\Shared\Infrastructure\Doctrine\CrossModuleForeignKeys;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * ADR-0019 的验收标准：跨模块外键**存在于 ORM 生成的 schema 里**，
 * 尽管没有任何实体持有对应的关联。
 *
 * ============================================================================
 * 为什么这个文件必须存在
 * ============================================================================
 * {@see CrossModuleForeignKeys} 用**字符串**指表名与列名（那正是它不违反
 * §4.2 规则 4 的原因），代价是这些外键没有编译期检查：
 * 把 `owner_id` 打成 `owner`，PHP 层面一切正常。
 *
 * 唯一会红的地方是 `doctrine:schema:validate`，而它的失败信息是一句
 * 「不同步」——不指向原因。这里直接断言那条外键的形状，
 * 好让「监听器被误删 / 表名打错 / onDelete 写错」三种情况各自给出一句人话。
 *
 * ⚠️ 这条用例**不需要**数据库连接：`getSchemaFromMetadata()` 只读 ORM 元数据。
 * 所以它在裸机上也跑，不 skip。
 */
#[CoversClass(CrossModuleForeignKeys::class)]
final class CrossModuleForeignKeysTest extends KernelTestCase
{
    public function testTheCardsOwnerForeignKeyIsInjectedIntoTheOrmSchema(): void
    {
        $cards = $this->schema()->getTable('cards');

        self::assertTrue(
            $cards->hasForeignKey('fk_cards_owner_id'),
            'ADR-0019：cards.owner_id → users(id) 由 CrossModuleForeignKeys 在 '
            .'postGenerateSchema 上补进 ORM schema。它不见了的话 schema:validate 会报'
            .'「多出一条待删外键」，而那条信息不会告诉你监听器没跑。',
        );

        $fk = $cards->getForeignKey('fk_cards_owner_id');

        self::assertSame(['owner_id'], $fk->getLocalColumns());
        self::assertSame('users', $fk->getForeignTableName());
        self::assertSame(['id'], $fk->getForeignColumns());
        // ⚠️ Comparator::diffForeignKey() 不比约束名，但**比 onDelete** ——
        // 写错就是一条永久 diff。而且 RESTRICT 本身是 §3.7 账号删除流程的护栏。
        self::assertSame('RESTRICT', $fk->getOption('onDelete'));
    }

    /**
     * ⚠️ `Card` 实体**不该**持有一个到 `User` 的 ORM 关联。
     *
     * 这是 ADR-0019 与 ADR-0011 第 3 条的分界线，也是 §4.2 规则 5
     * （禁止跨模块 JOIN）结构性成立的地方：拿不到 `$card->getOwner()`，
     * 就写不出 `$card->getOwner()->getUsername()`。
     *
     * deptrac 已经会拦住那个 import，但它拦的是「Wallet.Domain 引用了
     * Identity.Domain」——如果哪天有人把 `User` 挪进 `Shared\Domain`，
     * deptrac 就放行了，而这条用例还会红。
     */
    public function testTheCardEntityHasNoAssociationToUser(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $em->getClassMetadata(\App\Module\Wallet\Domain\Entity\Card::class);

        self::assertSame([], $metadata->associationMappings, 'Card 不该有任何 ORM 关联。');
        self::assertContains('ownerId', $metadata->getFieldNames(), 'owner_id 是一个普通的 uuid 列。');
    }

    /**
     * T-110 的三条。`card_members` 属 Sharing，而 `cards` 属 Wallet、
     * `users` 属 Identity —— 所以这张表的**每一条**外键都跨模块。
     *
     * ⚠️ `fk_card_members_card_id` 是清单里第一条指向**另一个模块业务表**
     * （而不是 `users`）的。ADR-0019 的「影响」行原先把 T-110 记成「两条」，
     * 那是按 `card_members` 归 Wallet 算的；归属定在 Sharing 之后是三条。
     */
    public function testTheThreeCardMemberForeignKeysAreInjectedIntoTheOrmSchema(): void
    {
        $members = $this->schema()->getTable('card_members');

        $expected = [
            'fk_card_members_card_id' => ['card_id', 'cards', 'CASCADE'],
            'fk_card_members_user_id' => ['user_id', 'users', 'CASCADE'],
            // SET NULL：邀请人删号不该把被邀请人的成员关系一起删掉。
            'fk_card_members_added_by' => ['added_by', 'users', 'SET NULL'],
        ];

        foreach ($expected as $name => [$column, $foreignTable, $onDelete]) {
            self::assertTrue(
                $members->hasForeignKey($name),
                \sprintf(
                    'ADR-0019：%s 由 CrossModuleForeignKeys 在 postGenerateSchema 上补进 ORM schema。'
                    .'它不见了的话 schema:validate 会报「多出一条待删外键」，'
                    .'而那条信息不会告诉你是哪一条、也不会告诉你监听器没跑。',
                    $name,
                ),
            );

            $fk = $members->getForeignKey($name);

            self::assertSame([$column], $fk->getLocalColumns(), $name);
            self::assertSame($foreignTable, $fk->getForeignTableName(), $name);
            self::assertSame(['id'], $fk->getForeignColumns(), $name);
            // Comparator::diffForeignKey() 不比约束名，但**比 onDelete**。
            self::assertSame($onDelete, $fk->getOption('onDelete'), $name);
        }
    }

    /**
     * ⚠️ `CardMember` 一个 ORM 关联都不该有 —— 它的三个 uuid 列全指向别的模块。
     *
     * 与 {@see testTheCardEntityHasNoAssociationToUser()} 同一条理由，
     * 但这里更紧：`card_id → cards` 看起来**像**是可以写成 `<many-to-one>` 的
     * （两张表都在「卡」这个话题下），而它跨的正是 Sharing → Wallet 那条边。
     * 写成关联就能拿到 `$member->getCard()->getBarcodeValueEncrypted()`，
     * §4.2 规则 5 当场失效。
     */
    public function testTheCardMemberEntityHasNoAssociations(): void
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $metadata = $em->getClassMetadata(\App\Module\Sharing\Domain\Entity\CardMember::class);

        self::assertSame([], $metadata->associationMappings, 'CardMember 不该有任何 ORM 关联。');

        foreach (['cardId', 'userId', 'addedBy'] as $field) {
            self::assertContains($field, $metadata->getFieldNames(), $field.' 是一个普通的 uuid 列。');
        }
    }

    /**
     * 监听器跑两次不会炸。
     *
     * `getSchemaFromMetadata()` 在一条用例里被调多次是常事（本文件就调了两次），
     * 而 DBAL 4 对重复添加同名外键发 deprecation —— phpunit.xml.dist 开了
     * `failOnWarning`，那会直接变成一条测试失败。
     */
    public function testGeneratingTheSchemaTwiceIsSafe(): void
    {
        $first = $this->schema()->getTable('cards');
        $second = $this->schema()->getTable('cards');

        self::assertCount(1, $first->getForeignKeys());
        self::assertCount(1, $second->getForeignKeys());

        // 同一条守护对 T-110 那张表也要成立 —— 它有三条外键，
        // 重复添加的 deprecation 在 failOnWarning 下同样是一条失败。
        self::assertCount(3, $this->schema()->getTable('card_members')->getForeignKeys());
        self::assertCount(3, $this->schema()->getTable('card_members')->getForeignKeys());
    }

    private function schema(): Schema
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
    }
}
