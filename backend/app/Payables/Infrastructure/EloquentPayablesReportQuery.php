<?php

namespace App\Payables\Infrastructure;

use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use App\Payables\Application\PayablesReportQuery;
use Illuminate\Support\Collection;

final class EloquentPayablesReportQuery implements PayablesReportQuery
{
    /** @return Collection<int, Bill> */
    public function openBills(string $entityId, ?string $vendorId): Collection
    {
        return Bill::query()->where('entity_id', $entityId)
            ->whereIn('status', ['awaiting_payment', 'partially_paid'])
            ->when($vendorId !== null, fn ($query) => $query->where('vendor_id', $vendorId))
            ->orderBy('due_date')->get();
    }

    /** @return Collection<int, Bill> */
    public function billsWithLinesInRange(string $entityId, string $from, string $to): Collection
    {
        return Bill::query()->where('entity_id', $entityId)
            ->whereIn('status', ['awaiting_payment', 'partially_paid', 'paid'])
            ->whereBetween('bill_date', [$from, $to])
            ->with('lines')->get();
    }

    /** @return Collection<int, DebitNote> */
    public function postedDebitNotesForVendor(string $entityId, string $vendorId): Collection
    {
        return DebitNote::query()->where('entity_id', $entityId)
            ->where('vendor_id', $vendorId)
            ->where('state', 'posted')->get();
    }

    /** @return Collection<int, DebitNote> */
    public function postedDebitNotesWithLinesForPeriod(string $entityId, string $periodRef): Collection
    {
        return DebitNote::query()->where('entity_id', $entityId)
            ->where('state', 'posted')->where('period_ref', $periodRef)
            ->with('lines')->get();
    }
}
