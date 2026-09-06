<?php

declare(strict_types=1);

namespace App\Tests\Double\Crypto;

use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoFailed;

/**
 * 进程内的 HMAC 替身，**并且记下每一次调用**。
 *
 * ============================================================================
 * 为什么要「记下每一次调用」
 * ============================================================================
 * §3.8 要求 `POST /v1/auth/otp/request` 对已注册与未注册的邮箱耗时不可区分，
 * 而耗时里占大头的就是 Vault 往返。想确定性地测这件事，最靠谱的断言不是墙钟，
 * 而是**两条路径的往返次数相同** —— 那正是这个替身的 {@see calls()} 提供的。
 * 详见 tests/Api/OtpEnumerationResistanceTest 与
 * tests/Unit/Module/Identity/Application/Otp/RequestOtpServiceTest。
 *
 * ⚠️ 与 {@see InMemoryCryptoService} 一样，这**不是** HMAC：没有 pepper，
 * 输出只是一个确定性摘要。它满足被测代码唯一依赖的性质 ——
 * 同输入同输出、异输入异输出、恒 32 字节。
 */
final class RecordingHmacHasher implements HmacHasherInterface
{
    /** 让摘要与真 Vault 的输出不可能碰撞，也让调试时一眼看出这是替身。 */
    private const PEPPER = 'test-double-pepper';

    /** @var list<string> 依次记下每一次 hash() 的入参 */
    private array $calls = [];

    private ?CryptoFailed $failure = null;

    /**
     * 让调用方模拟 Vault 不可达（限流 fail-closed 与 503 分支要用）。
     */
    public function failWith(?CryptoFailed $failure): void
    {
        $this->failure = $failure;
    }

    public function hash(string $input): string
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }

        $this->calls[] = $input;

        return hash_hmac('sha256', $input, self::PEPPER, true);
    }

    public function verify(string $input, string $digest): bool
    {
        return hash_equals($this->hash($input), $digest);
    }

    /**
     * @return list<string> 全部 hash() 入参，按调用顺序
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }

    public function reset(): void
    {
        $this->calls = [];
    }
}
