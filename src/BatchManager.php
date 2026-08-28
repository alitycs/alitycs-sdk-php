<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Event queue with PHP's two dependable flush triggers: queue depth and explicit
 * request. There is deliberately no timer — see {@see Config::$flushInterval} for the
 * opportunistic elapsed-time check that stands in for one.
 *
 * Delivery is honest: the outcome returned by the send closure is used. A refused
 * payload is split in half and each half retried recursively (the server rejects whole
 * batches when one event violates a limit); an individual event the server still
 * refuses is dropped loudly — warn-level log plus {@see lostTotal()} — because
 * re-queueing it would poison every future batch.
 */
final class BatchManager
{
    /** Bound whole-batch rejection isolation so one response cannot create a request storm. */
    private const MAX_SPLIT_SENDS = 64;

    /** @var list<AnalyticsEvent> */
    private array $queue = [];

    private float $lastFlushAttemptAt;

    private int $deliveredTotal = 0;

    private int $lostTotal = 0;

    /**
     * @param (\Closure(BatchPayload): (bool|DeliveryResult)) $send delivery callback
     * @param (\Closure(): float)|null $clock seconds-since-epoch; injectable for tests
     * @param (\Closure(): bool)|null $recover replays durable batches before new work
     * @param (\Closure(): int)|null $durablePending persisted event count
     * @param (\Closure(BatchPayload): bool)|null $persist stores shutdown work without sending
     */
    public function __construct(
        private readonly Config $config,
        private readonly \Closure $send,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $recover = null,
        private readonly ?\Closure $durablePending = null,
        private readonly bool $durable = false,
        private readonly ?\Closure $persist = null,
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

    /** Replays durable work, then sends everything currently queued. Never throws. */
    public function flush(): void
    {
        if (!$this->recoverDurable()) {
            return;
        }

        if ($this->queue === []) {
            return;
        }

        $this->lastFlushAttemptAt = $this->now();

        $events = $this->queue;
        $this->queue = [];

        try {
            $remainingSends = self::MAX_SPLIT_SENDS;
            $this->deliver($events, $remainingSends);
        } catch (\Throwable $throwable) {
            if ($this->durable) {
                $this->queue = array_merge($events, $this->queue);
                Log::warn('Batch dispatch failed — events retained in memory: ' . $throwable->getMessage());
            } else {
                $this->lostTotal += count($events);
                Log::warn('Batch dispatch failed — events dropped: ' . $throwable->getMessage());
            }
        }
    }

    /** Drains normally, or durably persists queued work when older recovery is blocked. */
    public function shutdown(): void
    {
        if ($this->recoverDurable()) {
            // flush() performs its own recovery check so normal and shutdown drains
            // share exactly one dispatch implementation. The second recovery is a
            // cheap no-op after the first one drained the WAL.
            $this->flush();

            return;
        }

        $this->persistQueuedForShutdown();
    }

    private function recoverDurable(): bool
    {
        try {
            return $this->recover === null || ($this->recover)() === true;
        } catch (\Throwable $throwable) {
            Log::warn('Durable batch recovery failed: ' . $throwable->getMessage());

            return false;
        }
    }

    /** Move accepted FIFO work to the WAL without overtaking an older durable batch. */
    private function persistQueuedForShutdown(): void
    {
        if ($this->queue === []) {
            return;
        }

        if (!$this->durable || $this->persist === null) {
            $lost = count($this->queue);
            $this->queue = [];
            $this->lostTotal += $lost;
            Log::warn("Shutdown could not deliver $lost queued event(s) — persistence unavailable");

            return;
        }

        while ($this->queue !== []) {
            $events = [$this->queue[0]];
            $payload = new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), $events);
            try {
                $persisted = ($this->persist)($payload) === true;
            } catch (\Throwable $throwable) {
                $persisted = false;
                Log::warn('Shutdown persistence failed: ' . $throwable->getMessage());
            }

            if (!$persisted) {
                $lost = count($this->queue);
                $this->queue = [];
                $this->lostTotal += $lost;
                Log::warn("Shutdown persistence failed — $lost queued event(s) lost");

                return;
            }

            array_shift($this->queue);
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
        $durablePending = $this->durablePending === null ? 0 : ($this->durablePending)();

        return count($this->queue) + $durablePending;
    }

    /**
     * Sends one payload and resolves its fate. On refusal, splits the payload in half
     * and retries each side so valid events still land; depth is bounded by ~log2 of
     * the original batch size.
     *
     * @param list<AnalyticsEvent> $events
     */
    private function deliver(array $events, int &$remainingSends): void
    {
        if ($remainingSends <= 0) {
            $this->lostTotal += count($events);
            Log::warn(
                'Batch rejection split limit reached — dropping ' . count($events) . ' unresolved event(s)'
            );

            return;
        }
        $remainingSends--;
        $payload = new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), $events);

        $rawOutcome = ($this->send)($payload);
        $outcome = $rawOutcome instanceof DeliveryResult
            ? $rawOutcome
            : ($rawOutcome === true ? DeliveryResult::accepted(200) : DeliveryResult::rejected(400));

        if ($outcome->isAccepted()) {
            $this->deliveredTotal += count($events);

            return;
        }

        if ($outcome->isRejected() && $outcome->status === 400 && count($events) > 1) {
            $mid = intdiv(count($events), 2);
            $this->deliver(array_slice($events, 0, $mid), $remainingSends);
            $this->deliver(array_slice($events, $mid), $remainingSends);

            return;
        }

        if ($outcome->isRejected()) {
            $this->lostTotal += count($events);
            Log::warn(
                'Server refused ' . count($events) . ' event(s)'
                . ' — dropped, not retried (re-queueing would poison future batches)'
            );

            return;
        }

        if ($this->durable) {
            Log::warn('Transient delivery failure — exact batch retained for restart');

            return;
        }

        $this->lostTotal += count($events);
        Log::warn('Transient delivery failure without persistence — ' . count($events) . ' event(s) lost');
    }

    private function now(): float
    {
        $clock = $this->clock ?? static fn (): float => microtime(true);

        return $clock();
    }
}
