<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use App\Models\Receivables\CreditNote;
use App\Models\Receivables\Invoice;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * API Contracts §13.10: aggregates already-immutable TaxSnapshot records by their
 * already-frozen return_box_mapping keys (ADR-006) — no filing form or rate invented.
 */
final readonly class TaxSummaryQuery
{
    public function __construct(private DocumentCommandSupport $commands, private PeriodQuery $periods, private EntityReferenceQuery $entities) {}

    public function fetch(User $actor, string $entityId, string $periodRef): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.tax_summary.read')) {
            return $denied;
        }
        $period = $this->periods->show($entityId, $periodRef);
        if ($period === null) {
            return $this->commands->error('not_found', 'The period was not found.', 404);
        }
        $from = $period->starts_on->toDateString();
        $to = $period->ends_on->toDateString();

        $outputVat = '0.0000';
        $inputVat = '0.0000';
        $boxes = [];
        $jurisdiction = null;

        $invoices = Invoice::query()->where('entity_id', $entityId)->whereIn('status', ['sent', 'partially_paid', 'paid'])
            ->whereBetween('invoice_date', [$from, $to])->with('lines')->get();
        foreach ($invoices as $invoice) {
            foreach ($invoice->lines as $line) {
                $this->accumulate($invoice->id, $line->tax_snapshot, $line->tax_amount, true, $outputVat, $inputVat, $boxes, $jurisdiction);
            }
        }

        $creditNotes = CreditNote::query()->where('entity_id', $entityId)->where('state', 'posted')->where('period_ref', $periodRef)->with('lines')->get();
        foreach ($creditNotes as $note) {
            foreach ($note->lines as $line) {
                $this->accumulate($note->id, $line->tax_snapshot, $line->tax_amount, true, $outputVat, $inputVat, $boxes, $jurisdiction);
            }
        }

        $bills = Bill::query()->where('entity_id', $entityId)->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid'])
            ->whereBetween('bill_date', [$from, $to])->with('lines')->get();
        foreach ($bills as $bill) {
            foreach ($bill->lines as $line) {
                $this->accumulate($bill->id, $line->tax_snapshot, $line->tax_amount, false, $outputVat, $inputVat, $boxes, $jurisdiction);
            }
        }

        $debitNotes = DebitNote::query()->where('entity_id', $entityId)->where('state', 'posted')->where('period_ref', $periodRef)->with('lines')->get();
        foreach ($debitNotes as $note) {
            foreach ($note->lines as $line) {
                $this->accumulate($note->id, $line->tax_snapshot, $line->tax_amount, false, $outputVat, $inputVat, $boxes, $jurisdiction);
            }
        }

        $netVat = ExactDecimal::subtract($outputVat, $inputVat);
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        $boxRows = [];
        foreach ($boxes as $key => $box) {
            $boxRows[] = ['return_box_key' => $key, 'amount' => ['amount' => $box['amount'], 'currency' => $currency], 'document_ids' => array_values(array_unique($box['document_ids']))];
        }

        return new DocumentActionResult([
            'period_ref' => $periodRef,
            'jurisdiction' => $jurisdiction,
            'output_vat' => ['amount' => $outputVat, 'currency' => $currency],
            'recoverable_input_vat' => ['amount' => $inputVat, 'currency' => $currency],
            'net_vat' => ['amount' => $netVat, 'currency' => $currency],
            'boxes' => $boxRows,
        ]);
    }

    /**
     * @param  array<string, array{amount: string, document_ids: list<string>}>  $boxes
     */
    private function accumulate(string $documentId, mixed $taxSnapshot, string $lineTaxAmount, bool $isOutput, string &$outputVat, string &$inputVat, array &$boxes, ?string &$jurisdiction): void
    {
        if (! is_array($taxSnapshot) || ! isset($taxSnapshot['rate'], $taxSnapshot['recoverable'])) {
            return;
        }
        $jurisdiction ??= is_string($taxSnapshot['jurisdiction'] ?? null) ? $taxSnapshot['jurisdiction'] : null;
        $amount = $lineTaxAmount;
        if ($isOutput) {
            $outputVat = ExactDecimal::add($outputVat, $amount);
        } elseif ($taxSnapshot['recoverable'] === true) {
            $inputVat = ExactDecimal::add($inputVat, $amount);
        } else {
            return;
        }
        $mapping = is_array($taxSnapshot['return_box_mapping'] ?? null) ? $taxSnapshot['return_box_mapping'] : [];
        $boxKey = (string) ($mapping['output'] ?? $mapping['input'] ?? 'unmapped');
        $box = $boxes[$boxKey] ?? ['amount' => '0.0000', 'document_ids' => []];
        $box['amount'] = ExactDecimal::add($box['amount'], $amount);
        $box['document_ids'][] = $documentId;
        $boxes[$boxKey] = $box;
    }
}
