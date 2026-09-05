<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Dto;

/**
 * 一封信用哪种语言（§8.6 / §13.8 DoD「德语 + 英语文案齐全」）。
 *
 * ============================================================================
 * ⚠️ 为什么不复用 `Identity\Domain\ValueObject\Locale`
 * ============================================================================
 * 两个 enum 的取值域**逐字相同**（`de` / `en`），看起来是明摆着的重复。
 * 但 deptrac 里 `Notification.Dto` 的允许列表只有 `Shared.Domain` 一项 ——
 * import 一个 `Identity.Domain` 的类型是 violation，而这条规则正是
 * §4.2 规则 1「跨模块只能看到对方的 Port 与 Dto」的强制点，不该为省一个
 * enum 就在它身上开洞。
 *
 * 考虑过、被否掉的备选：把 `Locale` 挪进 `Shared\Domain`。
 * 代价是改 T-101 的 `User` 实体与 `User.orm.xml`（映射里逐字写着
 * `enumType` 的 FQCN），且会让「用户的界面语言」这个 Identity 的领域概念
 * 变成一个所有模块共享的东西 —— 为一个两分支的 enum 付这个价不划算。
 * 完整取舍见 ADR-0012。
 *
 * 映射由调用方做一行 match（T-103/T-104/T-105），语言默认值仍归 Identity：
 * `users.locale` 的 `DEFAULT 'de'`（§17.1）与 `Locale::default()`。
 */
enum MailLocale: string
{
    case German = 'de';

    case English = 'en';

    /**
     * 模板路径里的那一段：`templates/email/<de|en>/…`。
     */
    public function directory(): string
    {
        return $this->value;
    }
}
