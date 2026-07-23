<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Payables\DebitNote;
use App\Models\User;
use App\Payables\Application\PayablesReportQuery;
use App\Settlement\Application\AllocationQuery;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * API Contracts §13.9: the frozen five-bucket default AgeingBucketSet for Payables.
 * Payables and Settlement continue to own their reads (AP-001).
 */
final readonly class APAgeingQuery
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private AgeingBucketProvider $buckets,
        private EntityReferenceQuery $entities,
        private PayablesReportQuery $payables,
        private AllocationQuery $allocations,
    ) {}

    public function fetch(User $actor, string $entityId, string $asOf, ?string $vendorId): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.ap_ageing.read')) {
            return $denied;
        }
        $bucketSet = $this->buckets->getEffective($entityId, $asOf);
        if ($bucketSet === null) {
            return $this->commands->error('missing_ageing_bucket_set', 'No effective ageing bucket set is configured.', 422);
        }
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        $bills = $this->payables->openBills($entityId, $vendorId);

        $detail = [];
        $summaryByVendor = [];
        foreach ($bills as $bill) {
            if (ExactDecimal::compare($bill->open_balance, '0.0000') <= 0) {
                continue;
            }
            $daysOverdue = (int) round((strtotime($asOf) - strtotime((string) $bill->due_date->toDateString())) / 86400);
            $bucketId = $bucketSet->bucketFor($daysOverdue) ?? 'not_due';
            $detail[] = ['vendor_id' => $bill->vendor_id, 'bill_id' => $bill->id, 'document_number' => $bill->document_number, 'due_date' => $bill->due_date->toDateString(), 'open_balance' => ['amount' => $bill->open_balance, 'currency' => $bill->currency], 'bucket_id' => $bucketId];
            $summaryByVendor[$bill->vendor_id]['totals'][$bucketId] = ExactDecimal::add($summaryByVendor[$bill->vendor_id]['totals'][$bucketId] ?? '0.0000', $bill->open_balance);
            $summaryByVendor[$bill->vendor_id]['total_open'] = ExactDecimal::add($summaryByVendor[$bill->vendor_id]['total_open'] ?? '0.0000', $bill->open_balance);
        }

        $vendorIds = $vendorId !== null ? [$vendorId] : array_keys($summaryByVendor);
        $summary = [];
        foreach ($vendorIds as $id) {
            $unappliedCredit = $this->payables->postedDebitNotesForVendor($entityId, $id)->reduce(fn (string $sum, DebitNote $n): string => ExactDecimal::add($sum, $n->undisposed_amount), '0.0000');
            $partyCredit = $this->allocations->partyCreditBalanceTotal($entityId, 'vendor', $id);
            $totalsByBucket = array_map(fn (string $bucketId): array => ['bucket_id' => $bucketId, 'amount' => ['amount' => $summaryByVendor[$id]['totals'][$bucketId] ?? '0.0000', 'currency' => $currency]], $bucketSet->bucketIds());
            $summary[] = ['vendor_id' => $id, 'totals_by_bucket' => $totalsByBucket, 'credit_balances' => ['amount' => '0.0000', 'currency' => $currency], 'unapplied_credit' => ['amount' => ExactDecimal::add($unappliedCredit, $partyCredit), 'currency' => $currency], 'total_open' => ['amount' => $summaryByVendor[$id]['total_open'] ?? '0.0000', 'currency' => $currency]];
        }

        return new DocumentActionResult(['as_of' => $asOf, 'bucket_set_version' => $bucketSet->versionNumber, 'detail' => $detail, 'summary' => $summary]);
    }
}
