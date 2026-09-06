<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
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
 * 这个类唯一真正在做的事：让两条路径不可区分
 * ============================================================================
 * 功能上它只是「生成一个码、存起来、发出去」。真正的复杂度全部来自 §3.8：
 * **邮箱不存在时也必须走完同样的流程**，只是不发信。响应体、状态码与耗时
 * 三者都不得泄露「这个邮箱注册过吗」。
 *
 * 邮箱不是随便什么标识符：它是跨服务的强身份锚点，字典可以按亿级购买。
 * 一个能区分的接口等价于一个可变现的「邮箱有效性验证服务」，
 * 而受害者是我们的用户（钓鱼与撞库的第一步就是确认目标在哪些服务有账号）。
 *
 * 三道防线，缺一不可：
 *
 *   ① **形状**：两条路径都返回 {@see OtpChallengeIssued}，字段与类型逐字相同。
 *   ② **做功**：两条路径的 Vault 往返次数、DB 往返次数相等（见 request() 里的账）。
 *   ③ **耗时**：{@see TimeEqualizerInterface} 把总耗时拉平到固定预算，
 *      兜住 ② 配不平的余数（真实路径那条 messenger_messages 的 INSERT）。
 *
 * ② 是「今天恰好平了」，③ 是「明天加了新东西也还平」。**两条都要**：
 * 只有 ② 的话，T-104/T-106 每加一步都要重新配平，配错了没有任何症状；
 * 只有 ③ 的话，预算必须开得比最慢路径还大，白白拉高全站登录延迟。
 *
 * ============================================================================
 * 这里**没有**事务
 * ============================================================================
 * 写库只有一处（`invalidateActiveFor` + `save`，同一张表），
 * 而发信是异步入队、失败自带重投。`TransactionRunnerInterface` 是给
 * T-104「建 user + device + session 三步同生共死」那种场景的，这里用不上。
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
        private UserRepositoryInterface $users,
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

        // ⚠️ 限流必须在**查库之前**。放到查库之后的话，429 的触发时刻会因为
        // 「查到了 / 没查到」而不同 —— 那等于把刚防住的信息又从限流这条路上放出去。
        //
        // 一次 consumeAll 而不是两次 consume：ADR-0005 的「全过才扣」。分开扣的话，
        // 一个把某出口 IP 的 20/h 烧光的攻击者，会顺带把每个被他试过的邮箱的
        // 1/min 也扣掉 —— 受害者因此登不进去，而他自己什么也没多得到。
        $this->limiter->consumeAll([
            new RateLimitCheck(self::POLICY_EMAIL, 'email:'.bin2hex($emailHash->toRaw())),
            new RateLimitCheck(self::POLICY_IP, 'ip:'.($clientIp ?? self::IP_FALLBACK)),
        ]);

        $now = $this->clock->now();
        $user = $this->users->findByEmailHash($emailHash);

        // 哑挑战也要一个**真实的随机码摘要**。传常量或全零的话，decoy 在库层就是
        // 可辨认的 —— 攻击者拿不到这一列，但 §8.4 的数据导出与将来的运维查询都可能
        // 把这个差别泄露出去。所以：照常生成、照常算摘要，只是不发信。
        // （这条要求写在 OtpChallenge::decoy() 的注释里。）
        $code = $this->generateCode();
        $codeHash = HashDigest::fromRaw($this->hasher->hash($code));

        // IP 也过 Vault HMAC 而不是本地 SHA-256：IPv4 空间只有 2^32，
        // 一份不带 pepper 的摘要在拿到备份后几秒钟就能全表反查，
        // 那 §8.2 把 request_ip_hash 当作「哈希后的个人数据」就不成立了。
        // 与 email_hash 用 Vault pepper 是同一套论证（HmacHasherInterface 的类注释）。
        $ipHash = HashDigest::fromRaw($this->hasher->hash($clientIp ?? self::IP_FALLBACK));

        // ============================================================
        // ⚠️ 下面这一行是耗时对齐的承重墙，别「优化」掉
        // ============================================================
        // decoy 分支这次 encrypt 的**返回值是被丢弃的**。它存在的唯一理由是配平
        // 真实路径在别处的一次 Vault 加密 —— `EncryptedMailSerializer` 会在入队时
        // 对整条消息体做一次 Transit 加密（那是为了 messenger_messages.body 里
        // 不出现明文邮箱与明文码）。两边的账：
        //
        //   真实路径：hmac(email) + hmac(code) + hmac(ip) + 序列化器的 encrypt = 4
        //   decoy   ：hmac(email) + hmac(code) + hmac(ip) + 这里的 encrypt     = 4
        //
        // 删掉它，decoy 就比真实路径少一次 Vault 往返（几毫秒，稳定可测），
        // §3.8 的时间侧信道当场打开，而所有功能测试仍然全绿。
        // tests/Api/OtpEnumerationResistanceTest 断言的就是这个账。
        $recipient = $user?->emailEncrypted() ?? $this->crypto->encrypt(CryptoKey::Pii, $payload->email);

        // §7.1：「单次登录只允许一个活跃 challenge」。两条路径都做 —— 对不存在的
        // 邮箱同样有旧的哑挑战要作废，而且这一步的 DB 往返次数必须相等。
        $this->challenges->invalidateActiveFor($emailHash, $now);

        $challengeId = $this->uuids->generate();
        $expiresAt = $now->modify(\sprintf('+%d seconds', $this->ttlSeconds));

        $this->challenges->save(
            null !== $user
                ? OtpChallenge::issue(
                    $challengeId,
                    $emailHash,
                    $codeHash,
                    OtpPurpose::Login,
                    $expiresAt,
                    // Magic Link 的 token 归 T-106，本端点一期只发数字码。
                    null,
                    $ipHash,
                    $now,
                )
                : OtpChallenge::decoy(
                    $challengeId,
                    $emailHash,
                    $codeHash,
                    OtpPurpose::Login,
                    $expiresAt,
                    $ipHash,
                    $now,
                ),
        );

        if (null !== $user) {
            $this->send($payload->locale, $recipient, $code);
        }

        // §14.4 的「OTP 转化率骤降」P1 告警需要请求侧的计数 —— T-102 只给了
        // worker 侧的 email_send_total，它看不到 decoy（decoy 压根不入队）。
        // 两条分支都记、只有标签值不同，所以这一步是耗时对称的。
        //
        // ⚠️ 请求路径里**不做任何其它** Redis 读写（QueueingMailSender 的类注释
        // 明令禁止）—— 每多一次都是一次要重新配平的往返。
        $this->metrics->counter('otp_request_total', ['result' => null !== $user ? 'issued' : 'decoy']);

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
