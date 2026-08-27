<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Canonical ingestion limits — encode the server's EventValidator exactly. The server
 * rejects an entire batch when a single event violates any of these, so the SDK
 * enforces them client-side and refuses such events before they are queued.
 */
final class Limits
{
    public const MAX_PROPERTIES_COUNT = 50;
    public const MAX_PROPERTY_KEY_LENGTH = 100;
    public const MAX_PROPERTY_VALUE_LENGTH = 1000;
    public const MAX_EVENT_SIZE_BYTES = 64 * 1024;

    /** Constant overhead the server adds to every event when estimating its size. */
    public const EVENT_SIZE_OVERHEAD = 200;

    /** Timestamps below this are seconds-scale, not epoch milliseconds. */
    public const MIN_EPOCH_MILLIS = 1_000_000_000_000;

    public const MAX_EVENT_AGE_DAYS = 7;
}
