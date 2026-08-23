<?php

declare(strict_types=1);

namespace Alitycs\Tests\Unit;

use Alitycs\RevenuePayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevenuePayloadTest extends TestCase
{
    public function testTransactionSerializesToTheWireShape(): void
    {
        $payload = RevenuePayload::transaction('fact_1', '19.99', 'USD');

        $this->assertSame([
            'version' => 1,
            'kind' => 'transaction',
            'factId' => 'fact_1',
            'currency' => 'USD',
            'amount' => '19.99',
        ], $payload->jsonSerialize());
    }

    public function testTransactionWithCustomer(): void
    {
        $serialized = RevenuePayload::transaction('fact_1', '19.99', 'USD', 'usr_1')->jsonSerialize();

        $this->assertSame('usr_1', $serialized['customerId']);
        $this->assertArrayNotHasKey('subscriptionId', $serialized);
        $this->assertArrayNotHasKey('mrrAmount', $serialized);
        $this->assertArrayNotHasKey('expectedActiveSubscriptions', $serialized);
    }

    public function testMrrSnapshotCarriesSubscriptionFields(): void
    {
        $payload = RevenuePayload::mrrSnapshot('fact_2', 'sub_9', 'usr_1', '149.00', 'EUR');

        $this->assertSame([
            'version' => 1,
            'kind' => 'mrr_snapshot',
            'factId' => 'fact_2',
            'currency' => 'EUR',
            'customerId' => 'usr_1',
            'subscriptionId' => 'sub_9',
            'mrrAmount' => '149.00',
        ], $payload->jsonSerialize());
    }

    public function testMrrBaselineCompleteCarriesExpectedSubscriptions(): void
    {
        $payload = RevenuePayload::mrrBaselineComplete('fact_3', 'USD', 120);

        $this->assertSame([
            'version' => 1,
            'kind' => 'mrr_baseline_complete',
            'factId' => 'fact_3',
            'currency' => 'USD',
            'expectedActiveSubscriptions' => 120,
        ], $payload->jsonSerialize());
    }

    public static function invalidPayloadProvider(): \Generator
    {
        yield 'blank factId' => [static fn () => RevenuePayload::transaction('', '1.00', 'USD')];
        yield 'factId over 200 chars' => [static fn () => RevenuePayload::transaction(str_repeat('x', 201), '1.00', 'USD')];
        yield 'lowercase currency' => [static fn () => RevenuePayload::transaction('f', '1.00', 'usd')];
        yield 'two-letter currency' => [static fn () => RevenuePayload::transaction('f', '1.00', 'US')];
        yield 'four-letter currency' => [static fn () => RevenuePayload::transaction('f', '1.00', 'USDX')];
        yield 'exponent amount' => [static fn () => RevenuePayload::transaction('f', '1e5', 'USD')];
        yield 'ten fraction digits' => [static fn () => RevenuePayload::transaction('f', '0.1234567891', 'USD')];
        yield 'leading zero decimal' => [static fn () => RevenuePayload::transaction('f', '01.00', 'USD')];
        yield 'trailing dot' => [static fn () => RevenuePayload::transaction('f', '1.', 'USD')];
        yield '39 digits of precision' => [static fn () => RevenuePayload::transaction('f', '123456789012345678901234567890123456789', 'USD')];
        yield 'negative mrr snapshot' => [static fn () => RevenuePayload::mrrSnapshot('f', 'sub', 'usr', '-5.00', 'USD')];
        yield 'negative expected subscriptions' => [static fn () => RevenuePayload::mrrBaselineComplete('f', 'USD', -1)];
    }

    #[DataProvider('invalidPayloadProvider')]
    public function testInvalidPayloadsAreRejected(\Closure $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory();
    }

    public function testBoundaryValuesAreAccepted(): void
    {
        // Exactly 38 digits of precision, negative zero, and a bare zero are all legal.
        RevenuePayload::transaction('f', '12345678901234567890123456789012345678', 'USD');
        RevenuePayload::transaction('f', '-0.00', 'USD');
        RevenuePayload::transaction('f', '0', 'USD');

        RevenuePayload::mrrSnapshot('f', 'sub', 'usr', '0.00', 'USD');

        $this->expectNotToPerformAssertions();
    }
}
