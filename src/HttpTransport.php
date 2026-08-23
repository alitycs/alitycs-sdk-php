<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * HTTP delivery over ext-curl with exponential-backoff retry.
 *
 * 5xx, 429, and network errors are retried up to `$maxRetries` times (1s, 2s, 4s …,
 * capped at 10s). Any other 4xx is a permanent rejection and is never retried. `send()`
 * never throws — a batch that exhausts its retries is dropped and logged, so a failing
 * endpoint can never fatal the host application.
 */
final class HttpTransport
{
    private const BACKOFF_BASE_MS = 1000;
    private const BACKOFF_CAP_MS = 10_000;

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
        private readonly ?\Closure $sleepMs = null
    ) {
    }

    /** @return bool true when the batch was accepted (2xx). */
    public function send(BatchPayload $payload): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            Log::write($this->debug, 'Batch failed to encode as JSON — dropped');

            return false;
        }

        $lastError = 'unknown error';

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                $this->sleep((int) min(self::BACKOFF_BASE_MS * 2 ** ($attempt - 1), self::BACKOFF_CAP_MS));
            }

            ['status' => $status, 'error' => $error] = $this->post($body);

            if ($error !== null) {
                $lastError = $error;
            } elseif ($status >= 200 && $status < 300) {
                return true;
            } elseif ($status >= 400 && $status < 500 && $status !== 429) {
                Log::write($this->debug, "Transport: $status — not retrying");

                return false;
            } else {
                $lastError = "HTTP $status";
            }

            if ($attempt < $this->maxRetries) {
                Log::write($this->debug, "Transport: attempt " . ($attempt + 1) . " failed ($lastError), retrying…");
            }
        }

        Log::write($this->debug, "Transport: all retries exhausted — dropping batch: $lastError");

        return false;
    }

    /**
     * @return array{status: int, error: string|null}
     */
    private function post(string $body): array
    {
        $handle = curl_init($this->endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
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
            return ['status' => 0, 'error' => "curl error $errno: $error"];
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'error' => null];
    }

    private function sleep(int $milliseconds): void
    {
        if ($this->sleepMs !== null) {
            ($this->sleepMs)($milliseconds);

            return;
        }

        usleep($milliseconds * 1000);
    }
}
