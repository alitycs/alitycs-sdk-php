<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Thrown when an event violates a canonical ingestion limit ({@see Limits}) and is
 * rejected locally: the event is never queued and never sent.
 */
final class EventRejectedError extends \InvalidArgumentException
{
}
