<?php

declare(strict_types=1);

namespace Alitycs;

/** Internal transport outcome used to distinguish retryable failures from rejections. */
final class DeliveryResult
{
    private const ACCEPTED = 'accepted';
    private const REJECTED = 'rejected';
    private const TRANSIENT = 'transient';

    private function __construct(
        private readonly string $kind,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterUntilMs = null,
    ) {
    }

    public static function accepted(int $status): self
    {
        return new self(self::ACCEPTED, $status);
    }

    public static function rejected(int $status): self
    {
        return new self(self::REJECTED, $status);
    }

    public static function transient(?int $status = null, ?int $retryAfterUntilMs = null): self
    {
        return new self(self::TRANSIENT, $status, $retryAfterUntilMs);
    }

    public function isAccepted(): bool
    {
        return $this->kind === self::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->kind === self::REJECTED;
    }
}
