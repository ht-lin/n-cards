<?php

declare(strict_types=1);

namespace App\Shared\Domain\Limit;

/**
 * 一个系统限额量的是什么。
 *
 * 存在的唯一理由是 §7.5 里 `barcode payload 1024` 与 `note 2000` 两行的单位不同，
 * 而这个区别在配置里看不出来 —— 两行都只是一个数字。
 *
 * - `Bytes`：`strlen`。barcode 载荷可能含非 UTF-8 字节，按字符计会让一个合法的
 *   1024 字节 PDF417 被拒。
 * - `Characters`：`mb_strlen`。面向人类输入，按字节算会让「Müller」凭空多占额度。
 * - `Count`：与字符串无关的存量计数（卡数、成员数、好友数）。
 *
 * 把它做成枚举而不是靠每个调用点记得用哪个函数，是因为用错的症状是**静默的**：
 * 限额看起来生效了，只是数字比 §7.5 写的松一点或紧一点。
 */
enum LimitUnit
{
    case Count;
    case Bytes;
    case Characters;
}
