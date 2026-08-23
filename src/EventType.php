<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Category of an analytics event, transmitted verbatim as the `eventType` field.
 */
enum EventType: string
{
    case Track = 'track';
    case Identify = 'identify';
    case Page = 'page';
    case Error = 'error';
}
