<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\Context;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    public function testCollectsServerSideEnvironmentDetails(): void
    {
        $context = Context::collect('9.9.9');

        $this->assertSame('9.9.9', $context->sdkVersion);
        $this->assertSame('php', $context->sdkLanguage);
        $this->assertNotSame('', $context->timezone);
        $this->assertNotSame('', $context->osName);
        $this->assertNotSame('', $context->osVersion);
        $this->assertSame(PHP_VERSION, $context->phpVersion);
        // Present when intl is loaded, omitted otherwise — never a non-tag like "C".
        $hasIntl = function_exists('locale_get_default');
        if ($hasIntl) {
            $this->assertNotNull($context->locale);
        } else {
            $this->assertNull($context->locale);
        }
    }

    public function testCollectedContextSerializesWithoutNulls(): void
    {
        $encoded = json_encode(Context::collect('1.0.0')->jsonSerialize());
        self::assertIsString($encoded);

        $this->assertStringNotContainsString(':null', $encoded);
        $this->assertStringContainsString('"sdkLanguage":"php"', $encoded);
    }
}
