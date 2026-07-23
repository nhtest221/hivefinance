<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Receivables\CreditNote;
use App\Models\Receivables\Invoice;
use App\Models\Settlement\PartyCreditBalance;
use App\Models\User;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/** API Contracts §13.9: the frozen five-bucket default AgeingBucketSet for Receivables. */
final readonly class ARAgeingQuery
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private AgeingBucketProvider $buckets,
        private EntityReferenceQuery $entities,
    ) {}

    public function fetch(User $actor, string $entityId, string $asOf, ?string $customerId): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.ar_ageing.read')) {
            return $denied;
        }
        $bucketSet = $this->buckets->getEffective($entityId, $asOf);
        if ($bucketSet === null) {
            return $this->commands->error('missing_ageing_bucket_set', 'No effective ageing bucket set is configured.', 422);
        }
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        $invoices = Invoice::query()->where('entity_id', $entityId)
            ->whereIn('status', ['sent', 'partially_paid'])
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->orderBy('due_date')->get();

        $detail = [];
        $summaryByCustomer = [];
        foreach ($invoices as $invoice) {
            if (ExactDecimal::compare($invoice->open_balance, '0.0000') <= 0) {
                continue;
            }
            $daysOverdue = (int) round((strtotime($asOf) - strtotime((string) $invoice->due_date->toDateString())) / 86400);
            $bucketId = $bucketSet->bucketFor($daysOverdue) ?? 'not_due';
            $detail[] = ['customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'document_number' => $invoice->document_number, 'due_date' => $invoice->due_date->toDateString(), 'open_balance' => ['amount' => $invoice->open_balance, 'currency' => $invoice->currency], 'bucket_id' => $bucketId];
            $summaryByCustomer[$invoice->customer_id]['totals'][$bucketId] = ExactDecimal::add($summaryByCustomer[$invoice->customer_id]['totals'][$bucketId] ?? '0.0000', $invoice->open_balance);
            $summaryByCustomer[$invoice->customer_id]['total_open'] = ExactDecimal::add($summaryByCustomer[$invoice->customer_id]['total_open'] ?? '0.0000', $invoice->open_balance);
        }

        $customerIds = $customerId !== null ? [$customerId] : array_keys($summaryByCustomer);
        $summary = [];
        foreach ($customerIds as $id) {
            $unappliedCredit = CreditNote::query()->where('entity_id', $entityId)->where('customer_id', $id)->where('state', 'posted')->get()->reduce(fn (string $sum, CreditNote $n): string => ExactDecimal::add($sum, $n->undisposed_amount), '0.0000');
            $partyCredit = PartyCreditBalance::query()->where('entity_id', $entityId)->where('party_type', 'customer')->where('party_id', $id)->get()->reduce(fn (string $sum, PartyCreditBalance $b): string => ExactDecimal::add($sum, $b->available_balance), '0.0000');
            $totalsByBucket = array_map(fn (string $bucketId): array => ['bucket_id' => $bucketId, 'amount' => ['amount' => $summaryByCustomer[$id]['totals'][$bucketId] ?? '0.0000', 'currency' => $currency]], $bucketSet->bucketIds());
            $summary[] = ['customer_id' => $id, 'totals_by_bucket' => $totalsByBucket, 'credit_balances' => ['amount' => '0.0000', 'currency' => $currency], 'unapplied_credit' => ['amount' => ExactDecimal::add($unappliedCredit, $partyCredit), 'currency' => $currency], 'total_open' => ['amount' => $summaryByCustomer[$id]['total_open'] ?? '0.0000', 'currency' => $currency]];
        }

        return new DocumentActionResult(['as_of' => $asOf, 'bucket_set_version' => $bucketSet->versionNumber, 'detail' => $detail, 'summary' => $summary]);
    }
}
