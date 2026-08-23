<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Diagnostics logging. Enabled by the `debug` config option; every message is prefixed
 * with `[Alitycs]` and written to the SAPI log (stderr on the CLI). Never throws.
 */
final class Log
{
    public static function write(bool $enabled, string $message): void
    {
        if (!$enabled) {
            return;
        }

        try {
            error_log('[Alitycs] ' . $message);
        } catch (\Throwable) {
            // A logging failure must never take the host application down.
        }
    }
}
