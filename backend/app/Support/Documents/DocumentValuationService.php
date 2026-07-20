<?php

namespace App\Support\Documents;

use App\CurrencyFx\Application\ApplicableRateQuery;
use App\CurrencyFx\Application\RateReferenceService;
use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Identity\Application\EntityReferenceQuery;
use App\Tax\Application\DocumentTaxService;

final readonly class DocumentValuationService
{
    public function __construct(private DocumentTaxService $taxes, private EntityReferenceQuery $entities, private ApplicableRateQuery $rates, private RateReferenceService $references, private RealisedFxCalculator $calculator) {}

    /** @param list<array<string,mixed>> $lines
     * @return array{lines:list<array<string,mixed>>,subtotal:string,tax_total:string,total:string,rate:array<string,mixed>|null,functional_total:string}|null
     */
    public function value(string $entityId, ?string $jurisdiction, string $date, string $currency, array $lines, ?string $rateId): ?array
    {
        $subtotal = '0.0000';
        $taxTotal = '0.0000';
        $total = '0.0000';
        $valued = [];
        foreach ($lines as $index => $line) {
            $lineAmount = ExactDecimal::multiply((string) $line['quantity'], (string) $line['unit_price']['amount']);
            $tax = $this->taxes->calculate($entityId, $line['tax_code_id'] ?? null, $jurisdiction, $date, $lineAmount);
            if ($tax === null) {
                return null;
            }$valued[] = ['id' => $line['id'] ?? null, 'line_no' => $index + 1, 'description' => $line['description'], 'quantity' => ExactDecimal::normalize((string) $line['quantity']), 'unit_price' => ExactDecimal::normalize((string) $line['unit_price']['amount']), 'tax_code_id' => $line['tax_code_id'] ?? null, 'tax_snapshot' => $tax['snapshot'], 'line_amount' => $tax['net'], 'tax_amount' => $tax['tax'], 'total_amount' => $tax['total'], 'expense_account_id' => $line['expense_account_id'] ?? null];
            $subtotal = ExactDecimal::add($subtotal, $tax['net']);
            $taxTotal = ExactDecimal::add($taxTotal, $tax['tax']);
            $total = ExactDecimal::add($total, $tax['total']);
        }
        $functional = $this->entities->functionalCurrency($entityId);
        if ($functional === null) {
            return null;
        }$reference = null;
        $functionalTotal = $total;
        if ($currency !== $functional) {
            $reference = $rateId !== null ? $this->references->exactById($entityId, $rateId, $currency, $functional, $date) : $this->rates->find($entityId, $currency, $functional, $date);
            if ($reference === null) {
                return null;
            }$scale = config('valuation.fx.rounding_scale');
            $mode = config('valuation.fx.rounding_mode');
            if (! is_numeric($scale) || ! is_string($mode)) {
                return null;
            }$functionalTotal = $this->calculator->calculate($total, (string) $reference['rate'], (string) $reference['rate'], (int) $scale, $mode)['document_functional'];
        }

        return ['lines' => $valued, 'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => $total, 'rate' => $reference, 'functional_total' => $functionalTotal];
    }

    /** @param array<string,mixed>|null $reference */
    public function functional(string $amount, ?array $reference): ?string
    {
        if ($reference === null) {
            return ExactDecimal::normalize($amount);
        }
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($scale) || ! is_string($mode)) {
            return null;
        }

        return $this->calculator->calculate($amount, (string) $reference['rate'], (string) $reference['rate'], (int) $scale, $mode)['document_functional'];
    }

    /** @param list<array<string,mixed>> $lines */
    public function markTaxSnapshotsReferenced(string $entityId, array $lines): void
    {
        foreach ($lines as $line) {
            $snapshot = $line['tax_snapshot'] ?? null;
            $this->taxes->markReferenced($entityId, is_array($snapshot) ? $snapshot : null);
        }
    }
}
