<?php

namespace App\Payables\Application;

use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use Illuminate\Support\Collection;

/**
 * Payables-owned read contract for Reporting's AP Ageing (API Contracts §13.9) and Tax
 * Summary (§13.10). Payables continues to own bills/debit_notes reads; Reporting never
 * queries those tables directly (AP-001). Mirror of ReceivablesReportQuery.
 */
interface PayablesReportQuery
{
    /**
     * Open (awaiting_payment/partially_paid) bills, optionally scoped to one vendor,
     * ordered by due_date — AP Ageing's source pool.
     *
     * @return Collection<int, Bill>
     */
    public function openBills(string $entityId, ?string $vendorId): Collection;

    /**
     * Awaiting_payment/partially_paid/paid bills whose bill_date falls within the window,
     * with lines eager-loaded — Tax Summary's input-VAT source pool.
     *
     * @return Collection<int, Bill>
     */
    public function billsWithLinesInRange(string $entityId, string $from, string $to): Collection;

    /**
     * Posted DebitNotes for a set of vendors in one query — AP Ageing's unapplied-credit
     * source, batched to avoid an N+1 over the vendor list.
     *
     * @param  list<string>  $vendorIds
     * @return Collection<int, DebitNote>
     */
    public function postedDebitNotesForVendors(string $entityId, array $vendorIds): Collection;

    /**
     * Posted DebitNotes for a period, with lines eager-loaded — Tax Summary's input-VAT
     * source pool.
     *
     * @return Collection<int, DebitNote>
     */
    public function postedDebitNotesWithLinesForPeriod(string $entityId, string $periodRef): Collection;
}
