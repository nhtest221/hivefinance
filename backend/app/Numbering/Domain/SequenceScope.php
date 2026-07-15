<?php

namespace App\Numbering\Domain;

use InvalidArgumentException;

final readonly class SequenceScope
{
    public function __construct(public string $entityId, public string $fiscalYear)
    {
        if ($entityId === '' || $fiscalYear === '') {
            throw new InvalidArgumentException('Sequence scope requires entity and fiscal year.');
        }
    }
}
