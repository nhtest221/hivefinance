<?php

namespace App\CurrencyFx\Domain;

use InvalidArgumentException;

final class RealisedFxCalculator
{
    /** @return array<string, string> */
    public function calculate(string $foreignAmount, string $documentRate, string $settlementRate, int $scale, string $roundingMode): array
    {
        if ($scale < 0 || $scale > 4 || ! in_array($roundingMode, ['half_up', 'half_even'], true)) {
            throw new InvalidArgumentException('Configured FX precision and rounding policy are required.');
        }
        $document = $this->multiply($foreignAmount, $documentRate, $scale, $roundingMode);
        $settlement = $this->multiply($foreignAmount, $settlementRate, $scale, $roundingMode);
        $factor = 10 ** $scale;
        $difference = $this->units($settlement, $scale) - $this->units($document, $scale);

        return ['document_functional' => $document, 'settlement_functional' => $settlement, 'realised_fx' => $this->format($difference, $factor, $scale), 'classification' => $difference >= 0 ? 'gain' : 'loss'];
    }

    private function multiply(string $amount, string $rate, int $scale, string $mode): string
    {
        $a = $this->scaled($amount, 4);
        $r = $this->scaled($rate, 8);
        $divisor = 10 ** (12 - $scale);
        $product = $a * $r;
        $quotient = intdiv($product, $divisor);
        $remainder = abs($product % $divisor);
        $half = intdiv($divisor, 2);
        if ($remainder > $half || ($remainder === $half && ($mode === 'half_up' || abs($quotient) % 2 === 1))) {
            $quotient += $product >= 0 ? 1 : -1;
        }

        return $this->format($quotient, 10 ** $scale, $scale);
    }

    private function scaled(string $value, int $scale): int
    {
        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException('FX values must be exact decimal strings.');
        }
        $negative = str_starts_with($value, '-');
        $parts = explode('.', ltrim($value, '-'));

        return ($negative ? -1 : 1) * (((int) $parts[0] * (10 ** $scale)) + (int) str_pad(substr($parts[1] ?? '', 0, $scale), $scale, '0'));
    }

    private function units(string $value, int $scale): int
    {
        return $this->scaled($value, $scale);
    }

    private function format(int $units, int $factor, int $scale): string
    {
        $negative = $units < 0;
        $units = abs($units);

        return ($negative ? '-' : '').intdiv($units, $factor).'.'.str_pad((string) ($units % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
