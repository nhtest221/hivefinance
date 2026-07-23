<?php

namespace App\Receivables\Infrastructure;

use App\Models\Receivables\CreditNote;
use App\Models\Receivables\Invoice;
use App\Receivables\Application\ReceivablesReportQuery;
use Illuminate\Support\Collection;

final class EloquentReceivablesReportQuery implements ReceivablesReportQuery
{
    /** @return Collection<int, Invoice> */
    public function openInvoices(string $entityId, ?string $customerId): Collection
    {
        return Invoice::query()->where('entity_id', $entityId)
            ->whereIn('status', ['sent', 'partially_paid'])
            ->when($customerId !== null, fn ($query) => $query->where('customer_id', $customerId))
            ->orderBy('due_date')->get();
    }

    /** @return Collection<int, Invoice> */
    public function invoicesWithLinesInRange(string $entityId, string $from, string $to): Collection
    {
        return Invoice::query()->where('entity_id', $entityId)
            ->whereIn('status', ['sent', 'partially_paid', 'paid'])
            ->whereBetween('invoice_date', [$from, $to])
            ->with('lines')->get();
    }

    /** @param list<string> $customerIds
     * @return Collection<int, CreditNote> */
    public function postedCreditNotesForCustomers(string $entityId, array $customerIds): Collection
    {
        if ($customerIds === []) {
            return new Collection;
        }

        return CreditNote::query()->where('entity_id', $entityId)
            ->whereIn('customer_id', $customerIds)
            ->where('state', 'posted')->get();
    }

    /** @return Collection<int, CreditNote> */
    public function postedCreditNotesWithLinesForPeriod(string $entityId, string $periodRef): Collection
    {
        return CreditNote::query()->where('entity_id', $entityId)
            ->where('state', 'posted')->where('period_ref', $periodRef)
            ->with('lines')->get();
    }
}
