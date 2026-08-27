<?php

declare(strict_types=1);

namespace App\Shared\Domain\Error;

/**
 * Problem Details 里 `errors[].code` 的词表。
 *
 * §6.1 只用一个例子给出了这一层（`{"field":"title","code":"too_long","message":"..."}`），
 * 从没把可用值列成表。但 §13.6 的「禁止改变错误 `code` 的含义」对这一层同样成立 ——
 * 客户端会照着它做字段级提示，一旦有人今天写 `too_long`、明天写 `max_length`，
 * 两个字符串都会在野外的旧客户端里长期存在，谁都不能删。
 *
 * 所以第一天就是 enum，而不是裸字符串。新增值向后兼容；改名不是。
 * T-004 把这九个值一并补进规格 §6.1。
 */
enum FieldErrorCode: string
{
    /** 必填字段缺失或为 null。 */
    case Required = 'required';
    /** 长度/元素个数低于下限。 */
    case TooShort = 'too_short';
    /** 长度/元素个数超过上限（§7.5 的 title 100 字符、note 2000 字符等）。 */
    case TooLong = 'too_long';
    /** 不符合字符集或格式（UUID、RFC 3339 时间、`X-Client` header……）。 */
    case InvalidFormat = 'invalid_format';
    /** JSON 类型不对（该给 string 给了 int，该给 object 给了 array）。 */
    case InvalidType = 'invalid_type';
    /** 数值超出允许区间（分页 limit < 1 等）。 */
    case OutOfRange = 'out_of_range';
    /** 唯一性冲突的字段级形态。整体冲突用 ErrorCode::UsernameTaken / AlreadyExists。 */
    case NotUnique = 'not_unique';
    /** 请求体里出现了本端点不认识的字段，且该端点选择严格拒绝而非忽略。 */
    case UnknownField = 'unknown_field';
    /**
     * 参数本身被本 API 明确不支持 —— 目前唯一的用处是 §6.1 的「**不使用** offset」：
     * `?offset=50` 必须报错而不是被忽略，否则客户端会永远收到第一页且无人察觉。
     */
    case UnsupportedParameter = 'unsupported_parameter';
}
