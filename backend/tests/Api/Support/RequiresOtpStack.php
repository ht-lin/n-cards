<?php

declare(strict_types=1);

namespace App\Tests\Api\Support;

use App\Module\Identity\Domain\Entity\OtpChallenge;
use App\Module\Identity\Domain\Entity\User;
use App\Module\Identity\Domain\Repository\OtpChallengeRepositoryInterface;
use App\Module\Identity\Domain\Repository\UserRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\Locale;
use App\Module\Identity\Domain\ValueObject\OtpPurpose;
use App\Shared\Application\Crypto\CryptoServiceInterface;
use App\Shared\Application\Crypto\HmacHasherInterface;
use App\Shared\Domain\Crypto\CryptoKey;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\UuidGeneratorInterface;
use App\Shared\Domain\Time\ClockInterface;
use App\Shared\Infrastructure\Redis\RedisConnectionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `POST /v1/auth/otp/request` 的端到端用例共用的启动、跳过与清理逻辑。
 *
 * ============================================================================
 * 为什么不能直接 use RequiresIdentitySchema
 * ============================================================================
 * 那个 trait 第一行就是 `self::bootKernel()`，而 `WebTestCase::createClient()`
 * 要求内核**还没**启动过（否则抛「Booting the kernel before calling
 * createClient() is not supported」）。所以这里的顺序反过来：先建 client，
 * 再从它的容器里取连接。事务与回滚的语义与那个 trait 一致。
 *
 * ============================================================================
 * 这个端点要三样东西都活着
 * ============================================================================
 * 与只碰一个后端的用例不同，本端点一次请求里同时用到：
 *
 *   Postgres —— 每次都要写 otp_challenges，没有「只跑控制器」这个选项
 *   Vault    —— email/code/ip 三次 HMAC
 *   Redis    —— §7.5 的两条限流策略，而它们是 **fail-closed** 的
 *
 * 最后一条特别容易踩：Redis 不可达时限流按 ADR-0005 返回 **503 而不是 429**
 * （与幂等的 fail-open 刻意相反）。裸机上不 skip 的话，整组用例会以
 * 「期待 202，实际 503」的形式红，而根因跟被测代码毫无关系。
 */
trait RequiresOtpStack
{
    private KernelBrowser $client;

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    /**
     * 建 client + 确认三个后端可用 + 开一个会被回滚的事务。
     */
    private function bootOtpStack(): void
    {
        $this->client = static::createClient();
        // 要看的是**响应**（状态码 + header + body），不是异常 ——
        // 与 ProblemDetailsContractTest / RateLimitTest 一致。
        $this->client->catchExceptions(true);
        // 内核重启会把下面这个事务连同连接一起丢掉。
        $this->client->disableReboot();

        $container = static::getContainer();

        /** @var RedisConnectionFactory $redis */
        $redis = $container->get(RedisConnectionFactory::class);

        try {
            $redis->create()->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis 不可达（'.$e->getMessage().'）—— 限流 fail-closed，全部请求会是 503。起 compose 栈后再跑。');
        }

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);

        try {
            // 最便宜的可用性探测：真打一次 transit/hmac。连不上或没 bootstrap
            // 都会在这里现形，而不是伪装成一个 500。
            $hasher->hash('otp-stack-probe');
        } catch (\Throwable $e) {
            self::markTestSkipped('Vault 不可达或未初始化（'.$e->getMessage().'）。起 compose 栈后再跑。');
        }

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        try {
            $connection->executeQuery('SELECT 1')->free();
        } catch (\Throwable $e) {
            self::markTestSkipped('Postgres 不可达（'.$e->getMessage().'）。');
        }

        if (null === $connection->fetchOne("SELECT to_regclass('otp_challenges')")) {
            self::markTestSkipped('Identity 的表还没建 —— 先跑 `composer migration:check`。');
        }

        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        $this->connection = $connection;
        $this->entityManager = $entityManager;

        $this->connection->beginTransaction();
    }

    private function rollbackOtpStack(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if (isset($this->entityManager)) {
            $this->entityManager->clear();
        }
    }

    /**
     * 每条请求用一个**新的**客户端 IP。
     *
     * ⚠️ 不这么做的话整组用例会互相干扰：`otp_request_ip` 是 20/h，
     * 而 Redis 里的计数**不在**测试事务里、回滚不掉。固定 IP 的话第 21 个请求
     * 开始全是 429，症状是「单跑绿、全跑红」，而且一小时内重跑还是红。
     *
     * 用 TEST-NET-3（RFC 5737，保证不是真实地址）的一段。
     */
    private static function uniqueIp(): string
    {
        return \sprintf('203.0.113.%d', random_int(1, 254));
    }

    /**
     * 每条用例用一个**新**邮箱，理由同上（`otp_request_email` 是 1/min）。
     */
    private static function uniqueEmail(): string
    {
        return 'anna-'.bin2hex(random_bytes(8)).'@example.de';
    }

    /**
     * 直接种一条**码已知**的挑战，绕过 `POST /auth/otp/request`。
     *
     * ============================================================================
     * 为什么不走真实的两步流程
     * ============================================================================
     * 真码只出现在邮件里，而 test 环境的 `MAILER_DSN=null://null` + 异步 transport
     * 意味着它躺在 `messenger_messages.body` 里、还被 `EncryptedMailSerializer`
     * 用 Vault Transit 加密过。为了拿一个六位数字去解一条队列消息，
     * 会把 verify 的用例绑死在 T-102 的序列化格式上 —— 那条格式一改，
     * 这里全红，而根因跟 verify 毫无关系。
     *
     * 所以这里用与生产**完全相同**的构造路径（同一个 `HmacHasherInterface`、
     * 同一个 `CryptoServiceInterface`、同一个仓储）自己种一条，只是码由用例指定。
     * 真实的「request → 收信 → verify」闭环由 T-104 的手工验证覆盖，
     * 那一步在 docs/tasks/M1.md 的交付回顾里有记录。
     *
     * @param string|null $email 省略即随机。已注册与未注册的区别由调用方
     *                           自己决定要不要先建 users 行
     */
    private function seedChallenge(string $code, ?string $email = null, ?\DateTimeImmutable $expiresAt = null): OtpChallenge
    {
        $container = static::getContainer();

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var UuidGeneratorInterface $uuids */
        $uuids = $container->get(UuidGeneratorInterface::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        /** @var OtpChallengeRepositoryInterface $challenges */
        $challenges = $container->get(OtpChallengeRepositoryInterface::class);

        $now = $clock->now();
        $email ??= self::uniqueEmail();

        $challenge = OtpChallenge::issue(
            $uuids->generate(),
            HashDigest::fromRaw($hasher->hash($email)),
            $crypto->encrypt(CryptoKey::Pii, $email),
            Locale::German,
            HashDigest::fromRaw($hasher->hash($code)),
            OtpPurpose::Login,
            $expiresAt ?? $now->modify('+600 seconds'),
            null,
            null,
            $now,
        );

        $challenges->save($challenge);

        return $challenge;
    }

    /**
     * 种一个**已注册**用户，邮箱哈希与 {@see seedChallenge()} 用的一致。
     */
    private function seedUser(string $email): User
    {
        $container = static::getContainer();

        /** @var HmacHasherInterface $hasher */
        $hasher = $container->get(HmacHasherInterface::class);
        /** @var CryptoServiceInterface $crypto */
        $crypto = $container->get(CryptoServiceInterface::class);
        /** @var UuidGeneratorInterface $uuids */
        $uuids = $container->get(UuidGeneratorInterface::class);
        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        /** @var UserRepositoryInterface $users */
        $users = $container->get(UserRepositoryInterface::class);

        $user = User::register(
            $uuids->generate(),
            HashDigest::fromRaw($hasher->hash($email)),
            $crypto->encrypt(CryptoKey::Pii, $email),
            Locale::German,
            $clock->now(),
        );

        $users->save($user);

        return $user;
    }
}
