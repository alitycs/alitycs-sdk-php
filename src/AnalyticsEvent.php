<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * A single analytics event, shaped to `specs/event-schema.json` v0.4.0
 * (`AnalyticsEvent`).
 */
final class AnalyticsEvent implements \JsonSerializable
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $event,
        public readonly EventType $eventType,
        public readonly string $anonymousId,
        public readonly string $sessionId,
        public readonly int $timestamp,
        public readonly array $properties,
        public readonly EventContext $context,
        public readonly ?string $userId = null,
        public readonly ?RevenuePayload $revenue = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'eventId' => $this->eventId,
            'event' => $this->event,
            'eventType' => $this->eventType->value,
            'anonymousId' => $this->anonymousId,
            'sessionId' => $this->sessionId,
            'timestamp' => $this->timestamp,
            // An empty PHP array would encode as `[]`, not the `{}` the schema's object
            // type requires.
            'properties' => $this->properties === [] ? new \stdClass() : $this->properties,
            'context' => $this->context,
            'userId' => $this->userId,
            'revenue' => $this->revenue,
        ], static fn ($value) => $value !== null);
    }
}
