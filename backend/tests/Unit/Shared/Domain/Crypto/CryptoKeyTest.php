<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Crypto;

use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\CryptoUnavailable;
use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CryptoKey::class)]
#[CoversClass(CryptoUnavailable::class)]
final class CryptoKeyTest extends TestCase
{
    /**
     * key 名要与三处**逐字**一致：
     *   - `infra/vault/bootstrap.sh` 里 create_key 的三次调用
     *   - `infra/vault/policies/ncards-app.hcl` 里的 transit 路径
     *   - §5.3 的密钥清单.
     *
     * 对不上的话 Vault 回 403，而 403 在 VaultClient 里会先被当成 token 失效去重登，
     * 于是真实原因（key 名打错）会被一次无谓的重登盖住 —— 排查起来很不直观。
     * 真实的对表点是 tests/Integration/Shared/Vault/AppRolePolicyTest（要真 Vault），
     * 这里先把常量本身钉住。
     */
    public function testKeyNamesMatchTheSpec(): void
    {
        self::assertSame('ncards-card', CryptoKey::Card->keyName());
        self::assertSame('ncards-pii', CryptoKey::Pii->keyName());
        self::assertSame('ncards-hmac', CryptoKey::Hmac->keyName());
    }

    public function testKeyNameIsTheEnumValue(): void
    {
        foreach (CryptoKey::cases() as $key) {
            self::assertSame($key->value, $key->keyName());
        }
    }

    /**
     * ⚠️ §5.3 密钥清单最后一列：`ncards-hmac` **不轮换**。
     *
     * 轮换它会让全部既有 `email_hash` 查找失效（登录查不到用户），
     * 且 HMAC 单向不可补救。这条纪律有三道防线，本方法是第二道
     * （T-404 的 RewrapCardSecrets 据此跳过它）。
     */
    public function testHmacKeyIsNeverRotatable(): void
    {
        self::assertFalse(CryptoKey::Hmac->isRotatable(), '轮换 ncards-hmac 会让全部 email_hash 查找失效 —— 见 §5.3 与 CryptoKey 的类注释。');
    }

    public function testEnvelopeKeysAreRotatable(): void
    {
        self::assertTrue(CryptoKey::Card->isRotatable());
        self::assertTrue(CryptoKey::Pii->isRotatable());
    }

    /**
     * `isRotatable()` 用无 default 分支的 match：新增一个 key 忘了补映射，
     * 第一次碰到就 \UnhandledMatchError。这条用例遍历全部 case 把那个瞬间提前到 CI。
     * 与 ErrorCode 的自我强制机制是同一套路子。
     */
    public function testEveryKeyHasARotationPolicy(): void
    {
        $policies = [];

        foreach (CryptoKey::cases() as $key) {
            // 新增 case 却忘了补 match 分支 → 这一行 \UnhandledMatchError。
            $policies[$key->value] = $key->isRotatable();
        }

        self::assertSame(
            ['ncards-card' => true, 'ncards-pii' => true, 'ncards-hmac' => false],
            $policies,
            '§5.3 的密钥清单里 transit 只有三把 key（JWT 签名密钥存 KV，不走 Transit）。新增 key 时请一并更新本断言与 §5.3。',
        );
    }

    /**
     * CryptoUnavailable 必须是 503 而不是 500：生产每次重启后 Vault 都是封印状态，
     * 那期间的失败是**期望行为**。用 500 的话，§14.4 的「5xx 率 > 1%」告警
     * 会在每次计划内的 unseal 窗口里误报。
     */
    public function testUnavailableRendersAsServiceUnavailable(): void
    {
        $e = new CryptoUnavailable();

        self::assertSame(ErrorCode::ServiceUnavailable, $e->errorCode());
        self::assertSame(503, $e->errorCode()->httpStatus());
        // warning 而不是 error —— 「看得见但不是故障」。
        self::assertSame('warning', $e->errorCode()->logLevel());
    }

    /**
     * `catch (CryptoFailed)` 必须能一次接住两种失败。
     *
     * 调用方（T-101 / T-109）会按类型收窄地 catch；继承关系一断，
     * 「Vault 不可达」就会从那个 catch 里漏出去，变成一个未处理异常。
     *
     * 写成真的 try/catch 而不是 assertInstanceOf / is_a：后两者对静态已知的类型
     * 是恒真的，PHPStan level 8 会报 alreadyNarrowedType。而这里断言的是
     * **运行时真的被接住了**，顺带把 errorCode 也验掉。
     */
    public function testCatchingCryptoFailedAlsoCatchesUnavailable(): void
    {
        $caught = null;

        try {
            throw new CryptoUnavailable();
        } catch (CryptoFailed $e) {
            $caught = $e->errorCode();
        }

        self::assertSame(
            ErrorCode::ServiceUnavailable,
            $caught,
            'CryptoUnavailable 必须仍是 CryptoFailed 的子类，且保持 503。',
        );
    }
}
