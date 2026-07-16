<?php

namespace App\Tax\Domain;

use JsonSerializable;

final readonly class TaxSnapshot implements JsonSerializable
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
