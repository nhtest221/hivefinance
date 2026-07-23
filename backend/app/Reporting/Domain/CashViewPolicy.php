<?php

namespace App\Reporting\Domain;

/** API Contracts §13.11: the frozen Cash View algorithm plus approved additional rules. */
final readonly class CashViewPolicy
{
    /** @param array<string, mixed> $policy */
    public function __construct(public int $versionNumber, public array $policy) {}
}
