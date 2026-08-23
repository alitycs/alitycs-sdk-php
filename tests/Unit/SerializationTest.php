<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\AnalyticsEvent;
use Alitycs\BatchPayload;
use Alitycs\EventContext;
use Alitycs\EventType;
use Alitycs\RevenuePayload;
use PHPUnit\Framework\TestCase;

final class SerializationTest extends TestCase
{
    public function testContextOmitsOptionalNullsAndKeepsRequiredFields(): void
    {
        $minimal = new EventContext('1.0.0', 'php');

        $this->assertSame(['sdkVersion' => '1.0.0', 'sdkLanguage' => 'php'], $minimal->jsonSerialize());

        $full = new EventContext('1.0.0', 'php', 'en-US', 'UTC', 'Darwin', '25.6.0', '8.5.9');

        $this->assertSame([
            'sdkVersion' => '1.0.0',
            'sdkLanguage' => 'php',
            'locale' => 'en-US',
            'timezone' => 'UTC',
            'osName' => 'Darwin',
            'osVersion' => '25.6.0',
            'phpVersion' => '8.5.9',
        ], $full->jsonSerialize());
    }

    public function testEventSerializesToTheSchemaShape(): void
    {
        $event = new AnalyticsEvent(
            eventId: 'evt_1',
            event: 'signup_completed',
            eventType: EventType::Track,
            anonymousId: 'anon_1',
            sessionId: 'sess_1',
            timestamp: 1_750_000_000_000,
            properties: ['plan' => 'free'],
            context: new EventContext('1.0.0', 'php'),
            userId: 'usr_1',
            revenue: RevenuePayload::transaction('fact_1', '19.99', 'USD'),
        );

        $encoded = json_encode($event);
        self::assertIsString($encoded);

        $this->assertSame([
            'eventId' => 'evt_1',
            'event' => 'signup_completed',
            'eventType' => 'track',
            'anonymousId' => 'anon_1',
            'sessionId' => 'sess_1',
            'timestamp' => 1_750_000_000_000,
            'properties' => ['plan' => 'free'],
            'context' => ['sdkVersion' => '1.0.0', 'sdkLanguage' => 'php'],
            'userId' => 'usr_1',
            'revenue' => [
                'version' => 1,
                'kind' => 'transaction',
                'factId' => 'fact_1',
                'currency' => 'USD',
                'amount' => '19.99',
            ],
        ], json_decode($encoded, true));
    }

    public function testEventWithoutUserOrRevenueOmitsThoseKeys(): void
    {
        $event = new AnalyticsEvent(
            eventId: 'evt_1',
            event: 'page_view',
            eventType: EventType::Page,
            anonymousId: 'anon_1',
            sessionId: 'sess_1',
            timestamp: 1_750_000_000_000,
            properties: [],
            context: new EventContext('1.0.0', 'php'),
        );

        $serialized = $event->jsonSerialize();

        $this->assertArrayNotHasKey('userId', $serialized);
        $this->assertArrayNotHasKey('revenue', $serialized);
    }

    public function testEmptyPropertiesEncodeAsAnJsonObjectNotAnArray(): void
    {
        $event = new AnalyticsEvent(
            eventId: 'evt_1',
            event: 'e',
            eventType: EventType::Track,
            anonymousId: 'anon_1',
            sessionId: 'sess_1',
            timestamp: 1,
            properties: [],
            context: new EventContext('1.0.0', 'php'),
        );

        $encoded = json_encode($event->jsonSerialize());
        self::assertIsString($encoded);

        // A PHP `[]` would render as `"properties":[]` and fail the schema's object type.
        $this->assertStringContainsString('"properties":{}', $encoded);
    }

    public function testBatchPayloadEncodesTheEnvelope(): void
    {
        $event = new AnalyticsEvent(
            eventId: 'evt_1',
            event: 'e',
            eventType: EventType::Track,
            anonymousId: 'anon_1',
            sessionId: 'sess_1',
            timestamp: 1,
            properties: ['k' => 'v'],
            context: new EventContext('1.0.0', 'php'),
        );
        $batch = new BatchPayload('batch_1', 1_750_000_000_000, [$event]);

        $decoded = json_decode(json_encode($batch), true);

        $this->assertSame('batch_1', $decoded['batchId']);
        $this->assertSame(1_750_000_000_000, $decoded['sentAt']);
        $this->assertCount(1, $decoded['events']);
        $this->assertSame('evt_1', $decoded['events'][0]['eventId']);
    }
}
