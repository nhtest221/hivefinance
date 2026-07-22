<?php

namespace App\Period\Domain;

use DateTimeImmutable;

/**
 * Internal CloseGateProvider v1 output (Repository Contracts §"AccountingPeriodRepository
 * and CloseGateProvider"; API Contracts §12.7). Never an HTTP payload, never a foreign
 * aggregate or ORM model — immutable evidence metadata only.
 */
final readonly class CloseGateResult
{
    public function __construct(
        public string $gateType,
        public string $status,
        public ?string $sourceContext,
        public ?string $sourceReference,
        public ?DateTimeImmutable $producedAt,
        public ?string $reviewedBy,
        public ?DateTimeImmutable $reviewedAt,
        public ?int $evidenceVersion,
        public ?string $evidenceHash,
    ) {}

    public function satisfied(): bool
    {
        return $this->status === 'satisfied';
    }
}
