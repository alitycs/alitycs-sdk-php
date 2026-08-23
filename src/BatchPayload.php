<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * The ingest envelope: one HTTP request body, shaped to the wire contract's
 * `BatchPayload`.
 */
final class BatchPayload implements \JsonSerializable
{
    /**
     * @param list<AnalyticsEvent> $events
     */
    public function __construct(
        public readonly string $batchId,
        public readonly int $sentAt,
        public readonly array $events,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'batchId' => $this->batchId,
            'sentAt' => $this->sentAt,
            'events' => $this->events,
        ];
    }
}
