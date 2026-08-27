<?php

declare(strict_types=1);

namespace App\Shared\Domain\Client;

use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\FieldError;
use App\Shared\Domain\Error\FieldErrorCode;

/**
 * `X-Client: android/1.4.0 (26)` 的解析结果（§6.1）。
 *
 * 两个用途：§6.1 的强制升级判定（低于最低支持版本 → `426 client_too_old`），
 * 以及 §14.4 的指标切分。
 *
 * ============================================================================
 * build 号必填，是刻意从严
 * ============================================================================
 * §13.6 允许事后**放宽**校验，永远禁止事后**收紧**。T-010 的 OkHttp 拦截器总是会发
 * 完整的 `platform/x.y.z (build)`，所以现在按完整格式收；将来真遇到发不出 build 的
 * 客户端，放宽是合法变更，反过来不是。
 *
 * ============================================================================
 * 比较语义
 * ============================================================================
 * {@see isAtLeast()} 先比 major/minor/patch 三元组，相等再比 build，且**不比 platform**。
 * 平台是维度而不是顺序 —— 「android 1.4.0 ≥ ios 1.2.0」这个问题本身没有意义。
 * 最低支持版本的配置（`%ncards.min_supported_client%`）在一期只有 android 一个平台，
 * 二期加 iOS 时要改成按平台各配一条，那时再来动这里。
 */
final readonly class ClientVersion implements \Stringable
{
    /**
     * `android/1.4.0 (26)`。
     *
     * - platform：小写字母，1–16 位
     * - 版本：严格三段式 `x.y.z`，每段 1–4 位数字
     * - build：括号内 1–9 位数字，`(` 前允许有空格
     */
    private const PATTERN = '/^(?<platform>[a-z]{1,16})\/(?<major>\d{1,4})\.(?<minor>\d{1,4})\.(?<patch>\d{1,4})\s*\((?<build>\d{1,9})\)$/';

    public function __construct(
        public string $platform,
        public int $major,
        public int $minor,
        public int $patch,
        public int $build,
    ) {
    }

    /**
     * @throws DomainException `validation_failed` —— header 值来自客户端，格式不对是客户端错误
     */
    public static function parse(string $header): self
    {
        if (1 !== preg_match(self::PATTERN, trim($header), $m)) {
            throw DomainException::validationFailed(new FieldError('X-Client', FieldErrorCode::InvalidFormat, 'The X-Client header must look like "android/1.4.0 (26)".'));
        }

        return new self(
            $m['platform'],
            (int) $m['major'],
            (int) $m['minor'],
            (int) $m['patch'],
            (int) $m['build'],
        );
    }

    /**
     * 本版本是否不低于 `$minimum`。
     *
     * **不比较 platform** —— 理由见类注释。
     */
    public function isAtLeast(self $minimum): bool
    {
        return [$this->major, $this->minor, $this->patch, $this->build]
            >= [$minimum->major, $minimum->minor, $minimum->patch, $minimum->build];
    }

    /**
     * 还原成规范形式。用于日志与指标标签 —— 保证同一个客户端版本在
     * §14.4 的指标里只有一种字符串形态。
     */
    public function __toString(): string
    {
        return \sprintf('%s/%d.%d.%d (%d)', $this->platform, $this->major, $this->minor, $this->patch, $this->build);
    }
}
