<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use App\Models\Settlement\PartyCreditBalance;
use App\Models\User;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/** API Contracts §13.9: the frozen five-bucket default AgeingBucketSet for Payables. */
final readonly class APAgeingQuery
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private AgeingBucketProvider $buckets,
        private EntityReferenceQuery $entities,
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

        $bills = Bill::query()->where('entity_id', $entityId)
            ->whereIn('status', ['awaiting_payment', 'partially_paid'])
            ->when($vendorId !== null, fn ($q) => $q->where('vendor_id', $vendorId))
            ->orderBy('due_date')->get();

        $detail = [];
        $summaryByVendor = [];
        foreach ($bills as $bill) {
            if (ExactDecimal::compare($bill->open_balance, '0.0000') <= 0) {
                continue;
            }
            $daysOverdue = (int) round((strtotime($asOf) - strtotime($bill->due_date->toDateString())) / 86400);
            $bucketId = $bucketSet->bucketFor($daysOverdue) ?? 'not_due';
            $detail[] = ['vendor_id' => $bill->vendor_id, 'bill_id' => $bill->id, 'document_number' => $bill->document_number, 'due_date' => $bill->due_date->toDateString(), 'open_balance' => ['amount' => $bill->open_balance, 'currency' => $bill->currency], 'bucket_id' => $bucketId];
            $summaryByVendor[$bill->vendor_id]['totals'][$bucketId] = ExactDecimal::add($summaryByVendor[$bill->vendor_id]['totals'][$bucketId] ?? '0.0000', $bill->open_balance);
            $summaryByVendor[$bill->vendor_id]['total_open'] = ExactDecimal::add($summaryByVendor[$bill->vendor_id]['total_open'] ?? '0.0000', $bill->open_balance);
        }

        $vendorIds = $vendorId !== null ? [$vendorId] : array_keys($summaryByVendor);
        $summary = [];
        foreach ($vendorIds as $id) {
            $unappliedCredit = DebitNote::query()->where('entity_id', $entityId)->where('vendor_id', $id)->where('state', 'posted')->get()->reduce(fn (string $sum, DebitNote $n): string => ExactDecimal::add($sum, $n->undisposed_amount), '0.0000');
            $partyCredit = PartyCreditBalance::query()->where('entity_id', $entityId)->where('party_type', 'vendor')->where('party_id', $id)->get()->reduce(fn (string $sum, PartyCreditBalance $b): string => ExactDecimal::add($sum, $b->available_balance), '0.0000');
            $totalsByBucket = array_map(fn (string $bucketId): array => ['bucket_id' => $bucketId, 'amount' => ['amount' => $summaryByVendor[$id]['totals'][$bucketId] ?? '0.0000', 'currency' => $currency]], $bucketSet->bucketIds());
            $summary[] = ['vendor_id' => $id, 'totals_by_bucket' => $totalsByBucket, 'credit_balances' => ['amount' => '0.0000', 'currency' => $currency], 'unapplied_credit' => ['amount' => ExactDecimal::add($unappliedCredit, $partyCredit), 'currency' => $currency], 'total_open' => ['amount' => $summaryByVendor[$id]['total_open'] ?? '0.0000', 'currency' => $currency]];
        }

        return new DocumentActionResult(['as_of' => $asOf, 'bucket_set_version' => $bucketSet->versionNumber, 'detail' => $detail, 'summary' => $summary]);
    }
}
