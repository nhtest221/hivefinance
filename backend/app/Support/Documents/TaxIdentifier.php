<?php

namespace App\Support\Documents;

use Normalizer;

final class TaxIdentifier
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = Normalizer::normalize(trim($value), Normalizer::FORM_KC);

        return mb_strtoupper($normalized === false ? trim($value) : $normalized, 'UTF-8');
    }
}
