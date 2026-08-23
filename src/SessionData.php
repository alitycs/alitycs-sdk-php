<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Immutable snapshot of the current session identity.
 */
final class SessionData
{
    public function __construct(
        public readonly string $id,
        public readonly string $anonymousId,
        public readonly ?string $userId,
        public readonly int $startTime,
        public readonly int $lastActivity,
    ) {
    }
}
