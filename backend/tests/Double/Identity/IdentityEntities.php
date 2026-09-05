<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\ValueObject\DevicePlatform;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * T-101 四个实体的测试构造器 —— 只填「这条用例不关心」的那些参数。
 *
 * ============================================================================
 * 为什么在 tests/Double/ 而不是 tests/Fixture/
 * ============================================================================
 * `config/services.yaml` 的 `when@test` 把 `tests/Fixture/` 整个注册成服务
 * （那是给 ProbeApiController 用的）。这个类不是服务，放进去只会多出一条
 * 没人用的定义。`tests/Double/` 不进容器，且已经是跨 suite 共用支撑类的去处
 * （FrozenClock、RecordingLogger 都在那儿）—— Unit 与 Integration 两边都要用它。
 *
 * 每个工厂方法都产出一个**合法**实体；用例只覆盖自己要断言的那一两个参数。
 * 这样新增一个构造参数时，要改的只有这一个文件。
 */
final class IdentityEntities
{
    /** 一条固定的 32 字节摘要，测试里需要「另一条」时用 {@see digest()}。 */
    public const EMAIL_HASH_SEED = 'email-hash';

    private function __construct()
    {
    }

    /**
     * 由一个种子串生成 32 字节裸摘要。
     *
     * 用 `hash(..., binary: true)` 而不是手写常量：同一个种子恒得同一条摘要，
     * 不同种子必然不同，用例读起来也自解释（`digest('anna')`）。
     */
    public static function digest(string $seed): HashDigest
    {
        return HashDigest::fromRaw(hash('sha256', $seed, true));
    }

    public static function ciphertext(string $payload = 'YW5uYQ=='): Ciphertext
    {
        return Ciphertext::fromString('vault:v1:'.$payload);
    }

    /**
     * UUIDv7 形态的固定 id。`$nth` 只是让同一条用例里的多个 id 互不相同。
     */
    public static function id(int $nth = 1): Uuid
    {
        return Uuid::fromString(\sprintf('01941f29-7c00-70ab-8000-%012d', $nth));
    }

    public static function now(string $at = '2026-09-05T10:15:00+00:00'): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at);
    }

    public static function user(
        ?Uuid $id = null,
        ?HashDigest $emailHash = null,
        ?Ciphertext $emailEncrypted = null,
        ?Locale $locale = null,
        ?\DateTimeImmutable $now = null,
    ): User {
        return User::register(
            $id ?? self::id(),
            $emailHash ?? self::digest(self::EMAIL_HASH_SEED),
            $emailEncrypted ?? self::ciphertext(),
            $locale ?? Locale::default(),
            $now ?? self::now(),
        );
    }

    public static function challenge(
        ?Uuid $id = null,
        ?HashDigest $emailHash = null,
        ?HashDigest $codeHash = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?HashDigest $magicTokenHash = null,
        ?HashDigest $requestIpHash = null,
        ?\DateTimeImmutable $now = null,
    ): OtpChallenge {
        $now ??= self::now();

        return OtpChallenge::issue(
            $id ?? self::id(2),
            $emailHash ?? self::digest(self::EMAIL_HASH_SEED),
            $codeHash ?? self::digest('code'),
            OtpPurpose::Login,
            // §7.1：有效期 10 分钟。
            $expiresAt ?? $now->modify('+10 minutes'),
            $magicTokenHash,
            $requestIpHash,
            $now,
        );
    }

    public static function decoyChallenge(?\DateTimeImmutable $now = null): OtpChallenge
    {
        $now ??= self::now();

        return OtpChallenge::decoy(
            self::id(3),
            self::digest('unknown-email'),
            self::digest('decoy-code'),
            OtpPurpose::Login,
            $now->modify('+10 minutes'),
            null,
            $now,
        );
    }

    public static function device(
        ?User $user = null,
        ?Uuid $id = null,
        ?\DateTimeImmutable $now = null,
    ): Device {
        return Device::register(
            $id ?? self::id(4),
            $user ?? self::user(),
            DevicePlatform::Android,
            'Pixel 6a',
            'Android 14',
            '1.0.0',
            $now ?? self::now(),
        );
    }

    public static function session(
        ?User $user = null,
        ?Device $device = null,
        ?Uuid $id = null,
        ?HashDigest $refreshTokenHash = null,
        ?\DateTimeImmutable $now = null,
    ): Session {
        $now ??= self::now();
        $user ??= self::user();

        return Session::start(
            $id ?? self::id(5),
            $user,
            $device ?? self::device($user),
            $refreshTokenHash ?? self::digest('refresh-1'),
            // §7.1：refresh 90 天滑动。
            $now->modify('+90 days'),
            $now,
        );
    }
}
