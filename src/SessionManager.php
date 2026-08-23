<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Session and anonymous identity for the lifetime of this client instance.
 *
 * A PHP server process has no persistent device storage, so unlike a browser SDK there
 * is nothing to restore: each `Client` starts a fresh session, `identify()` attaches a
 * user id to it, inactivity past `$sessionTimeout` rotates the session id while keeping
 * the anonymous id, and {@see reset()} rotates both and clears the user.
 */
final class SessionManager
{
    private SessionData $session;

    /**
     * @param int $sessionTimeout inactivity threshold in milliseconds
     * @param (\Closure(): float)|null $clock seconds-since-epoch; injectable for tests
     */
    public function __construct(
        private readonly int $sessionTimeout,
        private readonly ?\Closure $clock = null
    ) {
        $this->session = $this->create();
    }

    public function getSession(): SessionData
    {
        return $this->session;
    }

    /** Refreshes activity, rotating the session id if it expired (anonymous id kept). */
    public function touch(): void
    {
        if ($this->isExpired()) {
            $this->session = $this->create($this->session->anonymousId);

            return;
        }

        $this->session = new SessionData(
            $this->session->id,
            $this->session->anonymousId,
            $this->session->userId,
            $this->session->startTime,
            $this->nowMs()
        );
    }

    public function setUserId(string $userId): void
    {
        $this->session = new SessionData(
            $this->session->id,
            $this->session->anonymousId,
            $userId,
            $this->session->startTime,
            $this->nowMs()
        );
    }

    /** Rotates session and anonymous ids and clears the identified user. */
    public function reset(): SessionData
    {
        $this->session = $this->create();

        return $this->session;
    }

    private function isExpired(): bool
    {
        return $this->nowMs() - $this->session->lastActivity > $this->sessionTimeout;
    }

    private function create(?string $anonymousId = null): SessionData
    {
        return new SessionData(
            'sess_' . Utils::generateId(),
            $anonymousId ?? 'anon_' . Utils::generateId(),
            null,
            $this->nowMs(),
            $this->nowMs(),
        );
    }

    private function nowMs(): int
    {
        $clock = $this->clock ?? static fn (): float => microtime(true);

        return (int) round($clock() * 1000);
    }
}
