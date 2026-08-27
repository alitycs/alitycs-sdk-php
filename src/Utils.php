<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Shared helpers: identifier generation, canonical limits, property serialization.
 */
final class Utils
{
    /**
     * UUID v4, matching the format the JS and JVM SDKs emit (`evt_`, `anon_`, `sess_`,
     * `batch_` prefixes are applied by the callers).
     */
    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        // Set the version (4) and variant (RFC 4122) bits.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    /**
     * Current wall-clock time as a Unix timestamp in milliseconds — the schema's
     * `timestamp` / `sentAt` unit.
     *
     * Seconds and microseconds are handled separately (`gettimeofday()` already returns
     * integer microseconds) so the arithmetic stays in exact integer territory on
     * 64-bit platforms; `(int) round(microtime(true) * 1000)` routes through a float
     * whose low bits can wobble by ±1 ms. 32-bit builds (PHP_INT_SIZE === 4) cannot
     * hold epoch milliseconds as an int (~2^41 today), so they keep the float form —
     * best-effort there: millisecond-accurate until the mantissa runs out, degrading
     * to coarser resolution afterwards.
     */
    public static function nowMs(): int
    {
        if (\PHP_INT_SIZE < 8) {
            return (int) round(microtime(true) * 1000);
        }

        $now = gettimeofday();

        return $now['sec'] * 1000 + intdiv($now['usec'], 1000);
    }

    /**
     * Flattens caller-supplied properties into the string-valued map the wire contract
     * requires. Null values are omitted entirely; scalars are stringified; arrays and
     * objects are JSON-encoded so their shape survives transport.
     *
     * Values containing ill-formed UTF-8 are sanitized to valid UTF-8 (invalid sequences
     * become U+FFFD) — the ingest endpoint is JSON and would otherwise refuse the whole
     * batch. User data is never truncated.
     *
     * @throws EventRejectedError when the property set violates {@see Limits}: more than
     *                             50 properties, a key over 100 chars, or a value over
     *                             1000 chars. Such events must not be queued because the
     *                             server would reject the entire batch.
     */
    public static function serializeProperties(array $properties): array
    {
        if (count($properties) > Limits::MAX_PROPERTIES_COUNT) {
            throw new EventRejectedError(
                'Event rejected locally: ' . count($properties) . ' properties exceeds the maximum of '
                . Limits::MAX_PROPERTIES_COUNT . ' per event'
            );
        }

        $serialized = [];

        foreach ($properties as $key => $value) {
            if ($value === null) {
                continue;
            }

            $key = self::sanitizeUtf8((string) $key);
            if (strlen($key) > Limits::MAX_PROPERTY_KEY_LENGTH) {
                throw new EventRejectedError(
                    "Event rejected locally: property key \"{$key}\" exceeds the maximum of "
                    . Limits::MAX_PROPERTY_KEY_LENGTH . ' characters'
                );
            }

            $serialized[$key] = match (true) {
                is_string($value) => self::sanitizeUtf8($value),
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value) => (string) $value,
                is_float($value) => (string) $value,
                $value instanceof \BackedEnum => (string) $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                is_array($value),
                $value instanceof \stdClass,
                $value instanceof \JsonSerializable => self::encodeJson($value),
                $value instanceof \Stringable => (string) $value,
                default => self::encodeJson($value),
            };

            if (strlen($serialized[$key]) > Limits::MAX_PROPERTY_VALUE_LENGTH) {
                throw new EventRejectedError(
                    "Event rejected locally: value for property key \"{$key}\" exceeds the maximum of "
                    . Limits::MAX_PROPERTY_VALUE_LENGTH . ' characters'
                );
            }
        }

        return $serialized;
    }

    private static function encodeJson(mixed $value): string
    {
        // \JSON_INVALID_UTF8_SUBSTITUTE keeps ill-formed UTF-8 inside structures from
        // failing the encode (it substitutes U+FFFD instead).
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );

        // Only reachable for structures JSON cannot represent (e.g. closures nested in
        // an array). The placeholder keeps the event schema-valid instead of fataling.
        return $json === false ? '[' . get_debug_type($value) . ']' : $json;
    }

    /**
     * Returns `$value` unchanged when it is already well-formed UTF-8; otherwise swaps
     * ill-formed byte sequences for U+FFFD using the first available encoder.
     */
    public static function sanitizeUtf8(string $value): string
    {
        if (preg_match('//u', $value) === 1) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            /** @var string|false $converted */
            $converted = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

            return is_string($converted) ? $converted : '';
        }

        if (function_exists('iconv')) {
            /** @var string|false $converted */
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

            return is_string($converted) ? $converted : '';
        }

        // No encoder available: keep the ASCII prefix and mark each invalid run with
        // U+FFFD so the payload stays deliverable. Best-effort by definition.
        return (string) preg_replace('/[\x80-\xFF]+/', "\u{FFFD}", $value);
    }

    /**
     * Validates a fully built event against {@see Limits}: required identifiers,
     * epoch-millis timestamp age bounds, and the estimated wire size. Property count and
     * lengths are enforced in {@see serializeProperties}.
     *
     * @throws EventRejectedError listing every violation.
     */
    public static function validateEvent(AnalyticsEvent $event): void
    {
        $errors = [];

        if ($event->event === '' || trim($event->event) === '') {
            $errors[] = 'action is required and cannot be blank';
        }
        if (($event->userId === null || trim($event->userId) === '')
            && trim($event->anonymousId) === ''
        ) {
            $errors[] = 'at least one of userId or anonymousId is required';
        }

        $timestamp = $event->timestamp;
        if ($timestamp < Limits::MIN_EPOCH_MILLIS) {
            $errors[] = "timestamp must be epoch milliseconds (got {$timestamp}, which looks like seconds-scale)";
        } else {
            $now = self::nowMs();
            $maxAge = $now - Limits::MAX_EVENT_AGE_DAYS * 24 * 60 * 60 * 1000;
            if ($timestamp > $now) {
                $errors[] = 'timestamp cannot be in the future';
            } elseif ($timestamp < $maxAge) {
                $errors[] = 'timestamp is too old (older than ' . Limits::MAX_EVENT_AGE_DAYS . ' days)';
            }
        }

        foreach ($event->properties as $key => $value) {
            if (strlen((string) $key) > Limits::MAX_PROPERTY_KEY_LENGTH) {
                $errors[] = "property key \"{$key}\" exceeds the maximum of "
                    . Limits::MAX_PROPERTY_KEY_LENGTH . ' characters';
            }
            if (is_string($value) && strlen($value) > Limits::MAX_PROPERTY_VALUE_LENGTH) {
                $errors[] = "value for property key \"{$key}\" exceeds the maximum of "
                    . Limits::MAX_PROPERTY_VALUE_LENGTH . ' characters';
            }
        }

        $estimatedSize = strlen($event->userId ?? '')
            + strlen($event->anonymousId)
            + strlen($event->event)
            + array_sum(array_map(
                static fn ($key, $value): int => strlen((string) $key) + strlen(is_string($value) ? $value : (string) $value),
                array_keys($event->properties),
                array_values($event->properties)
            ))
            + Limits::EVENT_SIZE_OVERHEAD;
        if ($estimatedSize > Limits::MAX_EVENT_SIZE_BYTES) {
            $errors[] = "event size (~{$estimatedSize} bytes) exceeds the maximum allowed size "
                . '(' . Limits::MAX_EVENT_SIZE_BYTES . ' bytes)';
        }

        if ($errors !== []) {
            throw new EventRejectedError('Event rejected locally: ' . implode('; ', $errors));
        }
    }
}
