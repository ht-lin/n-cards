<?php

declare(strict_types=1);

namespace App\Shared\Application\Config;

use App\Shared\Domain\Client\ClientVersion;
use App\Shared\Domain\Config\MaintenanceStatus;

/**
 * `GET /v1/config` 的内容，**已经解析好的形态**（T-112）。
 *
 * 两个版本是 {@see ClientVersion} 而不是字符串：那个值对象已经是 `Stringable`，
 * 而且 `__toString()` 给的就是规范形式（`android/1.4.0 (26)`）。让 Http 层去拼
 * 字符串的话，同一个版本在 `/v1/config` 的响应里与 §14.4 的指标标签里可能长得不一样。
 *
 * 不含 `feature_flags`：M1 恒为空对象，把一个恒定的空值放进 DTO 只会让人以为
 * 它是可变的。它在控制器里作为响应形状的一部分出现（并在那里解释为什么是空的）。
 */
final readonly class ClientConfig
{
    public function __construct(
        public ClientVersion $minSupportedClient,
        public ClientVersion $latestClient,
        public MaintenanceStatus $maintenance,
    ) {
    }
}
