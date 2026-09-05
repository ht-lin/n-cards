<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

/**
 * `devices.platform`（§5.2）。
 *
 * 一期只有 Android —— iOS 在 §1.3 的 OUT 列表里，是被明确排除的范围，
 * 不是「还没做」。所以这个 enum 现在只有一个 case，且**没有库层 CHECK**：
 * 真做 iOS 的那天只需要加一个 case，不需要发迁移（§13.6 允许新增枚举值）。
 *
 * 这一列存在的意义不在「区分平台」，而在设备管理页要显示它（§5.2 的
 * `model` / `os_version` / `app_version` 同理），以及 T-105 的推送要知道
 * `push_token` 该发给哪个厂商通道。
 */
enum DevicePlatform: string
{
    case Android = 'android';
}
