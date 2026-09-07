<?php

declare(strict_types=1);

namespace App\Tests\Double\Identity;

use App\Module\Identity\Domain\Entity\Session;
use App\Module\Identity\Domain\Repository\SessionRepositoryInterface;
use App\Module\Identity\Domain\ValueObject\SessionRevokedReason;
use App\Shared\Domain\Crypto\HashDigest;
use App\Shared\Domain\Identity\Uuid;

/**
 * 进程内的 `sessions` 仓储。
 *
 * `uq_sessions_refresh_token_hash` 那条唯一约束由真 Postgres 上的
 * `DoctrineSessionRepositoryTest` 覆盖；这里只负责让编排用例能读回刚存的那条。
 */
final class InMemorySessionRepository implements SessionRepositoryInterface
{
    /** @var list<Session> */
    private array $sessions = [];

    public function save(Session $session): void
    {
        foreach ($this->sessions as $index => $existing) {
            if ($existing->id()->equals($session->id())) {
                $this->sessions[$index] = $session;

                return;
            }
        }

        $this->sessions[] = $session;
    }

    public function findById(Uuid $id): ?Session
    {
        foreach ($this->sessions as $session) {
            if ($session->id()->equals($id)) {
                return $session;
            }
        }

        return null;
    }

    public function findByRefreshTokenHash(HashDigest $refreshTokenHash): ?Session
    {
        foreach ($this->sessions as $session) {
            if ($session->refreshTokenHash()->equals($refreshTokenHash)) {
                return $session;
            }
        }

        return null;
    }

    public function findByPreviousTokenHash(HashDigest $previousTokenHash): ?Session
    {
        foreach ($this->sessions as $session) {
            $previous = $session->previousTokenHash();

            if (null !== $previous && $previous->equals($previousTokenHash)) {
                return $session;
            }
        }

        return null;
    }

    public function revokeAllForDevice(
        Uuid $deviceId,
        Uuid $userId,
        SessionRevokedReason $reason,
        \DateTimeImmutable $now,
    ): int {
        $revoked = 0;

        foreach ($this->sessions as $session) {
            if (!$session->device()->id()->equals($deviceId) || !$session->user()->id()->equals($userId)) {
                continue;
            }

            if ($session->isRevoked()) {
                continue;
            }

            // 与真实现一样走实体方法，「首个 reason 胜出」因此在两边同源。
            $session->revoke($reason, $now);
            ++$revoked;
        }

        return $revoked;
    }

    /**
     * @return list<Session>
     */
    public function all(): array
    {
        return $this->sessions;
    }

    public function count(): int
    {
        return \count($this->sessions);
    }

    /**
     * 唯一被存下的那条会话 —— 一次成功的登录恰好建一条。
     */
    public function only(): Session
    {
        if (1 !== \count($this->sessions)) {
            throw new \LogicException(\sprintf('Expected exactly one session, got %d.', \count($this->sessions)));
        }

        return $this->sessions[0];
    }
}
