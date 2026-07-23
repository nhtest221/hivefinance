<?php

namespace App\Reporting\Domain;

/** API Contracts §13.7/§13.8; versioned, frozen into every P&L/BS ReportRun. */
final readonly class ReportLayout
{
    /** @param list<array<string, mixed>> $sections */
    public function __construct(public int $versionNumber, public array $sections) {}
}
