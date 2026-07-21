<?php

namespace App\Support\Documents;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<string,string> */
final class ExactDecimalCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key} must be stored as an exact decimal value.");
        }

        return ExactDecimal::normalize((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$key} must be supplied as an exact decimal string.");
        }

        return ExactDecimal::normalize($value);
    }
}
