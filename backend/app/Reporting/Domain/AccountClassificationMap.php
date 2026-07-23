<?php

namespace App\Reporting\Domain;

/**
 * API Contracts §13.7/§13.8: account classification is never inferred from name or code;
 * an account posted to in the period must have an explicit entry here.
 */
final readonly class AccountClassificationMap
{
    /** @param list<array{account_id: string, code: string, classification: string}> $entries */
    public function __construct(public int $versionNumber, public array $entries) {}

    public function classify(string $accountId): ?string
    {
        foreach ($this->entries as $entry) {
            if ($entry['account_id'] === $accountId) {
                return $entry['classification'];
            }
        }

        return null;
    }
}
