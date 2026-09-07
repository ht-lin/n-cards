<?php

declare(strict_types=1);

namespace App\Module\Notification\Application\Dto;

/**
 * 一期外发邮件的**完整**清单（§3.1）。
 *
 * ============================================================================
 * ⚠️ 为什么在 Dto 而不是 Domain
 * ============================================================================
 * deptrac 的 `_Ports` 策略只给调用方（Identity 等）开放
 * `Notification.Port` + `Notification.Dto` 两层。而调用方**必须**能命名这个 enum ——
 * 「发哪封信」正是它要做的选择。放在 `Notification.Domain` 的话，
 * `Identity.Application` 引用 `MailTemplate` 会是 violation，
 * Port 的入参就只能退化成一个 string，把 §3.1「外发邮件只有这三封」
 * 这条最重要的约束从类型系统里删掉。
 *
 * 与 {@see MailLocale} 同一个位置、同一个理由。
 *
 * 反过来，「这封信在熔断时的去留」不是调用方的事，所以
 * {@see \App\Module\Notification\Domain\MailCriticality} 留在 Domain，
 * 分类逻辑在 {@see \App\Module\Notification\Application\MailCircuitBreaker} 里。
 *
 * ============================================================================
 * 只有三封，而且这个数字是设计出来的
 * ============================================================================
 * §3.1 的 v1.1 修订取消了邮箱邀请与邀请链接（C2），直接后果是
 * **用户无法让系统向任意第三方邮箱发信**。剩下的两类是：
 *
 *   1. OTP（码 + Magic Link 在同一封信里，ADR-0016）—— 收件人只能是
 *      **请求者自己刚输入的那个邮箱**，由 §7.5 的 email_hash
 *      1/min、5/h、10/day 严格限速。
 *   2. 安全提醒 —— 收件人只能是**账号自己**，由系统事件触发。
 *
 * 于是 §7.2 威胁模型 T11「OTP 邮件轰炸 → 域名进黑名单」的攻击面从
 * 「任意邮箱轰炸」收缩成了「对自有邮箱的 OTP 轰炸」。
 *
 * ⚠️ 往这个 enum 里加 case 之前先确认新的收件人来源仍然只有这两种。
 * 「给某人发点什么」的需求几乎一定落在 CONTRIBUTING §9 的 OUT 清单里
 * （邮箱邀请、到期提醒都在上面），加进来会把上面那段论证整个推翻。
 *
 * ============================================================================
 * 一个 case = 三份模板 × 两种语言
 * ============================================================================
 * `templates/email/{de,en}/<basename>.{subject.txt,txt,html}.twig`。
 * 主题也是模板而不是这里的 PHP 常量 —— 让 §13.8 DoD 的「德语 + 英语文案齐全」
 * 有一个能被穷举测试的单一位置（tests/Integration/Module/Notification/MailTemplateRenderingTest）。
 */
enum MailTemplate: string
{
    /**
     * 6 位登录码 **+ Magic Link**（§7.1）。T-103 触发，T-106 加上了链接。
     *
     * ⚠️ 这**一封**信同时带码与链接，不是两封。ADR-0016：两封会让每次登录的
     * 发信量翻倍（§7.5 的「每邮箱 10 封/天」实际变成 20 条消息，而 ADR-0013
     * 的域名邮箱配额至今没有实测），也会让用户在一秒内收到两封 Critical 信
     * 并且要先分辨再选 —— 那正好训练他忽略这一类邮件，而 §7.2 的 T02 把它列为
     * 「邮箱被接管」唯一能被用户察觉的信号。
     *
     * 库里也是这个形状：`otp_challenges` 一行同时挂 `code_hash` 与
     * `magic_token_hash`，共用一个 `consumed_at`。
     */
    case OtpCode = 'otp_code';

    /** 新设备登录提醒，含「这不是我」撤销链接（§7.1）。T-104 触发。 */
    case NewDeviceLogin = 'new_device_login';

    /** refresh token 重放 → 判定令牌被窃，会话家族已撤销（§7.1）。T-105 触发。 */
    case RefreshReplay = 'refresh_replay';

    /**
     * 模板文件名的公共前缀，同时也是 §14.4
     * `email_send_total{provider,template,result}` 里那个 `template` 标签值。
     *
     * 两者同源是刻意的：否则「指标里 template=otp 掉到 0」与
     * 「模板 otp_code 没被渲染过」要靠人脑对上，而这正是 R1 告警触发时
     * 最不该花时间的地方。
     */
    public function basename(): string
    {
        return $this->value;
    }

    /**
     * 这封信的模板要求调用方提供的变量名。
     *
     * ============================================================================
     * 为什么要在 PHP 里再写一遍模板里已有的东西
     * ============================================================================
     * `config/packages/twig.yaml` 把 `strict_variables` 在**三个环境**都打开了，
     * 所以缺变量会在渲染时抛异常 —— 但那是在 worker 里、在消息已经入队之后。
     * 用户看到的是「码一直不来」，运维看到的是 `email_failed` 队列在涨。
     *
     * 这个清单让同一个错误在**入队前**就被 {@see MailRequest}
     * 的构造函数拦下，于是它变成调用方（T-103/T-104/T-105）的一个同步异常，
     * 堆栈直接指向出错的那一行。
     *
     * MailTemplateRenderingTest 会拿这个清单去真渲染每一份模板，
     * 所以「清单与模板不同步」在 CI 就会红，不会漂。
     *
     * @return list<string>
     */
    public function requiredVariables(): array
    {
        return match ($this) {
            // `magic_link_url` 指向**落地页**（`{APP_PUBLIC_BASE_URL}/l/magic/<token>`），
            // 不是 `POST /v1/auth/magic/consume`。企业邮件安全网关会自动 GET
            // 邮件里的每个链接（§7.1），所以 GET 那个地址必须什么都不改 ——
            // 那一侧是一份静态 HTML，后端在 `/l/` 下没有任何路由。
            self::OtpCode => ['code', 'expires_in_minutes', 'magic_link_url'],
            // `revoke_url` 是信里那个「这不是我」链接。它指向 T-105 的落地页，
            // 落地页再走 POST 确认 —— §7.1 的企业邮件安全网关陷阱（网关会
            // 自动 GET 邮件里的每个链接）由那一侧负责，这里只负责原样渲染。
            self::NewDeviceLogin => ['device_model', 'occurred_at', 'approximate_region', 'revoke_url'],
            self::RefreshReplay => ['occurred_at', 'support_url'],
        };
    }
}
