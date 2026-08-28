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

    private ?string $path;

    public function __construct(?string $path, private readonly int $maxPendingEvents = 1000)
    {
        if ($maxPendingEvents < 1) {
            throw new \InvalidArgumentException('Alitycs persistence max pending events must be positive');
        }
        $this->path = $path;
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
                    || $record['eventCount'] < 1
                    || !is_null($record['pausedUntilMs'] ?? null) && !is_int($record['pausedUntilMs'] ?? null)
                ) {
                    throw new \RuntimeException("Invalid Alitycs persistence record: $path");
                }
                if (isset($this->records[$record['batchId']])) {
                    throw new \RuntimeException("Duplicate Alitycs persistence record: $path");
                }
                $this->records[$record['batchId']] = $record;
            }
            if ($this->pendingEvents() > $this->maxPendingEvents) {
                throw new \RuntimeException(
                    "Alitycs persistence file exceeds the configured event limit: $path"
                );
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
        if ($eventCount < 1 || $this->pendingEvents() + $eventCount > $this->maxPendingEvents) {
            throw new \RuntimeException('Alitycs persistence event limit exceeded');
        }

        $previous = $this->records;
        $this->records[$batchId] = [
            'batchId' => $batchId,
            'body' => $body,
            'eventCount' => $eventCount,
            'pausedUntilMs' => null,
        ];
        try {
            $this->persist();
        } catch (\Throwable $throwable) {
            $this->records = $previous;
            throw $throwable;
        }
    }

    public function acknowledge(string $batchId): void
    {
        if (!$this->enabled() || !isset($this->records[$batchId])) {
            return;
        }

        $previous = $this->records;
        unset($this->records[$batchId]);
        try {
            $this->persist();
        } catch (\Throwable $throwable) {
            $this->records = $previous;
            throw $throwable;
        }
    }

    public function pause(string $batchId, ?int $pausedUntilMs): void
    {
        if (!$this->enabled() || !isset($this->records[$batchId])) {
            return;
        }

        $previous = $this->records;
        $this->records[$batchId]['pausedUntilMs'] = $pausedUntilMs;
        try {
            $this->persist();
        } catch (\Throwable $throwable) {
            $this->records = $previous;
            throw $throwable;
        }
    }

    /** Detach a forked child from the parent-owned WAL and its inherited state. */
    public function resetForChild(): bool
    {
        $inherited = $this->path !== null;
        $this->path = null;
        $this->records = [];

        return $inherited;
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

    public function contains(string $batchId): bool
    {
        return isset($this->records[$batchId]);
    }

    private function persist(): void
    {
        if ($this->path === null) {
            return;
        }

        if ($this->records === []) {
            if (is_file($this->path) && !@unlink($this->path)) {
                throw new \RuntimeException("Unable to remove Alitycs persistence file: {$this->path}");
            }
            $this->syncDirectoryBestEffort(dirname($this->path));

            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
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

        $handle = null;
        try {
            $handle = @fopen($temporary, 'wb');
            if ($handle === false || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException("Unable to persist Alitycs batches: {$this->path}");
            }
            $offset = 0;
            $length = strlen($json);
            while ($offset < $length) {
                $written = fwrite($handle, substr($json, $offset));
                if ($written === false || $written === 0) {
                    throw new \RuntimeException("Unable to persist Alitycs batches: {$this->path}");
                }
                $offset += $written;
            }
            if (!fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException("Unable to sync Alitycs batches: {$this->path}");
            }
            fclose($handle);
            $handle = null;
            @chmod($temporary, 0600);
            if (!@rename($temporary, $this->path)) {
                throw new \RuntimeException("Unable to replace Alitycs batches: {$this->path}");
            }
            $this->syncDirectoryBestEffort($directory);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function syncDirectoryBestEffort(string $directory): void
    {
        $handle = @fopen($directory, 'r');
        if ($handle === false) {
            return;
        }
        @fsync($handle);
        fclose($handle);
    }
}
