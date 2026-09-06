<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailSenderInterface;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\Timing\TimeEqualizerInterface;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\Time\ClockInterface;

/**
 * `POST /v1/auth/otp/request` 的编排（§6.3.1、§7.1）。
 *
 * ============================================================================
 * 防枚举：这个类**不知道**邮箱注册过没有（ADR-0014）
 * ============================================================================
 * §3.8 要求：响应体、状态码与耗时三者都不得泄露「这个邮箱注册过吗」。
 * 邮箱不是随便什么标识符 —— 它是跨服务的强身份锚点，字典可以按亿级购买。
 * 一个能区分的接口等价于一个可变现的「邮箱有效性验证服务」，
 * 而受害者是我们的用户（钓鱼与撞库的第一步就是确认目标在哪些服务有账号）。
 *
 * T-103 原本的办法是「两条路径 + 逐项配平」：邮箱不存在时建一条哑挑战
 * （`is_decoy`）、不发信，再靠数 Vault 往返次数把两条路径的做功拉平。
 * T-104 发现那个设计**让注册变得不可能**（新用户永远收不到码，而契约里没有
 * 第二条注册路径），于是按 ADR-0014 把它换成了更强也更简单的一条：
 *
 *   **无论邮箱是否注册，都真发一封验证码信。**
 *
 * 于是这个类里**一个分支都没有**，连 `UserRepositoryInterface` 都不再注入 ——
 * 「两条路径不可区分」升级成了「压根不存在第二条路径」。
 * 首次验证成功时才建 `users` 行（§5.2 / §6.3.1），输入是本类存进挑战的
 * `email_encrypted` 与 `locale`。
 *
 * 三道防线于是收缩成两道半：
 *
 *   ① **形状**：只有一种返回，{@see OtpChallengeIssued}。控制器里也没有 `if`。
 *   ② **做功**：不再需要人肉配平 —— 没有分支就没有可配的两边。
 *   ③ **耗时**：{@see TimeEqualizerInterface} 保留。今天它兜的不是分支差异，
 *      而是纵深防御：将来谁往这里加一个 `if`，填充仍然在原地挡着。
 *
 * ⚠️ 保留 ③ 而不是顺手删掉，是因为删它没有任何测试会红，而它挡住的那类回归
 * （新增一个按存在性分支的优化）恰恰是最容易被当成「性能改进」提交的。
 *
 * ============================================================================
 * ⚠️ 收件人**恒为新加密的密文**，不要「优化」成读 users 那一列
 * ============================================================================
 * `$recipient = $crypto->encrypt(CryptoKey::Pii, $payload->email)` 每次都真加密一次，
 * 哪怕这个邮箱已经有 `users.email_encrypted` 可以直接拿。
 *
 * 改成 `$user?->emailEncrypted() ?? $crypto->encrypt(...)` 会同时打开两个洞：
 * 要先查一次 `users`（本类就又知道存在性了），且已注册路径会少一次 Vault 往返 ——
 * 正是 T-103 花了一整段注释去堵的那条时间侧信道，只是方向反过来。
 *
 * 代价是每次请求一次 Transit encrypt。本端点被 §7.5 限到每邮箱 1/min，付得起。
 *
 * ============================================================================
 * 这里**没有**事务
 * ============================================================================
 * 写库只有一处（`invalidateActiveFor` + `save`，同一张表），
 * 而发信是异步入队、失败自带重投。`TransactionRunnerInterface` 是给
 * `VerifyOtpService`「建 user + device + session 三步同生共死」那种场景的，这里用不上。
 *
 * ⚠️ 顺序仍然有要求：作废旧挑战必须在 save 新挑战**之前**，否则会把刚建的那条
 * 一起作废掉，用户拿到的码当场失效。
 */
final readonly class RequestOtpService
{
    /** `config/packages/rate_limiter.yaml` 里的策略名（§7.5：1/min、5/h、10/day）。 */
    private const POLICY_EMAIL = 'otp_request_email';

    /** 同上（§7.5：20/h）。第二道闸，挡「换邮箱不换出口」的批量试探。 */
    private const POLICY_IP = 'otp_request_ip';

    /**
     * 取不到客户端 IP 时的占位主体。
     *
     * ⚠️ 回落到一个固定串而**不是跳过限流** —— 跳过等于给任何能造出这种请求的
     * 调用方开一个绕过 §7.5 的后门。同样的取舍见 `RateLimitListener::subject()`。
     */
    private const IP_FALLBACK = 'unknown';

    /**
     * @param int<1, 9>   $codeDigits          §7.1：6 位
     * @param int<1, max> $ttlSeconds          §7.1：10 分钟
     * @param int<0, max> $resendAfterSeconds  §7.1：60 秒，必须与 otp_request_email 的 1/min 窗口一致
     * @param int<1, max> $requestBudgetMillis 恒定耗时预算，见 config/packages/ncards_otp.yaml
     */
    public function __construct(
        private OtpChallengeRepositoryInterface $challenges,
        private HmacHasherInterface $hasher,
        private CryptoServiceInterface $crypto,
        private RandomnessInterface $random,
        private UuidGeneratorInterface $uuids,
        private ClockInterface $clock,
        private RateLimiterInterface $limiter,
        private TimeEqualizerInterface $equalizer,
        private MetricsInterface $metrics,
        private MailSenderInterface $mail,
        private int $codeDigits,
        private int $ttlSeconds,
        private int $resendAfterSeconds,
        private int $requestBudgetMillis,
    ) {
    }

    /**
     * @param string|null $clientIp 由 Http 层从 `Request::getClientIp()` 取出后以裸字符串传入 ——
     *                              deptrac 里 `Identity.Application` 不得出现 `Request`
     *
     * @throws \App\Shared\Domain\Error\RateLimitExceeded  429，带 Retry-After
     * @throws \App\Shared\Domain\Error\DomainException    503（Redis 不可用时限流 fail-closed）
     * @throws \App\Shared\Domain\Crypto\CryptoUnavailable 503（Vault 不可达）
     */
    public function request(OtpRequestPayload $payload, ?string $clientIp): OtpChallengeIssued
    {
        $budget = $this->equalizer->begin($this->requestBudgetMillis);

        $emailHash = HashDigest::fromRaw($this->hasher->hash($payload->email));

        // ⚠️ 限流必须在**任何写库与发信之前**。它是这个端点唯一的滥用闸门：
        // §7.5 的 1/min、5/h、10/day 就是「对自有邮箱的 OTP 轰炸」这条剩余攻击面
        // （§7.2 T11）的全部缓解措施。ADR-0014 让本端点对任意邮箱都真发信之后，
        // 这道闸从「重要」变成了「唯一」—— 它挡的是「用我们的域名给别人的收件箱发信」。
        //
        // 一次 consumeAll 而不是两次 consume：ADR-0005 的「全过才扣」。分开扣的话，
        // 一个把某出口 IP 的 20/h 烧光的攻击者，会顺带把每个被他试过的邮箱的
        // 1/min 也扣掉 —— 受害者因此登不进去，而他自己什么也没多得到。
        $this->limiter->consumeAll([
            new RateLimitCheck(self::POLICY_EMAIL, 'email:'.bin2hex($emailHash->toRaw())),
            new RateLimitCheck(self::POLICY_IP, 'ip:'.($clientIp ?? self::IP_FALLBACK)),
        ]);

        $now = $this->clock->now();

        $code = $this->generateCode();
        $codeHash = HashDigest::fromRaw($this->hasher->hash($code));

        // IP 也过 Vault HMAC 而不是本地 SHA-256：IPv4 空间只有 2^32，
        // 一份不带 pepper 的摘要在拿到备份后几秒钟就能全表反查，
        // 那 §8.2 把 request_ip_hash 当作「哈希后的个人数据」就不成立了。
        // 与 email_hash 用 Vault pepper 是同一套论证（HmacHasherInterface 的类注释）。
        $ipHash = HashDigest::fromRaw($this->hasher->hash($clientIp ?? self::IP_FALLBACK));

        // ⚠️ 恒加密一次，**不要**改成读 users 那一列 —— 理由见类注释顶部那一节。
        // 这个密文有两个用途：这封信的收件人，以及首次验证成功时建 users 行的输入。
        $recipient = $this->crypto->encrypt(CryptoKey::Pii, $payload->email);

        // §7.1：「单次登录只允许一个活跃 challenge」。
        $this->challenges->invalidateActiveFor($emailHash, $now);

        $challengeId = $this->uuids->generate();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->ttlSeconds));

        $this->challenges->save(OtpChallenge::issue(
            $challengeId,
            $emailHash,
            $recipient,
            $payload->locale,
            $codeHash,
            OtpPurpose::Login,
            $expiresAt,
            // Magic Link 的 token 归 T-106，本端点一期只发数字码。
            null,
            $ipHash,
            $now,
        ));

        $this->send($payload->locale, $recipient, $code);

        // §14.4 的「OTP 转化率骤降」P1 告警需要请求侧的计数 —— T-102 只给了
        // worker 侧的 email_send_total，而那是 worker 消费之后才有的数。
        //
        // ⚠️ `result` 标签保留但恒为 `issued`：ADR-0014 之后 `decoy` 这个取值退休了。
        // 留着标签维度是为了让既有的告警查询不必改写，也为了 T-106 的 Magic Link
        // 将来能在同一个计数器上分出自己的取值。
        //
        // ⚠️ 请求路径里**不做任何其它** Redis 读写（QueueingMailSender 的类注释
        // 明令禁止）。
        $this->metrics->counter('otp_request_total', ['result' => 'issued']);

        $budget->settle();

        return new OtpChallengeIssued($challengeId, $expiresAt, $this->resendAfterSeconds);
    }

    /**
     * 6 位数字，CSPRNG（§7.1）。
     *
     * `str_pad` 是必须的：`random_int(0, 999999)` 有百万分之一的概率给出 `7`，
     * 而用户要输的是 `000007`。少了它，那些码在客户端侧永远对不上。
     */
    private function generateCode(): string
    {
        $max = 10 ** $this->codeDigits - 1;

        return str_pad((string) $this->random->int(0, $max), $this->codeDigits, '0', \STR_PAD_LEFT);
    }

    /**
     * @param Ciphertext $recipient 密文形态的收件人 —— 明文邮箱不出这个类
     */
    private function send(Locale $locale, Ciphertext $recipient, string $code): void
    {
        $this->mail->send(new MailRequest(
            MailTemplate::OtpCode,
            // ⚠️ 这行 match 不能省成「共用一个 enum」：ADR-0012 的 Consequences
            // 解释了为什么 Identity 的 Locale 与 Notification 的 MailLocale
            // 取值域相同却必须是两个类型（把 Locale 提到 Shared\Domain 会让
            // 「用户的界面语言」变成所有模块共享的概念）。
            match ($locale) {
                Locale::German => MailLocale::German,
                Locale::English => MailLocale::English,
            },
            $recipient,
            // 变量集必须**恰好**等于 MailTemplate::OtpCode->requiredVariables()，
            // 多一个少一个 MailRequest 的构造函数就抛。两个值都得是字符串。
            [
                'code' => $code,
                'expires_in_minutes' => (string) intdiv($this->ttlSeconds, 60),
            ],
        ));
    }
}
