<?php

declare(strict_types=1);

namespace Alitycs;

/**
 * Trusted revenue ingestion payload. Server-only: requires a key with `revenue:write`.
 *
 * Build one through the named constructors matching the three variants of the wire
 * contract's `RevenuePayload` — the constructor is private so an invalid combination of
 * fields for a variant cannot be expressed.
 */
final class RevenuePayload implements \JsonSerializable
{
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';
    private const DECIMAL_PATTERN = '/^-?(?:0|[1-9]\d*)(?:\.\d{1,9})?$/';
    private const MAX_PRECISION_DIGITS = 38;
    private const MAX_FACT_ID_LENGTH = 200;

    /**
     * @param int<0, max>|null $expectedActiveSubscriptions
     */
    private function __construct(
        public readonly int $version,
        public readonly string $kind,
        public readonly string $factId,
        public readonly string $currency,
        public readonly ?string $amount = null,
        public readonly ?string $customerId = null,
        public readonly ?string $subscriptionId = null,
        public readonly ?string $mrrAmount = null,
        public readonly ?int $expectedActiveSubscriptions = null,
    ) {
    }

    public static function transaction(
        string $factId,
        string $amount,
        string $currency,
        ?string $customerId = null
    ): self {
        return (new self(version: 1, kind: 'transaction', factId: $factId, currency: $currency, amount: $amount, customerId: $customerId))
            ->validated();
    }

    public static function mrrSnapshot(
        string $factId,
        string $subscriptionId,
        string $customerId,
        string $mrrAmount,
        string $currency
    ): self {
        return (new self(
            version: 1,
            kind: 'mrr_snapshot',
            factId: $factId,
            currency: $currency,
            customerId: $customerId,
            subscriptionId: $subscriptionId,
            mrrAmount: $mrrAmount,
        ))->validated();
    }

    /**
     * @param int<0, max> $expectedActiveSubscriptions
     */
    public static function mrrBaselineComplete(
        string $factId,
        string $currency,
        int $expectedActiveSubscriptions
    ): self {
        return (new self(
            version: 1,
            kind: 'mrr_baseline_complete',
            factId: $factId,
            currency: $currency,
            expectedActiveSubscriptions: $expectedActiveSubscriptions,
        ))->validated();
    }

    private function validated(): static
    {
        if ($this->factId === '' || strlen($this->factId) > self::MAX_FACT_ID_LENGTH) {
            throw new \InvalidArgumentException('Revenue factId must be between 1 and 200 characters');
        }
        if (preg_match(self::CURRENCY_PATTERN, $this->currency) !== 1) {
            throw new \InvalidArgumentException('Revenue currency must be a three-letter uppercase code');
        }

        $decimal = $this->amount ?? $this->mrrAmount;
        if ($decimal !== null) {
            self::assertValidDecimal($decimal);
        }
        if (
            $this->kind === 'mrr_snapshot'
            && $this->mrrAmount !== null
            && self::isNegative($this->mrrAmount)
        ) {
            throw new \InvalidArgumentException('MRR snapshot amount must be non-negative');
        }
        if ($this->expectedActiveSubscriptions !== null && $this->expectedActiveSubscriptions < 0) {
            throw new \InvalidArgumentException('Expected active subscriptions must be non-negative');
        }

        return $this;
    }

    private static function assertValidDecimal(string $decimal): void
    {
        if (preg_match(self::DECIMAL_PATTERN, $decimal) !== 1) {
            throw new \InvalidArgumentException(
                'Revenue amounts must be non-exponent decimal strings with at most 9 fraction digits'
            );
        }

        $digits = str_replace(['-', '.'], '', $decimal);
        if (strlen($digits) > self::MAX_PRECISION_DIGITS) {
            throw new \InvalidArgumentException('Revenue amounts must not exceed 38 digits of precision');
        }
    }

    private static function isNegative(string $decimal): bool
    {
        $magnitude = ltrim(str_replace('.', '', ltrim($decimal, '-')), '0');

        return str_starts_with($decimal, '-') && $magnitude !== '';
    }

    public function jsonSerialize(): array
    {
        return array_filter([
            'version' => $this->version,
            'kind' => $this->kind,
            'factId' => $this->factId,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'customerId' => $this->customerId,
            'subscriptionId' => $this->subscriptionId,
            'mrrAmount' => $this->mrrAmount,
            'expectedActiveSubscriptions' => $this->expectedActiveSubscriptions,
        ], static fn ($value) => $value !== null);
    }
}
