<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Collects the server-side environment details that go into every event's context.
 */
final class Context
{
    public static function collect(string $sdkVersion): EventContext
    {
        return new EventContext(
            sdkVersion: $sdkVersion,
            sdkLanguage: 'php',
            locale: self::locale(),
            timezone: date_default_timezone_get(),
            osName: php_uname('s'),
            osVersion: php_uname('r'),
            phpVersion: PHP_VERSION,
        );
    }

    /**
     * BCP 47 language tag when the intl extension is available; omitted otherwise so the
     * field stays out of the payload rather than sending a non-tag like "C".
     */
    private static function locale(): ?string
    {
        if (!function_exists('locale_get_default')) {
            return null;
        }

        $locale = locale_get_default();

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
