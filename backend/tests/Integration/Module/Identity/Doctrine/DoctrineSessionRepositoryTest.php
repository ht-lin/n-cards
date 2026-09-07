<?php

declare(strict_types=1);

namespace App\Tests\Integration\Module\Identity\Doctrine;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineDeviceRepository;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineSessionRepository;
use App\Module\Identity\Infrastructure\Doctrine\DoctrineUserRepository;
use App\Shared\Domain\Crypto\HashDigest;
use App\Tests\Double\Identity\IdentityEntities;
use App\Tests\Integration\Support\RequiresIdentitySchema;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `devices` / `sessions` 经 ORM 的真实往返。
 *
 * 这里额外验的是 T-101 那条最容易写错的映射决定：`Device.user` / `Session.user`
 * / `Session.device` 是 **ORM 关联**而不是普通 uuid 列（ADR-0011）——
 * 关联写错的话 `schema:validate` 会红，但**懒加载能不能真的取回对象**
 * 只有真库上跑一遍才知道。
 *
 * 仓储直接 `new` 而不从容器取，理由见 {@see DoctrineUserRepositoryTest::setUp()}。
 */
#[CoversClass(DoctrineSessionRepository::class)]
#[CoversClass(DoctrineDeviceRepository::class)]
final class DoctrineSessionRepositoryTest extends KernelTestCase
{
    use RequiresIdentitySchema;

    private DoctrineUserRepository $users;
    private DoctrineDeviceRepository $devices;
    private DoctrineSessionRepository $sessions;

    protected function setUp(): void
    {
        $this->bootIdentitySchema();

        $this->users = new DoctrineUserRepository($this->entityManager);
        $this->devices = new DoctrineDeviceRepository($this->entityManager);
        $this->sessions = new DoctrineSessionRepository($this->entityManager);
    }

    protected function tearDown(): void
    {
        $this->rollbackIdentitySchema();

        parent::tearDown();
    }

    public function testRoundTripsAWholeLoginTriple(): void
    {
        $now = IdentityEntities::now();
        $refreshTokenHash = IdentityEntities::digest('refresh-1');
        [$user, $device, $session] = $this->persistLoginTriple($now, $refreshTokenHash);

        $this->entityManager->clear();

        $found = $this->sessions->findById($session->id());

        self::assertInstanceOf(Session::class, $found);
        self::assertTrue($refreshTokenHash->equals($found->refreshTokenHash()));
        self::assertNull($found->previousTokenHash());
        self::assertFalse($found->isRevoked());
        self::assertNull($found->revokedReason());

        // 关联必须真的能取回**那个**对象（懒加载），而不是一个空壳或别人的行。
        // 不断言 instanceof —— 返回类型已经是 User/Device，那种断言是同义反复
        // （PHPStan level 8 会报 alreadyNarrowedType）。有信息量的是 id 与字段值。
        self::assertTrue($user->id()->equals($found->user()->id()));
        self::assertTrue($device->id()->equals($found->device()->id()));
        self::assertSame('Pixel 6a', $found->device()->model());
    }

    /**
     * T-105 刷新流程的入口。走 uq_sessions_refresh_token_hash。
     */
    public function testFindsByRefreshTokenHash(): void
    {
        $refreshTokenHash = IdentityEntities::digest('refresh-1');
        $this->persistLoginTriple(IdentityEntities::now(), $refreshTokenHash);
        $this->entityManager->clear();

        self::assertInstanceOf(Session::class, $this->sessions->findByRefreshTokenHash($refreshTokenHash));
        self::assertNull($this->sessions->findByRefreshTokenHash(IdentityEntities::digest('unknown')));
    }

    /**
     * §7.1 的轮换：新摘要接位、旧摘要落到 previous。
     *
     * ⚠️ 轮换之后**旧摘要必须查不到** —— 这正是 T-105 判定「令牌被窃」的前提：
     * 查不到当前、但等于某一行的 previous，就是重放。
     */
    public function testRotationMakesTheOldHashUnfindableAsCurrent(): void
    {
        $now = IdentityEntities::now();
        $first = IdentityEntities::digest('refresh-1');
        $second = IdentityEntities::digest('refresh-2');
        [, , $session] = $this->persistLoginTriple($now, $first);

        $session->rotate($second, $now->modify('+90 days'));
        $this->sessions->save($session);
        $this->entityManager->clear();

        self::assertNull($this->sessions->findByRefreshTokenHash($first));

        $found = $this->sessions->findByRefreshTokenHash($second);
        self::assertInstanceOf(Session::class, $found);
        self::assertTrue($first->equals($found->previousTokenHash() ?? $second));
    }

    public function testPersistsRevocationWithItsReason(): void
    {
        $now = IdentityEntities::now();
        [, , $session] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        $session->revoke(SessionRevokedReason::ReuseDetected, $now);
        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->sessions->findById($session->id());

        self::assertInstanceOf(Session::class, $found);
        self::assertTrue($found->isRevoked());
        self::assertSame(SessionRevokedReason::ReuseDetected, $found->revokedReason());
        self::assertTrue($found->revokedReason()->isSecurityIncident());
    }

    public function testRejectsADuplicateRefreshTokenHash(): void
    {
        $now = IdentityEntities::now();
        $refreshTokenHash = IdentityEntities::digest('refresh-1');
        [$user, $device] = $this->persistLoginTriple($now, $refreshTokenHash);

        $duplicate = Session::start(
            IdentityEntities::id(6),
            $user,
            $device,
            $refreshTokenHash,
            $now->modify('+90 days'),
            $now,
        );

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sessions->save($duplicate);
    }

    public function testPersistsDeviceRevocationClearingThePushToken(): void
    {
        $now = IdentityEntities::now();
        [, $device] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        $device->updatePushToken('fcm-token-1', $now);
        $this->devices->save($device);
        $this->entityManager->clear();

        $reloaded = $this->devices->findById($device->id());
        self::assertInstanceOf(Device::class, $reloaded);
        self::assertSame('fcm-token-1', $reloaded->pushToken());

        $reloaded->revoke($now->modify('+1 day'));
        $this->devices->save($reloaded);
        $this->entityManager->clear();

        $revoked = $this->devices->findById($device->id());
        self::assertInstanceOf(Device::class, $revoked);
        self::assertTrue($revoked->isRevoked());
        // ROPA §8.2：设备撤销后即删 push_token（唯一会发给 FCM 的字段）。
        self::assertNull($revoked->pushToken());
    }

    public function testReturnsNullForUnknownIds(): void
    {
        self::assertNull($this->sessions->findById(IdentityEntities::id(999)));
        self::assertNull($this->devices->findById(IdentityEntities::id(999)));
    }

    // ========================================================================
    // T-105
    // ========================================================================

    /**
     * ⚠️ §7.1 重放检测的**唯一**入口。
     *
     * 上面的 `testRotationMakesTheOldHashUnfindableAsCurrent()` 证明了旧摘要
     * 在 current 上查不到；这一条证明它**确实躺在 previous 上**。
     * 没有这一条，那个 null 就只是「令牌无效」，整个重放检测都不存在。
     */
    public function testFindsARotatedTokenByItsPreviousHash(): void
    {
        $now = IdentityEntities::now();
        $first = IdentityEntities::digest('refresh-1');
        $second = IdentityEntities::digest('refresh-2');
        [, , $session] = $this->persistLoginTriple($now, $first);

        $session->rotate($second, $now->modify('+90 days'));
        $this->sessions->save($session);
        $this->entityManager->clear();

        $found = $this->sessions->findByPreviousTokenHash($first);

        self::assertInstanceOf(Session::class, $found);
        self::assertTrue($session->id()->equals($found->id()));
    }

    /**
     * 没轮换过的会话，`previous_token_hash` 是 NULL —— 拿当前摘要去查 previous
     * 必须查不到。
     *
     * ⚠️ 这条挡的是「WHERE previous IS NULL 也被当成匹配」那类写法：
     * 那会让**每一次正常的首刷**都被判成令牌被窃。
     */
    public function testAFreshSessionIsNotFoundByItsCurrentHashOnThePreviousColumn(): void
    {
        $now = IdentityEntities::now();
        $hash = IdentityEntities::digest('refresh-1');
        $this->persistLoginTriple($now, $hash);
        $this->entityManager->clear();

        self::assertNull($this->sessions->findByPreviousTokenHash($hash));
    }

    /**
     * 远程登出：撤销该设备上该用户的全部未撤销会话。
     */
    public function testRevokesEverySessionOnADevice(): void
    {
        $now = IdentityEntities::now();
        [$user, $device] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        // 同一台设备再登录一次 = 第二条会话行。
        $second = Session::start(
            IdentityEntities::id(7),
            $user,
            $device,
            IdentityEntities::digest('refresh-2'),
            $now->modify('+90 days'),
            $now,
        );
        $this->sessions->save($second);
        $this->entityManager->clear();

        $revoked = $this->sessions->revokeAllForDevice(
            $device->id(),
            $user->id(),
            SessionRevokedReason::UserRevoked,
            $now->modify('+1 hour'),
        );

        self::assertSame(2, $revoked);

        $this->entityManager->clear();

        foreach ([IdentityEntities::digest('refresh-1'), IdentityEntities::digest('refresh-2')] as $hash) {
            $found = $this->sessions->findByRefreshTokenHash($hash);
            self::assertInstanceOf(Session::class, $found);
            self::assertSame(SessionRevokedReason::UserRevoked, $found->revokedReason());
        }
    }

    /**
     * ⚠️ 已因 `reuse_detected` 撤销的会话**不会**被改写成 `user_revoked`。
     *
     * 这是 `Session::revoke()`「首个 reason 胜出」在真库上的证明 ——
     * 而它正是仓储必须逐行走实体方法、不能写成批量 DQL `UPDATE` 的理由。
     * 被覆盖掉的话，这个账号曾经发生过令牌被窃就再也查不出来。
     */
    public function testARemoteLogoutDoesNotOverwriteASecurityIncidentReason(): void
    {
        $now = IdentityEntities::now();
        [$user, $device, $session] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        $session->revoke(SessionRevokedReason::ReuseDetected, $now);
        $this->sessions->save($session);
        $this->entityManager->clear();

        $revoked = $this->sessions->revokeAllForDevice(
            $device->id(),
            $user->id(),
            SessionRevokedReason::UserRevoked,
            $now->modify('+1 hour'),
        );

        // 已撤销的不计入 —— 幂等。
        self::assertSame(0, $revoked);

        $this->entityManager->clear();

        $found = $this->sessions->findById($session->id());
        self::assertInstanceOf(Session::class, $found);
        self::assertSame(SessionRevokedReason::ReuseDetected, $found->revokedReason());
    }

    /**
     * ⚠️ `userId` 是 WHERE 里的第二个条件，不是冗余：即使上层漏了归属校验，
     * 这条语句也伤不到别人的会话。
     */
    public function testRevokingScopesToTheOwningUser(): void
    {
        $now = IdentityEntities::now();
        [, $device, $session] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        $stranger = IdentityEntities::user(IdentityEntities::id(90), IdentityEntities::digest('other-email'), now: $now);
        $this->users->save($stranger);

        $revoked = $this->sessions->revokeAllForDevice(
            $device->id(),
            $stranger->id(),
            SessionRevokedReason::UserRevoked,
            $now,
        );

        self::assertSame(0, $revoked);

        $this->entityManager->clear();

        $found = $this->sessions->findById($session->id());
        self::assertInstanceOf(Session::class, $found);
        self::assertFalse($found->isRevoked());
    }

    /**
     * 设备列表：只列未撤销的，按 `last_seen_at` 倒序。
     */
    public function testListsOnlyActiveDevicesMostRecentlySeenFirst(): void
    {
        $now = IdentityEntities::now();
        [$user] = $this->persistLoginTriple($now, IdentityEntities::digest('refresh-1'));

        $newer = IdentityEntities::device($user, IdentityEntities::id(43), $now);
        $newer->touch($now->modify('+1 hour'));
        $this->devices->save($newer);

        $revokedDevice = IdentityEntities::device($user, IdentityEntities::id(44), $now);
        $revokedDevice->revoke($now);
        $this->devices->save($revokedDevice);

        $this->entityManager->clear();

        $listed = $this->devices->listActiveForUser($user->id());

        self::assertSame(
            [IdentityEntities::id(43)->toString(), IdentityEntities::id(4)->toString()],
            array_map(static fn (Device $d): string => $d->id()->toString(), $listed),
        );
    }

    /**
     * T-104 的写入形状：一次登录 = user + device + session 三行。
     *
     * @return array{User, Device, Session}
     */
    private function persistLoginTriple(\DateTimeImmutable $now, HashDigest $refreshTokenHash): array
    {
        $user = IdentityEntities::user(now: $now);
        $this->users->save($user);

        $device = IdentityEntities::device($user, now: $now);
        $this->devices->save($device);

        $session = IdentityEntities::session(
            $user,
            $device,
            refreshTokenHash: $refreshTokenHash,
            now: $now,
        );
        $this->sessions->save($session);

        return [$user, $device, $session];
    }
}
