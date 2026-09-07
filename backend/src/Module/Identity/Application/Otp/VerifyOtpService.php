<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Otp;

use App\Module\Identity\Application\Session\SessionIssued;
use App\Module\Identity\Domain\Entity\Device;
use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\DeviceRepositoryInterface;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Notification\Application\Dto\MailLocale;
use App\Module\Notification\Application\Dto\MailRequest;
use App\Module\Notification\Application\Dto\MailTemplate;
use App\Module\Notification\Application\Port\MailSenderInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\RateLimit\RateLimiterInterface;
use App\Shared\Application\Timing\TimeEqualizerInterface;
use App\Shared\Application\Token\AccessTokenSignerInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\RateLimit\RateLimitCheck;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Domain\Token\AccessTokenClaims;

/**
 * `POST /v1/auth/otp/verify` 的编排（§6.3.1、§7.1）—— 登录，且首次即注册。
 *
 * ============================================================================
 * 拒绝路径与成功路径是两种完全不同的东西，别把它们看成一条流程的两个分支
 * ============================================================================
 * **拒绝路径**（401）是安全敏感的：它要对「码错了」「过期了」「已经用过了」
 * 「试太多次了」「这是上个版本留下的哑挑战」五种情形返回**逐字相同**的响应，
 * 且做功与耗时也要相同（见下一节）。
 *
 * **成功路径**（200）不是：走到那里的前提是拿到了正确的 6 位码，
 * 也就是攻击者已经能读那个邮箱了 —— 此后再隐藏什么都没有意义。
 * 所以成功路径只关心正确性（三张表同生共死）与副作用（提醒信）。
 *
 * 两条路径的这个不对称贯穿全类，包括 `settle()` 只在拒绝路径调用。
 *
 * ============================================================================
 * 要挡住的那一对比较
 * ============================================================================
 * §3.8 在这个端点上要挡的**不是**「401 与 200 有什么差别」，而是这一对：
 *
 *     「**未注册**邮箱的 challenge + 错码 → 401」
 *     「**已注册**邮箱的 challenge + 错码 → 401」
 *
 * 攻击者能对任意邮箱走完 request（ADR-0014 之后恒 202、恒发信，而信进的是
 * 受害者的收件箱，他看不到），拿到一个真实的 `challenge_id`，
 * 再用一个随便编的码来 verify。这两次 401 若有稳定的耗时或形状差异，
 * 「这个邮箱注册过吗」就从这一侧漏出去了 —— request 侧刚焊死的门会从这里重新打开。
 *
 * 好消息是这两条在本类里**根本不是两条**：拒绝路径不查 `users`、不加密、不发信，
 * 它做的事只有 hmac(code) ×1 + findById + attempts 的 UPDATE，
 * 与挑战背后有没有用户完全无关。这不是靠配平得来的，是结构上就没有分叉。
 *
 * 剩下的余数由 {@see TimeEqualizerInterface} 兜（`ncards.otp.verify_budget_ms`）。
 *
 * ============================================================================
 * ⚠️ 三条顺序约束，每一条都能悄悄打开一个洞
 * ============================================================================
 *   1. **限流在最前**。放到查库之后的话，429 的触发时刻会因为
 *      「challenge_id 存不存在」而不同。
 *   2. **`hash_equals` 永远执行，`isDecoy()` 永远排在它后面**。
 *      反过来写（先判 decoy 就短路返回）会让哑挑战少一次 Vault HMAC 往返，
 *      而哑挑战恰好等价于「这个邮箱在上个版本里没注册过」—— 那是一条现成的
 *      枚举信道。所以 {@see verify()} 里那条布尔链的**次序是安全约束，不是风格**。
 *   3. **`recordAttempt()` 必须落盘，且不在事务里**。它记的是失败，
 *      而失败路径要抛 401；包进事务再抛会把计数一起回滚，
 *      §7.1 的「5 次上限」于是永远数不到 5，暴力猜码的成本从 10^6/5 掉回 10^6。
 */
final readonly class VerifyOtpService
{
    /** `config/packages/rate_limiter.yaml` 里的策略名（§7.5：IP 60/h）。 */
    private const POLICY_IP = 'otp_verify_ip';

    /** 取不到客户端 IP 时的占位主体，口径同 {@see RequestOtpService}。 */
    private const IP_FALLBACK = 'unknown';

    /**
     * 全部拒绝情形共用的文案。
     *
     * ⚠️ **绝不**细分成「码错了」/「过期了」/「试太多次了」。
     * 前两者的区别对客户端毫无用处（两种的处置都是「重新请求一个码」），
     * 而第三种一旦可辨认，攻击者就能用它免费探测「这个 challenge 被别人试过几次」。
     * 契约里 verify 的 401 也只有一种 `token_invalid`。
     */
    private const REJECTED = 'The verification code is not valid.';

    /**
     * 新设备提醒信里那个「这不是我」链接的路径。
     *
     * ⚠️ 指向设备管理落地页，**不是**一个带令牌的一次性撤销 URL。
     * §7.1 要求那个链接「同样走 POST 确认」（企业邮件安全网关会自动 GET
     * 邮件里的每个链接），而一个只列出设备的落地页天然满足那条：
     * GET 它不改变任何状态，真正的撤销是页面上的
     * `DELETE /v1/me/devices/{id}`。
     *
     * ✅ T-105 之后这个链接是**有后端的**：那三个设备端点已经存在。
     * 仍然不是 tokenized URL —— 任务卡的交付物里没有它，而这个形状已经满足 §7.1。
     * 真要换成一次性令牌的话，改的应该只有这一个常量与拼接方式。
     */
    private const REVOKE_PATH = '/l/devices';

    /**
     * @param int<1, max> $maxAttempts        §7.1：5 次。`ncards.otp.max_attempts`
     * @param int<1, max> $accessTtlSeconds   §7.1：900。`ncards.jwt.access_ttl_seconds`
     * @param int<1, max> $refreshTtlSeconds  §7.1：90 天。`ncards.jwt.refresh_ttl_seconds`
     * @param int<1, max> $verifyBudgetMillis 拒绝路径的恒定耗时预算，见 ncards_otp.yaml
     * @param string      $appBaseUrl         `APP_PUBLIC_BASE_URL`，结尾不带斜杠
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private OtpChallengeRepositoryInterface $challenges,
        private DeviceRepositoryInterface $devices,
        private SessionRepositoryInterface $sessions,
        private HmacHasherInterface $hasher,
        private RandomnessInterface $random,
        private UuidGeneratorInterface $uuids,
        private ClockInterface $clock,
        private RateLimiterInterface $limiter,
        private TimeEqualizerInterface $equalizer,
        private MetricsInterface $metrics,
        private MailSenderInterface $mail,
        private AccessTokenSignerInterface $tokens,
        private TransactionRunnerInterface $transactions,
        private int $maxAttempts,
        private int $accessTtlSeconds,
        private int $refreshTtlSeconds,
        private int $verifyBudgetMillis,
        private string $appBaseUrl,
    ) {
    }

    /**
     * @param string|null $clientIp 由 Http 层从 `Request::getClientIp()` 取出后以裸字符串传入 ——
     *                              deptrac 里 `Identity.Application` 不得出现 `Request`
     *
     * @throws DomainException                             401 `token_invalid`（码错/过期/已用/次数耗尽/哑挑战）、
     *                                                     409 `id_conflict`（设备 id 属于别人）、
     *                                                     503（Redis 不可用时限流 fail-closed）
     * @throws \App\Shared\Domain\Error\RateLimitExceeded  429，带 Retry-After
     * @throws \App\Shared\Domain\Crypto\CryptoUnavailable 503（Vault 不可达）
     */
    public function verify(OtpVerificationPayload $payload, ?string $clientIp): SessionIssued
    {
        $budget = $this->equalizer->begin($this->verifyBudgetMillis);

        // ⚠️ 在查库之前。§7.5 的 IP 60/h 是本端点唯一的暴力破解闸门 ——
        // 「challenge_id 5 次总计」只挡住对**同一条**挑战的猜测，
        // 挡不住「反复请求新挑战、每条各试 5 次」。
        $this->limiter->consumeAll([
            new RateLimitCheck(self::POLICY_IP, 'ip:'.($clientIp ?? self::IP_FALLBACK)),
        ]);

        $now = $this->clock->now();
        $challenge = $this->challenges->findById($payload->challengeId);

        // ⚠️ 无条件算，即使 $challenge 是 null。这一次 Vault 往返是拒绝路径上
        // 最贵的一步，把它放进任何分支里都会让那个分支变得可测量地更快。
        $codeHash = HashDigest::fromRaw($this->hasher->hash($payload->code));

        // ⚠️ 次序是安全约束（见类注释第 2 条）。`&&` 会短路，但前四项都是纯内存判断，
        // 唯一有代价的一步已经在上面执行完了 —— 短路在这里不产生可测量的差异。
        //
        // isDecoy() 排在最后：它等价于「这个邮箱在上个版本里没注册过」，
        // 是全链条里唯一一个真正泄露存在性的判据。
        $accepted = null !== $challenge
            && !$challenge->isExpiredAt($now)
            && !$challenge->isConsumed()
            && $challenge->hasAttemptsLeft($this->maxAttempts)
            && $challenge->codeHash()->equals($codeHash)
            && !$challenge->isDecoy();

        if (null !== $challenge) {
            // 成功也记一次（OtpChallenge::recordAttempt() 的注释点名了这条）：
            // 否则「码对了」与「码错了」在 attempts 这一列上留下的痕迹不同。
            //
            // ⚠️ 上限用完之后**仍然要调**，且仍然要写库 —— 「次数耗尽」这种拒绝
            // 必须与「码错了」做同样多的功。计数本身在实体里饱和，理由见那边的注释。
            $challenge->recordAttempt($this->maxAttempts);
            $this->challenges->save($challenge);
        }

        if (!$accepted) {
            $this->metrics->counter('login_total', ['result' => 'rejected']);
            $budget->settle();

            throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
        }

        // ⚠️ 从这里往下**不再填充耗时**，也不再隐藏任何东西。
        // 走到这一行需要正确的 6 位码，也就是调用方已经能读那个邮箱了。
        // 硬把成功路径也填到预算里，只会让每一次正常登录都打一行
        // "Constant-time budget overrun" —— 把 §3.8 的告警淹掉。
        return $this->issueSession($challenge, $payload, $now);
    }

    /**
     * 成功路径：注册（首次）→ 设备 → 会话 → 令牌 → 提醒信。
     */
    private function issueSession(OtpChallenge $challenge, OtpVerificationPayload $payload, \DateTimeImmutable $now): SessionIssued
    {
        // §7.1：32 字节随机、Base64url、**不透明**。在事务外生成 ——
        // 它是要返回给客户端的明文，而事务里存的是它的摘要。
        $refreshToken = self::base64UrlEncode($this->random->bytes(32));

        // ⚠️ 本地 SHA-256，**不**走 Vault HMAC（§17.1 的 DDL 注释就是这么定的）。
        // 与 email_hash / request_ip_hash 的区别在于**原像熵**：refresh token 是
        // 32 字节 CSPRNG，没有可枚举的字典，pepper 买不到任何东西 ——
        // 而代价是每次登录与**每次刷新**（T-105，60/h/session）都多一次 Vault 往返。
        $refreshTokenHash = HashDigest::fromRaw(hash('sha256', $refreshToken, true));

        $sessionId = $this->uuids->generate();

        // 三张表同生共死：user（首次）+ device + session。任何一步失败都不能留下
        // 「建了用户但没有会话」这种要人工修的中间态。
        // UserRepositoryInterface::save() 的注释点名了这个场景。
        [$user, $isNewDevice, $registered] = $this->transactions->run(
            function () use ($challenge, $payload, $now, $sessionId, $refreshTokenHash): array {
                // 重放同一条挑战时 consume() 自己抛 token_invalid（401）。
                // 放在事务最前面：它是这次登录的「许可证」，拿不到就什么都不该发生。
                $challenge->consume($now);
                $this->challenges->save($challenge);

                $user = $this->users->findByEmailHash($challenge->emailHash());
                $registered = null === $user;

                if (null === $user) {
                    $user = $this->register($challenge, $now);
                }

                [$device, $isNewDevice] = $this->resolveDevice($user, $payload, $now);

                $this->sessions->save(Session::start(
                    $sessionId,
                    $user,
                    $device,
                    $refreshTokenHash,
                    $now->modify(\sprintf('+%d seconds', $this->refreshTtlSeconds)),
                    $now,
                ));

                return [$user, $isNewDevice, $registered];
            },
        );

        $accessToken = $this->tokens->sign(new AccessTokenClaims(
            $user->id(),
            $sessionId,
            $payload->deviceId,
            $this->uuids->generate(),
            $now,
            $now->modify(\sprintf('+%d seconds', $this->accessTtlSeconds)),
        ));

        $this->metrics->counter('login_total', ['result' => $registered ? 'registered' : 'success']);

        // ⚠️ 发信在**事务之外**。MailSenderInterface::send() 只入队，
        // 但入队走的是 doctrine transport，也就是同一个连接上的一条 INSERT。
        // 放进事务里，「信已入队但会话回滚」与「会话已建但信没入队」必有其一，
        // 而两者都是静默的。
        if ($isNewDevice) {
            $this->notifyNewDevice($user, $payload, $now);
        }

        return new SessionIssued(
            $accessToken->token,
            $accessToken->expiresInSeconds,
            $refreshToken,
            $user->id(),
            $user->username(),
            $user->locale()->value,
            // 契约把 onboarding_complete 定义为「等价于 username != null」。
            // 由 hasUsername() 算而不是另存一列，两者因此不可能漂。
            $user->hasUsername(),
            $user->createdAt(),
        );
    }

    /**
     * 首次验证成功即注册（§5.2 / §6.3.1 / ADR-0014）。
     *
     * ⚠️ `username` 保持 null —— 用户此刻处于 `onboarding_incomplete`：
     * 除 `GET /v1/me`、`POST /v1/me/username`、`POST /v1/auth/logout` 外，
     * 所有 `/v1` 端点对他返回 403 `username_required`（T-107 / T-108）。
     * 这个中间态是 §5.2 三个候选方案里被显式选中的那个，不是遗漏。
     */
    private function register(OtpChallenge $challenge, \DateTimeImmutable $now): User
    {
        $emailEncrypted = $challenge->emailEncrypted();
        $locale = $challenge->locale();

        if (null === $emailEncrypted || null === $locale) {
            // 只有一种来源：T-104 的迁移之前建的行（含哑挑战）。
            // 建不出 users 行 —— 没有收件人密文就没法发信，那样的账号是坏的。
            // 这些行寿命只有 10 分钟，部署窗口一过自然消失。
            throw new DomainException(ErrorCode::TokenInvalid, self::REJECTED);
        }

        $user = User::register($this->uuids->generate(), $challenge->emailHash(), $emailEncrypted, $locale, $now);

        $this->users->save($user);

        return $user;
    }

    /**
     * 解析这次登录用的设备行，并回答「算不算新设备」。
     *
     * 四种情形，第三种是唯一会拒绝的：
     *
     *   1. 没见过这个 id            → 建行，**新设备**
     *   2. 是本人的、未撤销         → 刷新展示信息与 last_seen，不是新设备
     *   3. 是**别人的**            → 409 `id_conflict`
     *   4. 是本人的、但已被远程登出 → 复活，**按新设备处理**（{@see Device::reactivate()}）
     *
     * @return array{Device, bool}
     */
    private function resolveDevice(User $user, OtpVerificationPayload $payload, \DateTimeImmutable $now): array
    {
        $device = $this->devices->findById($payload->deviceId);

        if (null === $device) {
            $device = Device::register(
                $payload->deviceId,
                $user,
                $payload->platform,
                $payload->model,
                $payload->osVersion,
                $payload->appVersion,
                $now,
            );

            $this->devices->save($device);

            return [$device, true];
        }

        if (!$device->user()->id()->equals($user->id())) {
            // `devices.id` 是**客户端生成**的，所以撞上别人的安装只有两种可能：
            // 客户端的 UUID 生成坏了，或者有人在拿别人的设备 id 试探。
            //
            // 静默改绑是最坏的处理：那会把受害者的设备行从他的设备管理页上挪走，
            // 而他的 App 仍然在用那个 id —— 下次轮到他 409。
            // 返回 409 让客户端知道要重新生成安装 id（契约里 verify 已列 409）。
            //
            // ⚠️ 这里**不**泄露任何关于那个设备或它主人的信息。
            throw new DomainException(ErrorCode::IdConflict, 'The device id is already registered to another account.');
        }

        // 同一台设备再次登录：机型、系统版本、App 版本都可能变了（§5.2 的
        // describe()），设备管理页显示的应该是最新的那份。
        $device->describe($payload->model, $payload->osVersion, $payload->appVersion);

        $isNewDevice = $device->isRevoked();

        if ($isNewDevice) {
            $device->reactivate($now);
        } else {
            $device->touch($now);
        }

        $this->devices->save($device);

        return [$device, $isNewDevice];
    }

    /**
     * §7.1「新设备登录的防护」的邮件那一半。
     *
     * ============================================================================
     * 本任务只发信，**不发推送**
     * ============================================================================
     * 任务卡还要求「向该用户所有既有设备发推送」。FCM 整条链路归 T-306（M3），
     * 本任务不提前拉进来 —— 那需要 `push_token` 的下发（T-105 的
     * `PUT /v1/me/devices/{id}/push-token`）与一个还不存在的推送 Port。
     * 邮件是两条渠道里更重要的那条：§7.2 T02（邮箱被接管 → 账号被接管）
     * 的缓解就落在这封信上，而推送发到的正是可能已经被控制的那些设备。
     */
    private function notifyNewDevice(User $user, OtpVerificationPayload $payload, \DateTimeImmutable $now): void
    {
        // 刚注册的账号第一次登录必然是「新设备」。给它发一封「检测到新设备登录」
        // 是纯噪声，而噪声会训练用户忽略这封信 —— 那正好毁掉它唯一的作用。
        if ($this->devices->countActiveForUser($user->id()) <= 1) {
            return;
        }

        $this->mail->send(new MailRequest(
            MailTemplate::NewDeviceLogin,
            // ⚠️ 这行 match 不能省成「共用一个 enum」，理由见 ADR-0012 的 Consequences
            // 与 RequestOtpService::send() 上的同款注释。
            match ($user->locale()) {
                Locale::German => MailLocale::German,
                Locale::English => MailLocale::English,
            },
            $user->emailEncrypted(),
            [
                // 机型可能没上报（契约必填，服务端收得更宽）。回落到平台名而不是空串：
                // 信里那一行写着「Gerät: {{ device_model }}」，空着比「Android」更没用。
                'device_model' => $payload->model ?? $payload->platform->value,
                // §6.1：时间一律 RFC 3339 UTC。信是给人看的，但本地化格式化归客户端 ——
                // 服务端不猜用户的时区（我们没存过它）。模板正文已经说明这是 UTC。
                'occurred_at' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                // ⚠️ 没有 GeoIP，所以**不编一个地区**。模板正文写着
                // 「Region 是从网络连接粗略估计的，可能与实际位置不符」——
                // 填一个假地区会把那句话变成谎话，而这封信的全部价值在于可信。
                // 交接：IP → 粗粒度地区归 T-405 或单独立项（需要一份 GeoIP 库 + ROPA 补登记）。
                'approximate_region' => match ($user->locale()) {
                    Locale::German => 'Unbekannt',
                    Locale::English => 'Unknown',
                },
                'revoke_url' => $this->appBaseUrl.self::REVOKE_PATH,
            ],
        ));
    }

    /**
     * RFC 4648 §5 的 base64url：`+/` → `-_`，去掉 `=` 填充。
     *
     * 契约说 refresh token 是「32 字节随机（Base64url），**不透明**」。
     * 不透明的意思是客户端不解析它 —— 但它仍然要能安全地进 JSON、
     * 进 `Authorization` 之外的任何头、进 URL 查询串（T-105 不会这么用，
     * 但 §7.1 没有禁止），所以不能有 `+` `/` `=`。
     */
    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
