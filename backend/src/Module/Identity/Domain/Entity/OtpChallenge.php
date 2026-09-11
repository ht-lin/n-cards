<?php

declare(strict_types=1);

namespace App\Module\Identity\Domain\Entity;

use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Shared\Domain\Crypto\Ciphertext;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Error\DomainException;
use App\Shared\Domain\Error\ErrorCode;
use App\Shared\Domain\Identity\Uuid;

/**
 * `otp_challenges`（§5.2）—— 一次 OTP 登录挑战。
 *
 * 类不是 `final`、映射在 XML 里，理由见 {@see User} 的类注释与 ADR-0011。
 *
 * ============================================================================
 * ⚠️ 这张表**没有到 `users` 的外键**
 * ============================================================================
 * 看起来像遗漏，其实是 §3.8 防枚举的承重墙 —— 而且 T-104 之后它比原来更重要。
 *
 * 原本的理由是哑挑战（`is_decoy`）：邮箱不存在时也要建一条挑战，有外键就插不进去。
 * ADR-0014 之后**没有哑挑战了**（两条路径合并成一条），但外键仍然不能加，
 * 理由变成了更根本的一条：`POST /auth/otp/request` **不再查 `users`**，
 * 它在结构上就不知道这个邮箱注册过没有。挑战因此可能先于用户存在 ——
 * 首次验证成功时才建 `users` 行（见 {@see emailEncrypted()}）。
 *
 * 所以这里存的是 `email_hash` 而不是 `user_id`，且没有引用完整性约束。
 *
 * ============================================================================
 * 什么不在这里
 * ============================================================================
 * 这个实体只提供状态迁移，**策略归各自的任务**：
 *   - 6 位码的生成与 `code_hash` 的计算、旧挑战作废、限流两维（T-103）
 *   - 「`attempts` 超过 5 即作废整个挑战」的那个 **5**（T-104，§7.1）——
 *     所以下面是 {@see hasAttemptsLeft()} 收一个上限参数，而不是硬编码
 *   - Magic Link 的 `GET` 不消费 / `POST` 才消费（T-106）
 */
class OtpChallenge
{
    private int $attempts = 0;

    private ?\DateTimeImmutable $consumedAt = null;

    /**
     * 属性不加 `readonly` 的理由见 {@see User::__construct()} 上的注释。
     */
    private function __construct(
        private Uuid $id,
        private HashDigest $emailHash,
        private ?Ciphertext $emailEncrypted,
        private ?Locale $locale,
        private HashDigest $codeHash,
        private OtpPurpose $purpose,
        private \DateTimeImmutable $expiresAt,
        private bool $isDecoy,
        private ?HashDigest $magicTokenHash,
        private ?HashDigest $requestIpHash,
        private \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * 一条登录挑战。**不区分邮箱是否已注册** —— 那正是 ADR-0014 的要点。
     *
     * @param Ciphertext      $emailEncrypted 收件人（`vault:v1:…`）。既用来发这封信，
     *                                        也是首次验证成功时建 `users` 行的输入
     * @param Locale          $locale         客户端选的语言，同上两个用途
     * @param HashDigest      $codeHash       `HMAC-SHA256(code, pepper)`，§7.1 明令**不存明文**
     * @param HashDigest|null $magicTokenHash Magic Link 令牌的哈希（T-106），不发 Magic Link 时为 null
     * @param HashDigest|null $requestIpHash  限流与滥用分析用；ROPA §8.2 规定 30 天后清理（T-113）
     */
    public static function issue(
        Uuid $id,
        HashDigest $emailHash,
        Ciphertext $emailEncrypted,
        Locale $locale,
        HashDigest $codeHash,
        OtpPurpose $purpose,
        \DateTimeImmutable $expiresAt,
        ?HashDigest $magicTokenHash,
        ?HashDigest $requestIpHash,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $emailHash,
            $emailEncrypted,
            $locale,
            $codeHash,
            $purpose,
            $expiresAt,
            false,
            $magicTokenHash,
            $requestIpHash,
            $now,
        );
    }

    /**
     * 哑挑战 —— **T-104（ADR-0014）之后已无生产调用方**。
     *
     * ============================================================================
     * ⚠️ 为什么还留着
     * ============================================================================
     * 它原本是 §3.8 的第一道防线：邮箱不存在时建一条不发信、验证恒失败的挑战，
     * 好让 `POST /auth/otp/request` 的响应体与耗时不可区分。
     *
     * ADR-0014 把两条路径合并成一条（**恒发码**）之后这个概念失去了意义 ——
     * 防枚举从「靠配平两条路径」变成了「服务端根本不查 `users`」，更强也更难写错。
     * ============================================================================
     * T-113 之后它还留着的**唯一**理由：`doctrine:schema:validate`
     * ============================================================================
     * T-113 摘掉了 `VerifyOtpService` 里那次 {@see isDecoy()} 判断（部署窗口早已
     * 过去，理由写在那条布尔链上）。原本的第 1 条理由到此结束。
     *
     * 剩下的是第 2 条，而它比看起来硬：`is_decoy` **列**还在库里，而
     * `doctrine:schema:validate`（CI 的 `composer migration:check` 第 ③ 步）比的是
     * ORM 映射与真库内省结果。把这个属性、`OtpChallenge.orm.xml` 里的 `<field>`
     * 或这个工厂先删掉，diff 里就会多出一条 `DROP COLUMN`，CI 当场红 ——
     * 而 §13.5 与 ADR-0010（生产回滚只回镜像 tag，不回迁移）要求删列必须
     * 晚于切读至少两周、且与映射同一次发布。
     *
     * 所以：**属性、映射、本工厂与 `isDecoy()` 必须一起活到那次发布，再一起消失。**
     * 交接清单见 docs/tasks/M1.md 的 T-113「留给后续任务」一节。
     *
     * ⚠️ 在那之前，新代码**不要**再调它。要表达「这次不发信」，
     * 答案是「不存在这种情况」。它今天唯一的调用方是测试 ——
     * 那些用例验的是「上个版本建的行仍然能正常往返」，也是删列那一刻一起退休的。
     */
    public static function decoy(
        Uuid $id,
        HashDigest $emailHash,
        HashDigest $codeHash,
        OtpPurpose $purpose,
        \DateTimeImmutable $expiresAt,
        ?HashDigest $requestIpHash,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            $id,
            $emailHash,
            null,
            null,
            $codeHash,
            $purpose,
            $expiresAt,
            true,
            null,
            $requestIpHash,
            $now,
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function emailHash(): HashDigest
    {
        return $this->emailHash;
    }

    /**
     * 收件人密文，也是**首次验证成功时建 `users` 行的输入**（§5.2 / ADR-0014）。
     *
     * 为 null 只有一种情形：本列上线（T-104 的迁移）之前建的行，含哑挑战。
     * 验证侧遇到「查不到用户 且 这里是 null」时返回 401 —— 无法注册，
     * 而那些行的寿命只有 10 分钟。
     */
    public function emailEncrypted(): ?Ciphertext
    {
        return $this->emailEncrypted;
    }

    /**
     * 请求验证码时客户端选的语言。注册时进 `users.locale`。
     *
     * 为 null 的情形同 {@see emailEncrypted()}。
     */
    public function locale(): ?Locale
    {
        return $this->locale;
    }

    public function codeHash(): HashDigest
    {
        return $this->codeHash;
    }

    public function magicTokenHash(): ?HashDigest
    {
        return $this->magicTokenHash;
    }

    public function purpose(): OtpPurpose
    {
        return $this->purpose;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * 记一次验证尝试，**到 `$max` 为止饱和**。
     *
     * ⚠️ **成功的验证也要先记一次**再比对 —— 否则「码错了」与「码对了」在
     * `attempts` 上留下的痕迹不同，而 §3.8 要求两条路径不可区分。
     * 顺序由 `VerifyOtpService` 负责，这里只提供计数。
     *
     * ============================================================================
     * 为什么是饱和加法，而不是一直加下去
     * ============================================================================
     * 上限用完之后，调用方**仍然会**继续调它 —— 那是刻意的：
     * 「次数耗尽」这种拒绝必须与「码错了」做同样多的功（同一次 UPDATE），
     * 否则两者的耗时可分，而攻击者能用那个差别免费探测「这条挑战被试过几次」。
     *
     * 于是这一列会被一条已经死掉的挑战反复写。不封顶的话它是**无界**的：
     * `attempts` 是 SMALLINT，攻击者用足够多的源 IP 打同一个 `challenge_id`
     * （每 IP 60/h，挑战活 10 分钟）能把它顶过 32767，那时 PG 会拒绝 UPDATE，
     * 于是那条挑战上的每一次请求都变成 500 —— 一个由外部输入触发的错误。
     *
     * 饱和把那条路径关掉，代价是失去「被打了多少次」这个数。
     * 那个数本来也不该记在这里：滥用计数归 §14.4 的
     * `login_total{result}` 与限流器，它们的保留期与聚合方式都是为此设计的。
     *
     * @param int $max §7.1 的最大尝试次数（5）。**由调用方传入**：
     *                 这个数字是策略不是不变量，与 {@see hasAttemptsLeft()} 同源
     */
    public function recordAttempt(int $max): void
    {
        if ($this->attempts < $max) {
            ++$this->attempts;
        }
    }

    /**
     * @param int $max §7.1 的最大尝试次数（5）。**由调用方传入**：
     *                 这个数字是策略不是不变量，归 T-104
     */
    public function hasAttemptsLeft(int $max): bool
    {
        return $this->attempts < $max;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function consumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    /**
     * 消费掉这条挑战 —— 验证成功，或 Magic Link 被 `POST` 消费。
     *
     * @throws DomainException `token_invalid`（401）—— 已经消费过了
     *
     * ⚠️ 重复消费**必须抛**而不是静默返回：T-106 的验收标准写明
     * 「`POST` 消费一次后重复 POST 返回 401」。静默成功等于把一次性令牌变成可重放的。
     * 库层没有约束能拦住这件事（`consumed_at` 只是一列时间戳），
     * 所以这条不变量只在这里。
     */
    public function consume(\DateTimeImmutable $at): void
    {
        if (null !== $this->consumedAt) {
            throw new DomainException(ErrorCode::TokenInvalid, 'The OTP challenge has already been consumed.');
        }

        $this->consumedAt = $at;
    }

    /**
     * 哑挑战（§3.8）—— 邮箱不存在时建的，验证必须恒失败。
     *
     * ⚠️ **T-113 起没有生产调用方了**：`VerifyOtpService` 不再看这一位。
     * 留着不是遗漏 —— 它与 `is_decoy` 列、XML 映射、{@see decoy()} 绑在一起，
     * 必须同一次发布一起消失，否则 `doctrine:schema:validate` 会报「不同步」。
     * 完整论证见 {@see decoy()} 的注释。
     */
    public function isDecoy(): bool
    {
        return $this->isDecoy;
    }

    public function requestIpHash(): ?HashDigest
    {
        return $this->requestIpHash;
    }

    /**
     * 清空 `request_ip_hash`（ROPA §8.2：30 天后清理）。由 T-113 的每日任务调用。
     */
    public function forgetRequestIp(): void
    {
        $this->requestIpHash = null;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
