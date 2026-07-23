<?php

namespace App\Support\Documents;

use InvalidArgumentException;

final class ExactDecimal
{
    public static function normalize(string $value, int $scale = 4): string
    {
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('Invalid exact decimal.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('Decimal scale exceeds configured precision.');
        }

        return $whole.'.'.str_pad($fraction, $scale, '0');
    }

    public static function add(string $left, string $right, int $scale = 4): string
    {
        return self::fromMinor(self::minor($left, $scale) + self::minor($right, $scale), $scale);
    }

    public static function subtract(string $left, string $right, int $scale = 4): string
    {
        return self::fromMinor(self::minor($left, $scale) - self::minor($right, $scale), $scale);
    }

    public static function multiply(string $left, string $right, int $scale = 4): string
    {
        $product = self::minor($left, $scale) * self::minor($right, $scale);
        $factor = 10 ** $scale;
        $rounded = intdiv($product + intdiv($factor, 2), $factor);

        return self::fromMinor($rounded, $scale);
    }

    public static function positive(string $value): bool
    {
        return self::minor($value, 4) > 0;
    }

    /**
     * The canonical Money presentation shape: an exact, normalized decimal string paired
     * with an uppercase ISO 4217 currency code. Single source of truth for every context's
     * money() presenter helper — never build this array ad hoc.
     *
     * @return array{amount:string,currency:string}
     */
    public static function money(string $amount, string $currency): array
    {
        return ['amount' => self::normalize($amount), 'currency' => strtoupper($currency)];
    }

    public static function compare(string $left, string $right, int $scale = 4): int
    {
        return self::minor($left, $scale) <=> self::minor($right, $scale);
    }

    private static function minor(string $value, int $scale): int
    {
        $normalized = self::normalize($value, $scale);
        $negative = str_starts_with($normalized, '-');
        $unsigned = ltrim($normalized, '-');
        [$whole, $fraction] = explode('.', $unsigned, 2);
        $minor = ((int) $whole * (10 ** $scale)) + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    private static function fromMinor(int $minor, int $scale): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $factor = 10 ** $scale;
        $value = intdiv($absolute, $factor).'.'.str_pad((string) ($absolute % $factor), $scale, '0', STR_PAD_LEFT);

        return $negative ? '-'.$value : $value;
    }
}
