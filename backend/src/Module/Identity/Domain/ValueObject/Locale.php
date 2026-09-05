<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\ValueObject;

/**
 * `users.locale`（§5.2）—— 用户选的界面与**邮件**语言。
 *
 * 一期只有德语与英语（§8.6 要求应用内法律页面德英齐全，邮件模板同理，见 T-102 的
 * `templates/email/{de,en}/`）。默认 `de`：产品的一期市场是德国（§1.2）。
 *
 * ⚠️ 这一列在 §17.1 里带 `CHECK (locale IN ('de','en'))`。加新语言时要发两次：
 * 先改 CHECK（迁移），再放开这个 enum —— 反过来的话新值会被库层拒掉。
 * 这也是为什么**只有** §17.1 明写的三列（username / locale / status）有 CHECK，
 * 其余闭合词表（platform / purpose / revoked_reason）只做 PHP enum：
 * §13.6 把「新增枚举值」列为向后兼容变更，不该每次都欠一次迁移。
 */
enum Locale: string
{
    case German = 'de';

    case English = 'en';

    /**
     * 注册时的默认值，与 §17.1 的 `DEFAULT 'de'` 必须一致。
     */
    public static function default(): self
    {
        return self::German;
    }
}
