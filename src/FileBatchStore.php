<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Small file-backed write-ahead log for exact serialized in-flight batches.
 *
 * A single client process owns a persistence path. Each mutation replaces the whole
 * snapshot atomically so a crash exposes either the previous complete file or the next.
 */
final class FileBatchStore
{
    /** @var array<string, array{batchId: string, body: string, eventCount: int, pausedUntilMs: int|null}> */
    private array $records = [];

    public function __construct(private readonly ?string $path)
    {
        if ($path === null) {
            return;
        }

        if (is_file($path)) {
            $raw = file_get_contents($path);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (!is_array($decoded) || ($decoded['version'] ?? null) !== 1 || !is_array($decoded['batches'] ?? null)) {
                throw new \RuntimeException("Invalid Alitycs persistence file: $path");
            }

            foreach ($decoded['batches'] as $record) {
                if (
                    !is_array($record)
                    || !is_string($record['batchId'] ?? null)
                    || !is_string($record['body'] ?? null)
                    || !is_int($record['eventCount'] ?? null)
                    || !is_null($record['pausedUntilMs'] ?? null) && !is_int($record['pausedUntilMs'] ?? null)
                ) {
                    throw new \RuntimeException("Invalid Alitycs persistence record: $path");
                }
                $this->records[$record['batchId']] = $record;
            }
        }
    }

    public function enabled(): bool
    {
        return $this->path !== null;
    }

    public function put(string $batchId, string $body, int $eventCount): void
    {
        if (!$this->enabled() || isset($this->records[$batchId])) {
            return;
        }

        $this->records[$batchId] = [
            'batchId' => $batchId,
            'body' => $body,
            'eventCount' => $eventCount,
            'pausedUntilMs' => null,
        ];
        $this->persist();
    }

    public function acknowledge(string $batchId): void
    {
        if (!$this->enabled() || !isset($this->records[$batchId])) {
            return;
        }

        unset($this->records[$batchId]);
        $this->persist();
    }

    public function pause(string $batchId, ?int $pausedUntilMs): void
    {
        if (!$this->enabled() || !isset($this->records[$batchId])) {
            return;
        }

        $this->records[$batchId]['pausedUntilMs'] = $pausedUntilMs;
        $this->persist();
    }

    /** @return list<array{batchId: string, body: string, eventCount: int, pausedUntilMs: int|null}> */
    public function snapshot(): array
    {
        return array_values($this->records);
    }

    public function pendingEvents(): int
    {
        return array_sum(array_column($this->records, 'eventCount'));
    }

    private function persist(): void
    {
        if ($this->path === null) {
            return;
        }

        if ($this->records === []) {
            if (is_file($this->path) && !unlink($this->path)) {
                throw new \RuntimeException("Unable to remove Alitycs persistence file: {$this->path}");
            }

            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create Alitycs persistence directory: $directory");
        }

        $json = json_encode(
            ['version' => 1, 'batches' => array_values($this->records)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $temporary = tempnam($directory, basename($this->path) . '.tmp.');
        if ($temporary === false) {
            throw new \RuntimeException("Unable to create Alitycs persistence temporary file: $directory");
        }

        try {
            if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $this->path)) {
                throw new \RuntimeException("Unable to persist Alitycs batches: {$this->path}");
            }
            @chmod($this->path, 0600);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
