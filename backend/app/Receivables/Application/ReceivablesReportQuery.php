<?php

namespace App\Receivables\Application;

use App\Models\Receivables\CreditNote;
use App\Models\Receivables\Invoice;
use Illuminate\Support\Collection;

/**
 * Receivables-owned read contract for Reporting's AR Ageing (API Contracts §13.9) and Tax
 * Summary (§13.10). Receivables continues to own invoices/credit_notes reads; Reporting
 * never queries those tables directly (AP-001).
 */
interface ReceivablesReportQuery
{
    /**
     * Open (sent/partially_paid) invoices, optionally scoped to one customer, ordered by
     * due_date — AR Ageing's source pool.
     *
     * @return Collection<int, Invoice>
     */
    public function openInvoices(string $entityId, ?string $customerId): Collection;

    /**
     * Sent/partially_paid/paid invoices whose invoice_date falls within the window, with
     * lines eager-loaded — Tax Summary's output-VAT source pool.
     *
     * @return Collection<int, Invoice>
     */
    public function invoicesWithLinesInRange(string $entityId, string $from, string $to): Collection;

    /**
     * Posted CreditNotes for a set of customers in one query — AR Ageing's unapplied-credit
     * source, batched to avoid an N+1 over the customer list.
     *
     * @param  list<string>  $customerIds
     * @return Collection<int, CreditNote>
     */
    public function postedCreditNotesForCustomers(string $entityId, array $customerIds): Collection;

    /**
     * Posted CreditNotes for a period, with lines eager-loaded — Tax Summary's output-VAT
     * source pool.
     *
     * @return Collection<int, CreditNote>
     */
    public function postedCreditNotesWithLinesForPeriod(string $entityId, string $periodRef): Collection;
}
