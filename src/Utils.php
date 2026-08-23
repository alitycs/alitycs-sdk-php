<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Shared helpers: identifier generation and property serialization.
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
     */
    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    /**
     * Flattens caller-supplied properties into the string-valued map the wire contract
     * requires. Null values are omitted entirely; scalars are stringified; arrays and
     * objects are JSON-encoded so their shape survives transport.
     */
    public static function serializeProperties(array $properties): array
    {
        $serialized = [];

        foreach ($properties as $key => $value) {
            if ($value === null) {
                continue;
            }

            $serialized[(string) $key] = match (true) {
                is_string($value) => $value,
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
        }

        return $serialized;
    }

    private static function encodeJson(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Only reachable for structures JSON cannot represent (e.g. closures nested in
        // an array). The placeholder keeps the event schema-valid instead of fataling.
        return $json === false ? '[' . get_debug_type($value) . ']' : $json;
    }
}
