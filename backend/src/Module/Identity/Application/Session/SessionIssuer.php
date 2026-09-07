<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Session;

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
use App\Shared\Application\Metrics\MetricsInterface;
use App\Shared\Application\Token\AccessTokenSignerInterface;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Random\RandomnessInterface;
use App\Shared\Domain\Token\AccessTokenClaims;

/**
 * 「一条挑战已经被鉴别通过」之后发生的**全部**事情：
 * 注册（首次）→ 设备 → 会话 → 令牌 → 提醒信。
 *
 * ============================================================================
 * 为什么它从 VerifyOtpService 里搬了出来
 * ============================================================================
 * T-104 时只有一个调用方，所以这四个方法是 `VerifyOtpService` 的私有方法。
 * T-106 起有了第二个：Magic Link 的 `POST /v1/auth/magic/consume` 落在**同一条**
 * 挑战上（`otp_challenges` 一行同时挂着 `code_hash` 与 `magic_token_hash`），
 * 鉴别方式不同，鉴别通过之后要做的事**逐字相同**。
 *
 * 复制一份的代价不是重复代码，是**静默分叉**：
 *
 *   - 首次验证即注册（§5.2 / §6.3.1 / ADR-0014）——漏在一条路径上，
 *     那条路径的新用户就登不进去，而它今天没有测试（T-104 的教训原文）；
 *   - 设备 id 属于别人 → 409（不是静默改绑）；
 *   - 新设备提醒信的抑制规则（刚注册的账号第一次登录不发）——
 *     §7.2 T02 的缓解就落在这封信上，两条路径的口径必须一样。
 *
 * ⚠️ 所以「magic consume 只是少校验一步的 verify」这个说法是**反的**：
 * 两个端点不同的只有前半段（怎么鉴别），后半段必须是同一段代码。
 *
 * ============================================================================
 * ⚠️ 本类不做任何鉴别
 * ============================================================================
 * 走到 {@see issue()} 的前提是调用方已经确认过挑战没过期、没被消费、
 * 凭据对得上。本类只在事务最前面调一次 {@see OtpChallenge::consume()} ——
 * 那是并发下的最后一道闸（两个并发请求里只有一个能把 `consumed_at` 从 NULL 写走），
 * **不是**替调用方补校验。
 */
final readonly class SessionIssuer
{
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
     * ✅ T-106 之后它也**有落地页了**：`infra/caddy/site/l/devices/`。
     * 仍然不是 tokenized URL —— 那个形状已经满足 §7.1。
     */
    private const REVOKE_PATH = '/l/devices';

    /**
     * {@see register()} 唯一的失败文案。
     *
     * ⚠️ 与 `VerifyOtpService::REJECTED` **逐字相同**，不是巧合 ——
     * 见 {@see register()} 里的注释。改一处就要改两处。
     */
    private const LEGACY_REJECTED = 'The verification code is not valid.';

    /**
     * @param int<1, max> $accessTtlSeconds  §7.1：900。`ncards.jwt.access_ttl_seconds`
     * @param int<1, max> $refreshTtlSeconds §7.1：90 天。`ncards.jwt.refresh_ttl_seconds`
     * @param string      $appBaseUrl        `APP_PUBLIC_BASE_URL`，结尾不带斜杠
     */
    public function __construct(
        private UserRepositoryInterface $users,
        private OtpChallengeRepositoryInterface $challenges,
        private DeviceRepositoryInterface $devices,
        private SessionRepositoryInterface $sessions,
        private RandomnessInterface $random,
        private UuidGeneratorInterface $uuids,
        private MetricsInterface $metrics,
        private MailSenderInterface $mail,
        private AccessTokenSignerInterface $tokens,
        private TransactionRunnerInterface $transactions,
        private int $accessTtlSeconds,
        private int $refreshTtlSeconds,
        private string $appBaseUrl,
    ) {
    }

    /**
     * @param OtpChallenge $challenge 调用方已鉴别通过的那条挑战
     *
     * @throws DomainException 401 `token_invalid`（并发下挑战已被另一个请求消费）、
     *                         409 `id_conflict`（设备 id 属于别人）
     */
    public function issue(OtpChallenge $challenge, DeviceDescriptor $device, \DateTimeImmutable $now): SessionIssued
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
            function () use ($challenge, $device, $now, $sessionId, $refreshTokenHash): array {
                // 重放同一条挑战时 consume() 自己抛 token_invalid（401）。
                // 放在事务最前面：它是这次登录的「许可证」，拿不到就什么都不该发生。
                //
                // ⚠️ 这也是 Magic Link 与 6 位码之间的互斥点：两者挂在**同一行**上，
                // 谁先消费谁赢，另一条随即 401。
                $challenge->consume($now);
                $this->challenges->save($challenge);

                $user = $this->users->findByEmailHash($challenge->emailHash());
                $registered = null === $user;

                if (null === $user) {
                    $user = $this->register($challenge, $now);
                }

                [$deviceEntity, $isNewDevice] = $this->resolveDevice($user, $device, $now);

                $this->sessions->save(Session::start(
                    $sessionId,
                    $user,
                    $deviceEntity,
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
            $device->id,
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
            $this->notifyNewDevice($user, $device, $now);
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
            //
            // ⚠️ 文案必须与 {@see \App\Module\Identity\Application\Otp\VerifyOtpService}
            // 的拒绝文案**逐字相同**：这条路径能被外部触发，而那个端点的全部 401
            // 是不可区分的（`VerifyOtpServiceTest::testALegacyChallengeWithoutARecipientCannotRegister()`
            // 钉它）。
            //
            // Magic Link 那一侧到不了这里：`magic_token_hash` 从 T-106 起才有写入方，
            // 所以任何带 magic token 的挑战必然也带 `email_encrypted` 与 `locale`。
            throw new DomainException(ErrorCode::TokenInvalid, self::LEGACY_REJECTED);
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
    private function resolveDevice(User $user, DeviceDescriptor $descriptor, \DateTimeImmutable $now): array
    {
        $device = $this->devices->findById($descriptor->id);

        if (null === $device) {
            $device = Device::register(
                $descriptor->id,
                $user,
                $descriptor->platform,
                $descriptor->model,
                $descriptor->osVersion,
                $descriptor->appVersion,
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
            // 返回 409 让客户端知道要重新生成安装 id（契约里两个端点都列了 409）。
            //
            // ⚠️ 这里**不**泄露任何关于那个设备或它主人的信息。
            throw new DomainException(ErrorCode::IdConflict, 'The device id is already registered to another account.');
        }

        // 同一台设备再次登录：机型、系统版本、App 版本都可能变了（§5.2 的
        // describe()），设备管理页显示的应该是最新的那份。
        $device->describe($descriptor->model, $descriptor->osVersion, $descriptor->appVersion);

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
     * 只发信，**不发推送**
     * ============================================================================
     * §7.1 还要求「向该用户所有既有设备发推送」。FCM 整条链路归 T-306（M3），
     * 这里不提前拉进来 —— 那需要 `push_token` 的下发（T-105 的
     * `PUT /v1/me/devices/{id}/push-token`）与一个还不存在的推送 Port。
     * 邮件是两条渠道里更重要的那条：§7.2 T02（邮箱被接管 → 账号被接管）
     * 的缓解就落在这封信上，而推送发到的正是可能已经被控制的那些设备。
     */
    private function notifyNewDevice(User $user, DeviceDescriptor $device, \DateTimeImmutable $now): void
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
                'device_model' => $device->displayName(),
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
