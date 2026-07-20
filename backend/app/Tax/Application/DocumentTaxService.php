<?php

namespace App\Tax\Application;

use App\Models\Tax\TaxCodeVersion;
use App\Support\Documents\ExactDecimal;
use InvalidArgumentException;

final readonly class DocumentTaxService
{
    public function __construct(private ApplicableTaxQuery $taxes) {}

    /** @return array{snapshot:array<string,mixed>|null,net:string,tax:string,total:string}|null */
    public function calculate(string $entityId, ?string $taxCodeId, ?string $jurisdiction, string $date, string $gross): ?array
    {
        $gross = ExactDecimal::normalize($gross);
        if ($taxCodeId === null) {
            return ['snapshot' => null, 'net' => $gross, 'tax' => '0.0000', 'total' => $gross];
        }
        if ($jurisdiction === null) {
            return null;
        }
        $snapshot = $this->taxes->determine($entityId, $taxCodeId, $jurisdiction, $date);
        if ($snapshot === null) {
            return null;
        }$values = $snapshot->toArray();
        if (in_array($values['treatment'] ?? null, ['zero_rated', 'exempt'], true) || (string) ($values['rate'] ?? '0.00000000') === '0.00000000') {
            return ['snapshot' => $values, 'net' => $gross, 'tax' => '0.0000', 'total' => $gross];
        }
        $method = (string) ($values['calculation_method'] ?? '');
        $exclusive = (array) config('valuation.tax.exclusive_methods');
        $inclusive = (array) config('valuation.tax.inclusive_methods');
        if (! in_array($method, $exclusive, true) && ! in_array($method, $inclusive, true)) {
            return null;
        }
        try {
            $tax = in_array($method, $exclusive, true) ? $this->percent($gross, (string) $values['rate']) : $this->inclusiveTax($gross, (string) $values['rate']);
        } catch (InvalidArgumentException) {
            return null;
        }

        return in_array($method, $exclusive, true) ? ['snapshot' => $values, 'net' => $gross, 'tax' => $tax, 'total' => ExactDecimal::add($gross, $tax)] : ['snapshot' => $values, 'net' => ExactDecimal::subtract($gross, $tax), 'tax' => $tax, 'total' => $gross];
    }

    private function percent(string $amount, string $rate): string
    {
        $a = $this->units($amount, 4);
        $r = $this->units($rate, 8);
        $denominator = 10_000_000_000;
        $product = $a * $r;
        $units = intdiv($product + intdiv($denominator, 2), $denominator);

        return $this->format($units, 4);
    }

    /** @param array<string,mixed>|null $snapshot */
    public function markReferenced(string $entityId, ?array $snapshot): void
    {
        if (is_string($snapshot['tax_code_version_id'] ?? null)) {
            TaxCodeVersion::query()->where('entity_id', $entityId)->whereKey($snapshot['tax_code_version_id'])->where('referenced', false)->update(['referenced' => true]);
        }
    }

    private function inclusiveTax(string $total, string $rate): string
    {
        $t = $this->units($total, 4);
        $r = $this->units($rate, 8);
        $denominator = 10_000_000_000 + $r;
        $units = intdiv(($t * $r) + intdiv($denominator, 2), $denominator);

        return $this->format($units, 4);
    }

    private function units(string $value, int $scale): int
    {
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/', $value) !== 1) {
            throw new InvalidArgumentException;
        }$negative = str_starts_with($value, '-');
        [$w,$f] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        if (strlen($f) > $scale) {
            throw new InvalidArgumentException;
        }$units = ((int) $w * (10 ** $scale)) + (int) str_pad($f, $scale, '0');

        return $negative ? -$units : $units;
    }

    private function format(int $units, int $scale): string
    {
        $negative = $units < 0;
        $units = abs($units);
        $factor = 10 ** $scale;

        return ($negative ? '-' : '').intdiv($units, $factor).'.'.str_pad((string) ($units % $factor), $scale, '0', STR_PAD_LEFT);
    }
}
