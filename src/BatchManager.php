<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Event queue with PHP's two dependable flush triggers: queue depth and explicit
 * request. There is deliberately no timer — see {@see Config::$flushInterval} for the
 * opportunistic elapsed-time check that stands in for one.
 *
 * A failed send drops the batch (after the transport's own retries) and is logged, never
 * thrown, so a dead endpoint cannot fatal the host application.
 */
final class BatchManager
{
    /** @var list<AnalyticsEvent> */
    private array $queue = [];

    private float $lastFlushAttemptAt;

    /**
     * @param (\Closure(BatchPayload): bool) $send delivery callback
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
            Log::write($this->config->debug, 'Queue full — dropping event');

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

    /** Sends everything currently queued as one batch; a no-op on an empty queue. */
    public function flush(): void
    {
        if ($this->queue === []) {
            return;
        }

        $this->lastFlushAttemptAt = $this->now();

        $events = $this->queue;
        $this->queue = [];

        $payload = new BatchPayload('batch_' . Utils::generateId(), Utils::nowMs(), $events);

        try {
            ($this->send)($payload);
        } catch (\Throwable $throwable) {
            Log::write(
                $this->config->debug,
                'Batch send failed — events dropped: ' . $throwable->getMessage()
            );
        }
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    private function now(): float
    {
        $clock = $this->clock ?? static fn (): float => microtime(true);

        return $clock();
    }
}
