<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * The `context` block of {@see AnalyticsEvent}. `sdkVersion` and `sdkLanguage` are
 * required by the wire contract; the rest are optional environment details a server
 * process actually knows about.
 */
final class EventContext implements \JsonSerializable
{
    public function __construct(
        public readonly string $sdkVersion,
        public readonly string $sdkLanguage,
        public readonly ?string $locale = null,
        public readonly ?string $timezone = null,
        public readonly ?string $osName = null,
        public readonly ?string $osVersion = null,
        public readonly ?string $phpVersion = null,
    ) {
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'sdkVersion' => $this->sdkVersion,
            'sdkLanguage' => $this->sdkLanguage,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'osName' => $this->osName,
            'osVersion' => $this->osVersion,
            'phpVersion' => $this->phpVersion,
        ], static fn ($value) => $value !== null);
    }
}
