<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * HTTP delivery over ext-curl with exponential-backoff retry.
 *
 * 5xx, 429, and network errors are retried up to `$maxRetries` times (1s, 2s, 4s …,
 * capped at 10s). A 429 carrying a `Retry-After` header (delta-seconds or an HTTP-date)
 * is honoured: the attempt after it waits at least that long instead of the default
 * backoff. Any other 4xx is a permanent rejection and is never retried; neither are 3xx
 * responses — the ingest endpoint has no redirects, and retrying one only burns the
 * retry budget on the same answer. `send()` never throws — a batch that exhausts its
 * retries is dropped and logged, so a failing endpoint can never fatal the host
 * application. When persistence is configured, a retry-exhausted transient batch stays
 * in an exact-body write-ahead log for replay by the next client process.
 */
final class HttpTransport
{
    private const BACKOFF_BASE_MS = 1000;
    private const BACKOFF_CAP_MS = 10_000;
    private const RETRY_AFTER_CAP_MS = 60_000;

    private readonly FileBatchStore $store;

    /**
     * @param int $maxRetries attempts after the first one
     * @param (\Closure(int): void)|null $sleepMs millisecond backoff; injectable for tests
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly int $maxRetries = 3,
        private readonly int $timeoutMs = 10_000,
        private readonly bool $debug = false,
        private readonly ?\Closure $sleepMs = null,
        ?string $persistencePath = null,
        int $maxPendingEvents = 1000,
    ) {
        $this->store = new FileBatchStore($persistencePath, $maxPendingEvents);
    }

    /** @return bool true when the batch was accepted (2xx). */
    public function send(BatchPayload $payload): bool
    {
        // \JSON_INVALID_UTF8_SUBSTITUTE keeps a single ill-formed byte sequence from
        // failing the encode of the whole batch (it becomes U+FFFD instead).
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            Log::warn('Batch failed to encode as JSON — dropped: ' . json_last_error_msg());

            return false;
        }

        try {
            $this->store->put($payload->batchId, $body, count($payload->events));

            return $this->sendRecord($payload->batchId, $body)->isAccepted();
        } catch (\Throwable $throwable) {
            Log::warn('Transport persistence failed — delivery unresolved: ' . $throwable->getMessage());

            return false;
        }
    }

    /** Outcome-preserving form used by the batch manager. */
    public function sendWithOutcome(BatchPayload $payload): DeliveryResult
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            Log::warn('Batch failed to encode as JSON — dropped: ' . json_last_error_msg());

            return DeliveryResult::rejected(0);
        }

        try {
            $this->store->put($payload->batchId, $body, count($payload->events));

            return $this->sendRecord($payload->batchId, $body);
        } catch (\Throwable $throwable) {
            if ($this->store->contains($payload->batchId)) {
                Log::warn(
                    'Transport persistence failed after durable ownership was established'
                    . ' — exact batch retained: ' . $throwable->getMessage()
                );

                return DeliveryResult::transient();
            }

            throw $throwable;
        }
    }

    /** Persists a batch without attempting the network; used by shutdown fallback. */
    public function persist(BatchPayload $payload): bool
    {
        try {
            $body = json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | \JSON_INVALID_UTF8_SUBSTITUTE
                | JSON_THROW_ON_ERROR
            );
            $this->store->put($payload->batchId, $body, count($payload->events));

            return $this->store->contains($payload->batchId);
        } catch (\Throwable $throwable) {
            Log::warn('Shutdown persistence failed: ' . $throwable->getMessage());

            return false;
        }
    }

    /** Replays every persisted batch body without regenerating any identity fields. */
    public function recover(): bool
    {
        foreach ($this->store->snapshot() as $record) {
            $pausedUntilMs = $record['pausedUntilMs'];
            if ($pausedUntilMs !== null && $pausedUntilMs > self::nowMs()) {
                // The record is already durable. Preserve FIFO ordering and let a
                // later flush retry after the pause instead of blocking this caller.
                return false;
            }

            $outcome = $this->sendRecord($record['batchId'], $record['body']);
            // Terminal responses have already been acknowledged; only a transient
            // failure stops ordered recovery of later records.
            if ($outcome->isTransient()) {
                return false;
            }
        }

        return true;
    }

    public function pendingDurableEvents(): int
    {
        return $this->store->pendingEvents();
    }

    public function durableEnabled(): bool
    {
        return $this->store->enabled();
    }

    public function resetForChild(): void
    {
        if ($this->store->resetForChild()) {
            Log::warn(
                'Fork detected — inherited persistence disabled in child; create a new client '
                . 'with a child-specific persistencePath for durable child delivery'
            );
        }
    }

    private function sendRecord(string $batchId, string $body): DeliveryResult
    {

        $lastError = 'unknown error';
        $retryAfterMs = null;
        $retryAfterUntilMs = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                // SDK-generated exponential backoff is capped. A server Retry-After is
                // authoritative and is therefore not shortened to that client cap.
                $delayMs = (int) min(
                    self::BACKOFF_BASE_MS * 2 ** min($attempt - 1, 30),
                    self::BACKOFF_CAP_MS
                );
                if ($retryAfterMs !== null) {
                    $delayMs = $retryAfterMs;
                    $retryAfterMs = null;
                }
                $this->sleep($delayMs);
            }

            ['status' => $status, 'error' => $error, 'headers' => $headers] = $this->post($body);

            if ($error !== null) {
                $lastError = $error;
            } elseif ($status >= 200 && $status < 300) {
                $this->store->acknowledge($batchId);

                return DeliveryResult::accepted($status);
            } elseif ($status >= 300 && $status < 400) {
                // Terminal: redirects are never followed (CURLOPT_FOLLOWLOCATION is off)
                // and re-posting the batch to a 3xx answer cannot change that answer.
                Log::warn("Transport: HTTP $status — redirect responses are terminal, not retrying");

                $this->store->acknowledge($batchId);

                return DeliveryResult::rejected($status);
            } elseif ($status >= 400 && $status < 500 && $status !== 429) {
                Log::warn("Transport: HTTP $status — batch rejected, not retrying");

                $this->store->acknowledge($batchId);

                return DeliveryResult::rejected($status);
            } else {
                if ($status === 429) {
                    $retryAfterMs = $this->parseRetryAfterMs($headers);
                    $retryAfterUntilMs = $retryAfterMs === null ? null : self::nowMs() + $retryAfterMs;
                }
                $lastError = "HTTP $status";
            }

            if ($attempt < $this->maxRetries) {
                Log::write($this->debug, "Transport: attempt " . ($attempt + 1) . " failed ($lastError), retrying…");
            }
        }

        if ($this->store->enabled()) {
            $this->store->pause($batchId, $retryAfterUntilMs);
            Log::warn("Transport: all retries exhausted — batch retained for restart: $lastError");
        } else {
            Log::warn("Transport: all retries exhausted — batch not delivered: $lastError");
        }

        return DeliveryResult::transient(
            str_starts_with($lastError, 'HTTP ') ? (int) substr($lastError, 5) : null,
            $retryAfterUntilMs,
        );
    }

    /**
     * Parses a `Retry-After` header value into milliseconds: a delta-seconds integer or
     * an HTTP-date. Returns null when absent or unparseable; a past date yields zero.
     *
     * @param array<string, list<string>> $headers lowercase header names collected by post()
     */
    private function parseRetryAfterMs(array $headers): ?int
    {
        $value = $headers['retry-after'][0] ?? null;
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^\d+$/', $value) === 1) {
            $seconds = filter_var($value, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => intdiv(PHP_INT_MAX, 1000)],
            ]);

            return $seconds === false
                ? null
                : min($seconds * 1000, self::RETRY_AFTER_CAP_MS);
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return min(max(0, $timestamp - time()) * 1000, self::RETRY_AFTER_CAP_MS);
    }

    /**
     * @return array{status: int, error: string|null, headers: array<string, list<string>>}
     */
    private function post(string $body): array
    {
        // Response headers are collected case-insensitively (names lower-cased) so the
        // retry loop can read Retry-After without depending on the server's casing.
        $headers = [];
        $collectHeader = function (object $handle, string $line) use (&$headers): int {
            $length = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }

            return $length;
        };

        $handle = curl_init($this->endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_HEADERFUNCTION => $collectHeader,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($this->timeoutMs, 5000),
        ]);

        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);

            // No explicit curl_close(): the handle self-releases on scope exit (and the
            // call is deprecated as of PHP 8.5).
            return ['status' => 0, 'error' => "curl error $errno: $error", 'headers' => $headers];
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'error' => null, 'headers' => $headers];
    }

    private function sleep(int $milliseconds): void
    {
        if ($this->sleepMs !== null) {
            ($this->sleepMs)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }

    private static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
