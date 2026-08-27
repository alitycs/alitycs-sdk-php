<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Event queue with PHP's two dependable flush triggers: queue depth and explicit
 * request. There is deliberately no timer — see {@see Config::$flushInterval} for the
 * opportunistic elapsed-time check that stands in for one.
 *
 * Delivery is honest: the boolean returned by the send closure is used. A refused
 * payload is split in half and each half retried recursively (the server rejects whole
 * batches when one event violates a limit); an individual event the server still
 * refuses is dropped loudly — warn-level log plus {@see lostTotal()} — because
 * re-queueing it would poison every future batch.
 */
final class BatchManager
{
    /** Recursion guard for batch splitting: far beyond any realistic queue depth. */
    private const MAX_SPLIT_DEPTH = 32;

    /** @var list<AnalyticsEvent> */
    private array $queue = [];

    private float $lastFlushAttemptAt;

    private int $deliveredTotal = 0;

    private int $lostTotal = 0;

    /**
     * @param (\Closure(BatchPayload): bool) $send delivery callback: true = accepted
     * @param (\Closure(): float)|null $clock seconds-since-epoch; injectable for tests
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $send,
        private readonly ?\Closure $clock = null
    ) {
        $this->lastFlushAttemptAt = $this->now();
    }

    public function add(AnalyticsEvent $event): void
    {
        if (count($this->queue) >= $this->config->maxQueueSize) {
            $this->lostTotal++;
            Log::warn('Queue full — dropping event');

            return;
        }

        $this->queue[] = $event;

        if (count($this->queue) >= $this->config->flushSize) {
            $this->flush();

            return;
        }

        // No background thread exists to fire an interval flush, so while events keep
        // arriving we check elapsed time here instead.
        if (
            $this->config->flushInterval > 0
            && $this->queue !== []
            && $this->now() - $this->lastFlushAttemptAt >= $this->config->flushInterval
        ) {
            $this->flush();
        }
    }

    /** Sends everything currently queued; a no-op on an empty queue. Never throws. */
    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }

        $this->lastFlushAttemptAt = $this->now();

        $events = $this->queue;
        $this->queue = [];

        try {
            $this->deliver($events, 0);
        } catch (\Throwable $throwable) {
            $this->lostTotal += count($events);
            Log::warn('Batch dispatch failed — events dropped: ' . $throwable->getMessage());
        }
    }

    /**
     * Post-fork repair, run in the child process only: drops the queue inherited across
     * the fork. The parent's copy of these events is still queued there and will be
     * delivered by the parent — sending them from both processes would double-count
     * every one. Counters stay untouched: nothing was lost, ownership just moved back.
     */
    public function resetForChild(): void
    {
        if ($this->queue !== []) {
            Log::write(
                true,
                'Fork detected — ' . count($this->queue) . ' inherited event(s) left to the parent process'
            );
        }
        $this->queue = [];
        $this->lastFlushAttemptAt = $this->now();
    }

    /** Events confirmed delivered since this manager was created. */
    public function deliveredTotal(): int
    {
        return $this->deliveredTotal;
    }
    /** Events permanently lost: server-refused or overflowed from the queue. */
    public function lostTotal(): int
    {
        return $this->lostTotal;
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * Sends one payload and resolves its fate. On refusal, splits the payload in half
     * and retries each side so valid events still land; depth is bounded by ~log2 of
     * the original batch size.
     *
     * @param list<AnalyticsEvent> $events
     */
    private function deliver(array $events, int $depth): void
    {
        $payload = new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), $events);

        try {
            $accepted = ($this->send)($payload);
        } catch (\Throwable $throwable) {
            Log::warn(
                'Transport failure (' . $throwable->getMessage() . ') — '
                . count($events) . ' event(s) not delivered'
            );
            $accepted = false;
        }

        if ($accepted === true) {
            $this->deliveredTotal += count($events);

            return;
        }

        if (count($events) > 1 && $depth < self::MAX_SPLIT_DEPTH) {
            $mid = intdiv(count($events), 2);
            $this->deliver(array_slice($events, 0, $mid), $depth + 1);
            $this->deliver(array_slice($events, $mid), $depth + 1);

            return;
        }

        $this->lostTotal += count($events);
        Log::warn(
            'Server refused ' . count($events) . ' event(s)'
            . ' — dropped, not retried (re-queueing would poison future batches)'
        );
    }

    private function now(): float
    {
        $clock = $this->clock ?? static fn (): float => microtime(true);

        return $clock();
    }
}
