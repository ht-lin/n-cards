<?php

declare(strict_types=1);

namespace App\Shared\Domain\Config;

/**
 * `GET /v1/config` 下发的 `maintenance.message_key`（§9.2，T-112）。
 *
 * ============================================================================
 * 键，不是文案
 * ============================================================================
 * 任务卡的原话：「`maintenance.message_key` 是**键不是文案** —— 面向用户的德语
 * 文案由客户端本地化生成」。§11.1 同口径：服务端的 `detail` 是给开发者与日志看的
 * 英文说明，用户看到的每一句德语都在客户端的 `strings.xml` 里。
 *
 * 所以这里的取值是**标识符**，永远不要把它当作可读文本的前缀去拼接。
 *
 * ============================================================================
 * 为什么是 enum 而不是两个字符串常量
 * ============================================================================
 * 契约里 `Maintenance.message_key` 写成了 `enum:` 列表，而客户端（T-158）会按
 * 这个值分支渲染两种不同的横幅。两处取值漂了的症状是**静默的**：客户端的 `when`
 * 落到 else 分支，于是公告期的横幅直接不显示，而没有任何错误。
 *
 * 做成 enum 之后，`tests/Api/OpenApiDocumentTest` 能拿 `values()` 与契约里那份
 * 列表逐项对账 —— 这和 `ErrorCode` 与 `problem-details.schema.json` 的关系是
 * 同一个套路。
 *
 * ⚠️ 新增 case 是向后兼容的（§13.6 允许加枚举值），改名不是 ——
 * 客户端的分支是照这张表写的。
 */
enum MaintenanceMessageKey: string
{
    /** 窗口将在 {@see MaintenanceWindow::ANNOUNCEMENT_LEAD_SECONDS} 内开始，但还没开始。 */
    case Scheduled = 'maintenance.scheduled';

    /** 正在窗口内。此时、且仅此时，`retry_after` 有值。 */
    case InProgress = 'maintenance.in_progress';

    /**
     * 全部取值，用于与契约对账。
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $key): string => $key->value, self::cases());
    }
}
