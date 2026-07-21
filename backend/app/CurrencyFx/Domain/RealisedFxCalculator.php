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

    public function subtract(string $left, string $right, int $scale): string
    {
        return $this->format($this->units($left, $scale) - $this->units($right, $scale), 10 ** $scale, $scale);
    }

    /** @return array<string,string> */
    public function calculateSettlement(string $foreignAmount, string $documentRate, string $settlementRate, string $partyType, int $scale, string $roundingMode): array
    {
        $result = $this->calculate($foreignAmount, $documentRate, $settlementRate, $scale, $roundingMode);
        $signed = $partyType === 'vendor' ? $this->subtract('0.0000', $result['realised_fx'], $scale) : $result['realised_fx'];

        return [...$result, 'realised_fx' => $signed, 'classification' => $this->classification($signed, $scale)];
    }

    /** @return array{source_functional:string,comparison_functional:string,realised_fx:string,classification:string} */
    public function calculateCredit(string $foreignAmount, string $sourceRate, string $comparisonRate, string $partyType, int $scale, string $roundingMode): array
    {
        $values = $this->calculate($foreignAmount, $sourceRate, $comparisonRate, $scale, $roundingMode);
        $raw = $this->subtract($values['document_functional'], $values['settlement_functional'], $scale);
        $signed = $partyType === 'vendor' ? $this->subtract('0.0000', $raw, $scale) : $raw;

        return ['source_functional' => $values['document_functional'], 'comparison_functional' => $values['settlement_functional'], 'realised_fx' => $signed, 'classification' => $this->classification($signed, $scale)];
    }

    public function isZero(string $value, int $scale): bool
    {
        return $this->units($value, $scale) === 0;
    }

    private function classification(string $value, int $scale): string
    {
        $units = $this->units($value, $scale);

        return $units === 0 ? 'none' : ($units > 0 ? 'gain' : 'loss');
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
