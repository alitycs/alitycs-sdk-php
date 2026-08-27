<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\EventType;
use Alitycs\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UtilsTest extends TestCase
{
    public function testGenerateIdProducesUuidV4(): void
    {
        $id = Utils::generateId();

        $this->assertSame(1, preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        ));
    }

    public function testGenerateIdIsUnique(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = Utils::generateId();
        }

        $this->assertCount(100, array_unique($ids));
    }

    public function testNowMsIsMilliseconds(): void
    {
        $before = (int) round(microtime(true) * 1000);
        $now = Utils::nowMs();
        $after = (int) round(microtime(true) * 1000);

        // nowMs() truncates the millisecond where the float form rounds, so it may land
        // one millisecond "before" a rounded reading taken fractionally earlier.
        $this->assertGreaterThanOrEqual($before - 1, $now);
        $this->assertLessThanOrEqual($after, $now);
    }

    public function testNowMsIsEpochMillisWithinFloatAgreement(): void
    {
        $now = Utils::nowMs();

        // Shape: epoch milliseconds — far above seconds-scale timestamps and close to
        // the float computation of the same instant.
        $this->assertGreaterThan(\Alitycs\Limits::MIN_EPOCH_MILLIS, $now);
        $this->assertEqualsWithDelta((int) round(microtime(true) * 1000), $now, 2);
        $this->assertSame(0, $now % 1, 'must be an exact int');
    }

    public function testNowMsIsMonotonicAcrossSuccessiveReadings(): void
    {
        $previous = Utils::nowMs();
        for ($i = 0; $i < 50; $i++) {
            usleep(20);
            $current = Utils::nowMs();
            $this->assertGreaterThanOrEqual($previous - 1, $current);
            $previous = $current;
        }
    }

    public function testSerializesStringsVerbatim(): void
    {
        $this->assertSame(['plan' => 'pro'], Utils::serializeProperties(['plan' => 'pro']));
    }

    public function testSkipsNullProperties(): void
    {
        $this->assertSame(['kept' => 'yes'], Utils::serializeProperties([
            'dropped' => null,
            'kept' => 'yes',
        ]));
    }

    public function testStringifiesBooleansLikeTheOtherServerSdks(): void
    {
        $this->assertSame(
            ['yes' => 'true', 'no' => 'false'],
            Utils::serializeProperties(['yes' => true, 'no' => false])
        );
    }

    public function testStringifiesIntegers(): void
    {
        $this->assertSame(['n' => '42'], Utils::serializeProperties(['n' => 42]));
    }

    public function testStringifiesFloats(): void
    {
        $this->assertSame(['cart' => '96.4'], Utils::serializeProperties(['cart' => 96.40]));
    }

    public static function objectValueProvider(): \Generator
    {
        yield 'list' => [['a', 'b'], '["a","b"]'];
        yield 'map' => [['x' => 1], '{"x":1}'];
        yield 'nested' => [['deep' => ['n' => 2]], '{"deep":{"n":2}}'];
        yield 'stdClass' => [new \stdClass(), '{}'];
        yield 'jsonSerializable' => [new JsonSerializableFixture(), '{"fixture":true}'];
    }

    #[DataProvider('objectValueProvider')]
    public function testJsonEncodesStructuredValues(mixed $value, string $expected): void
    {
        $this->assertSame(['data' => $expected], Utils::serializeProperties(['data' => $value]));
    }

    public function testBackedEnumUsesItsValue(): void
    {
        $this->assertSame(['type' => 'track'], Utils::serializeProperties(['type' => EventType::Track]));
    }

    public function testPlainEnumUsesItsName(): void
    {
        $this->assertSame(['flavour' => 'Vanilla'], Utils::serializeProperties(['flavour' => PlainEnum::Vanilla]));
    }

    public function testDateTimeIsIso8601(): void
    {
        $moment = new \DateTimeImmutable('2026-01-02T03:04:05+00:00');

        $this->assertSame(
            ['at' => '2026-01-02T03:04:05+00:00'],
            Utils::serializeProperties(['at' => $moment])
        );
    }

    public function testStringableObjectsAreCast(): void
    {
        $this->assertSame(['s' => 'stringable'], Utils::serializeProperties(['s' => new StringableFixture()]));
    }

    public function testUnencodableValuesFallBackToTypePlaceholder(): void
    {
        // Resources cannot be JSON-encoded at all.
        $handle = fopen('php://memory', 'rb');

        $this->assertSame(
            ['data' => '[' . get_debug_type($handle) . ']'],
            Utils::serializeProperties(['data' => $handle])
        );

        fclose($handle);
    }

    public function testIntegerKeysAreStringified(): void
    {
        $this->assertSame(['7' => 'lucky'], Utils::serializeProperties([7 => 'lucky']));
    }

    // ------------------------------------------------------- canonical ingestion limits

    public function testIllFormedUtf8ValuesAreSanitizedNotFatal(): void
    {
        $invalid = "\xB1\x31"; // an ill-formed UTF-8 byte sequence followed by ASCII

        $serialized = Utils::serializeProperties(['data' => $invalid]);

        $this->assertSame(1, preg_match('//u', $serialized['data']), 'serialized value must be valid UTF-8');
        $this->assertStringContainsString('1', $serialized['data']);
        // The JSON encoder must now succeed without any substitution flag.
        $this->assertIsString(json_encode($serialized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function testIllFormedUtf8KeysAreSanitized(): void
    {
        $serialized = Utils::serializeProperties(["\xB1\x31" => 'v']);

        $this->assertCount(1, $serialized);
        foreach (array_keys($serialized) as $key) {
            $this->assertSame(1, preg_match('//u', $key));
        }
    }

    public function testValidUtf8MultiByteValuesPassThroughUntouched(): void
    {
        $this->assertSame(['city' => 'Köln'], Utils::serializeProperties(['city' => 'Köln']));
    }

    public function testOversizedPropertyKeyIsRejectedLocally(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('property key');

        Utils::serializeProperties([str_repeat('k', 101) => 'v']);
    }

    public function testOversizedPropertyValueIsRejectedLocally(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('value for property key');

        Utils::serializeProperties(['key' => str_repeat('v', 1001)]);
    }

    public function testMoreThanFiftyPropertiesIsRejectedLocally(): void
    {
        $properties = [];
        for ($i = 0; $i < 51; $i++) {
            $properties["key$i"] = 'v';
        }

        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('exceeds the maximum');

        Utils::serializeProperties($properties);
    }

    public function testValuesExactlyAtTheLimitsAreAccepted(): void
    {
        $serialized = Utils::serializeProperties([
            str_repeat('k', 100) => str_repeat('v', 1000),
        ]);

        $this->assertCount(1, $serialized);
    }

    private function event(array $overrides = []): \Alitycs\AnalyticsEvent
    {
        $fields = [
            'eventId' => 'evt_1',
            'event' => 'test_event',
            'eventType' => EventType::Track,
            'anonymousId' => 'anon_1',
            'sessionId' => 'sess_1',
            'timestamp' => Utils::nowMs(),
            'properties' => ['key' => 'value'],
            'context' => new \Alitycs\EventContext('1.0.0', 'php'),
        ];

        return new \Alitycs\AnalyticsEvent(...array_merge($fields, $overrides));
    }

    public function testValidateEventAcceptsAWellFormedEvent(): void
    {
        Utils::validateEvent($this->event());
        $this->addToAssertionCount(1);
    }

    public function testValidateEventRejectsSecondsScaleTimestamps(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('epoch milliseconds');

        Utils::validateEvent($this->event(['timestamp' => (int) (Utils::nowMs() / 1000)]));
    }

    public function testValidateEventRequiresAUserOrAnonymousIdentity(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('at least one of userId or anonymousId is required');

        Utils::validateEvent($this->event(['anonymousId' => '', 'userId' => null]));
    }

    public function testValidateEventRejectsFutureTimestamps(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('future');

        Utils::validateEvent($this->event(['timestamp' => Utils::nowMs() + 60_000]));
    }

    public function testValidateEventRejectsEventsOlderThanSevenDays(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('too old');

        Utils::validateEvent($this->event(['timestamp' => Utils::nowMs() - 8 * 24 * 60 * 60 * 1000]));
    }

    public function testValidateEventRejectsEventsOverTheEstimatedSizeLimit(): void
    {
        $this->expectException(\Alitycs\EventRejectedError::class);
        $this->expectExceptionMessage('maximum allowed size');

        Utils::validateEvent($this->event(['properties' => ['big' => str_repeat('v', 64 * 1024)]]));
    }

    public function testValidateEventAccumulatesViolations(): void
    {
        try {
            Utils::validateEvent($this->event([
                'event' => '   ',
                'timestamp' => Utils::nowMs() + 5_000,
                'properties' => [str_repeat('k', 101) => 'v'],
            ]));
            $this->fail('Expected EventRejectedError');
        } catch (\Alitycs\EventRejectedError $error) {
            $message = $error->getMessage();
            $this->assertStringContainsString('action is required', $message);
            $this->assertStringContainsString('; ', $message);
            $this->assertStringContainsString('property key', $message);
        }
    }
}

enum PlainEnum
{
    case Vanilla;
}

final class JsonSerializableFixture implements \JsonSerializable
{
    public function jsonSerialize(): array
    {
        return ['fixture' => true];
    }
}

final class StringableFixture implements \Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}
