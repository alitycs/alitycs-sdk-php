<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Client configuration. Built from an options array in the {@see Client} constructor;
 * unknown keys and out-of-range values throw so a typo fails fast instead of silently
 * running on defaults.
 */
final class Config
{
    public const DEFAULT_ENDPOINT = 'https://api.alitycs.com/events';

    public readonly string $endpoint;

    /**
     * Queue depth that triggers a send. PHP's only reliable flush triggers are size,
     * explicit flush(), and script shutdown — there is no background timer.
     */
    public readonly int $flushSize;

    /**
     * Seconds between opportunistic flushes. **PHP has no background threads**, so this
     * is never a timer: it is checked only while events keep arriving — `add()` compares
     * elapsed wall time since the last flush attempt and flushes if the interval has
     * elapsed. A request whose last event arrives before the interval does will not be
     * sent until flush()/shutdown() or the next event. `0` disables the check entirely.
     */
    public readonly float $flushInterval;

    /** Events buffered before the queue overflows and new arrivals are dropped. */
    public readonly int $maxQueueSize;

    /** Attempts per batch after the first one (5xx / 429 / network errors only). */
    public readonly int $maxRetries;

    /** Per-HTTP-request timeout in milliseconds. */
    public readonly int $timeoutMs;

    /** Inactivity (ms) after which the session id rotates; the anonymous id survives. */
    public readonly int $sessionTimeout;

    public readonly bool $debug;

    /** When false, every event is sent immediately as its own single-event batch. */
    public readonly bool $batching;

    private readonly string $apiKey;

    private const KNOWN_OPTIONS = [
        'endpoint',
        'flushSize',
        'flushInterval',
        'maxQueueSize',
        'maxRetries',
        'timeoutMs',
        'sessionTimeout',
        'debug',
        'batching',
    ];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('apiKey is required');
        }
        $this->apiKey = $apiKey;

        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::KNOWN_OPTIONS, true)) {
                throw new \InvalidArgumentException("Unknown config option \"$key\"");
            }
        }

        $this->endpoint = self::stringOption($options, 'endpoint', self::DEFAULT_ENDPOINT);
        $this->flushSize = self::intOption($options, 'flushSize', 25, min: 1);
        $this->flushInterval = self::floatOption($options, 'flushInterval', 10.0, min: 0.0);
        $this->maxQueueSize = self::intOption($options, 'maxQueueSize', 1000, min: 1);
        $this->maxRetries = self::intOption($options, 'maxRetries', 3, min: 0);
        $this->timeoutMs = self::intOption($options, 'timeoutMs', 10_000, min: 1);
        $this->sessionTimeout = self::intOption($options, 'sessionTimeout', 30 * 60 * 1000, min: 1);
        $this->debug = self::boolOption($options, 'debug', false);
        $this->batching = self::boolOption($options, 'batching', true);

        // Cross-check: the queue cap must leave room for the size trigger to fire. Below
        // flushSize, `add()` starts dropping arrivals before the queue ever reaches the
        // send threshold, so batching degrades into silent drop-everything.
        if ($this->maxQueueSize < $this->flushSize) {
            throw new \InvalidArgumentException(
                "Config option \"maxQueueSize\" ({$this->maxQueueSize}) must be >= \"flushSize\" "
                . "({$this->flushSize}): a queue capped below the flush threshold can never send"
            );
        }
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException("Config option \"$key\" must be a non-empty string");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function intOption(array $options, string $key, int $default, int $min): int
    {
        $value = $options[$key] ?? $default;
        if (!is_int($value)) {
            throw new \InvalidArgumentException("Config option \"$key\" must be an integer");
        }
        if ($value < $min) {
            throw new \InvalidArgumentException("Config option \"$key\" must be >= $min");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function floatOption(array $options, string $key, float $default, float $min): float
    {
        $value = $options[$key] ?? $default;
        // Accept ints for ergonomics (`'flushInterval' => 30`), normalize to float.
        if (!is_float($value) && !is_int($value)) {
            throw new \InvalidArgumentException("Config option \"$key\" must be a number of seconds");
        }
        $float = (float) $value;
        if ($float < $min || !is_finite($float)) {
            throw new \InvalidArgumentException("Config option \"$key\" must be a finite number >= $min");
        }

        return $float;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function boolOption(array $options, string $key, bool $default): bool
    {
        $value = $options[$key] ?? $default;
        if (!is_bool($value)) {
            throw new \InvalidArgumentException("Config option \"$key\" must be a boolean");
        }

        return $value;
    }
}
