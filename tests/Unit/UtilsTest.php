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

        $this->assertGreaterThanOrEqual($before, $now);
        $this->assertLessThanOrEqual($after, $now);
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
