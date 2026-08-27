<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Diagnostics logging. `write()` is debug-gated via the `debug` config option; `warn()`
 * always logs because dropped or rejected events must be visible by default. Every
 * message is prefixed with `[Alitycs]` and written to the SAPI log (stderr on the CLI).
 * Never throws.
 */
final class Log
{
    public static function write(bool $enabled, string $message): void
    {
        if (!$enabled) {
            return;
        }

        self::emit($message);
    }

    /** Warn-level: never debug-gated. */
    public static function warn(string $message): void
    {
        self::emit('WARN ' . $message);
    }

    private static function emit(string $message): void
    {
        try {
            error_log('[Alitycs] ' . $message);
        } catch (\Throwable) {
            // A logging failure must never take the host application down.
        }
    }
}
