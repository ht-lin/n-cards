<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Crypto;

use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoFailed;
use App\Shared\Domain\Error\ErrorCode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Ciphertext::class)]
#[CoversClass(CryptoFailed::class)]
final class CiphertextTest extends TestCase
{
    public function testAcceptsAVaultTransitCiphertext(): void
    {
        $ciphertext = Ciphertext::fromString('vault:v1:abcdefghijklmnop==');

        self::assertSame('vault:v1:abcdefghijklmnop==', $ciphertext->toString());
        self::assertSame('vault:v1:abcdefghijklmnop==', (string) $ciphertext);
        self::assertSame('vault:v1:abcdefghijklmnop==', $ciphertext->jsonSerialize());
    }

    /**
     * 本类存在的**全部理由**：挡住明文被当成密文写进 `_encrypted` 列。
     *
     * §17.1 里那几列是 TEXT，数据库分不出密文与明文；一次漏调 encrypt 的重构
     * 会把会员卡号以明文写满整张表且不报任何错。这条用例守的就是那个闸门。
     */
    #[DataProvider('nonCiphertexts')]
    public function testRejectsAnythingThatIsNotACiphertext(string $value, string $why): void
    {
        self::assertNull(Ciphertext::tryFromString($value), $why);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonCiphertexts(): iterable
    {
        yield '明文条码' => ['4012345678901', '一个真实的 EAN-13 —— 最典型的误写场景'];
        yield '明文邮箱' => ['anna@example.de', 'email_encrypted 列的同类误写'];
        yield '空串' => ['', 'NOT NULL 的列上，空串是「忘了赋值」的常见形态'];
        yield '只有前缀' => ['vault:v1:', '载荷为空 —— PATTERN 里的 `.+` 要求非空'];
        yield '版本号为零' => ['vault:v0:payload', 'Transit 的 key version 从 1 起，v0 不是它会产出的形态'];
        yield '版本号带前导零' => ['vault:v01:payload', '同上，接受它等于放宽闸门'];
        yield '版本号非数字' => ['vault:vX:payload', ''];
        yield '缺版本段' => ['vault:payload', ''];
        yield '大小写不符' => ['VAULT:v1:payload', 'Vault 的前缀恒为小写'];
        yield '前面有杂物' => [' vault:v1:payload', '前导空格 —— 从 CSV / 表单来的值常带'];
        yield '像但不是' => ['vaultv1:payload', ''];
    }

    public function testFromStringThrowsOnNonCiphertext(): void
    {
        $this->expectException(CryptoFailed::class);

        Ciphertext::fromString('4012345678901');
    }

    /**
     * ⚠️ 异常消息里**绝不**能出现被拒的值本身。
     *
     * 这个方法最典型的失败场景就是「有人把明文传了进来」—— 那 $value 就是一个
     * 会员卡号或邮箱，而 detail 会进日志与 Sentry（DomainException 的约束 1）。
     */
    public function testFailureNeverLeaksTheRejectedValue(): void
    {
        $plaintext = '4012345678901';

        try {
            Ciphertext::fromString($plaintext);
            self::fail('应当抛出 CryptoFailed。');
        } catch (CryptoFailed $e) {
            self::assertStringNotContainsString($plaintext, $e->getMessage());
            self::assertStringNotContainsString($plaintext, $e->detail());
        }
    }

    /**
     * 加解密失败对客户端没有可执行含义，所以复用 internal_error 而不是新造 code
     * （§13.6 禁止在 /v1 内随意扩张错误码表）。
     */
    public function testCryptoFailedRendersAsInternalError(): void
    {
        self::assertSame(ErrorCode::InternalError, (new CryptoFailed())->errorCode());
    }

    /**
     * T-404 的 rewrap 用 keyVersion() 挑「还停在旧版本上」的行：
     * 全部行都到 v2 了才能把 min_decryption_version 提到 2（§5.3 轮换流程第 4 步）。
     */
    #[DataProvider('versions')]
    public function testExposesTheKeyVersion(string $value, int $expected): void
    {
        self::assertSame($expected, Ciphertext::fromString($value)->keyVersion());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function versions(): iterable
    {
        yield 'v1' => ['vault:v1:payload', 1];
        yield 'v2' => ['vault:v2:payload', 2];
        yield '两位数' => ['vault:v42:payload', 42];
        // 载荷里出现冒号是可能的（base64 本身不会，但 Vault 的打包格式不归我们管），
        // 版本号的解析必须只看**第一个**冒号之后到第二个冒号之间。
        yield '载荷含冒号' => ['vault:v3:aGVsbG86d29ybGQ=', 3];
    }

    public function testEqualsComparesTheWholeValue(): void
    {
        $a = Ciphertext::fromString('vault:v1:payload');
        $b = Ciphertext::fromString('vault:v1:payload');
        $c = Ciphertext::fromString('vault:v2:payload');

        self::assertTrue($a->equals($b));
        self::assertFalse($a->equals($c));
    }
}
