<?php

namespace App\Receivables\Application;

use App\Models\Receivables\CreditNote;
use App\Models\Receivables\CreditNoteDisposition;
use App\Models\Receivables\CreditNoteReversal;

/**
 * Repository Contracts §"InvoiceRepository / CreditNoteRepository". Aggregate-root
 * granularity: loads/saves the whole CreditNote (header + lines) as one unit; disposition,
 * application, and reversal facts are appended, never rewritten. Optimistic concurrency via
 * `expectedVersion` on every mutating method; a stale version returns null (never throws) so
 * callers can map it to the frozen `409 concurrency_conflict` response uniformly.
 */
interface CreditNoteRepository
{
    public function getById(string $entityId, string $id): ?CreditNote;

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>  $lines
     */
    public function addDraft(array $attributes, array $lines): CreditNote;

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>  $lines
     */
    public function saveDraft(string $entityId, string $id, array $attributes, array $lines, int $expectedVersion): ?CreditNote;

    /** @param  array<string,mixed>  $attributes */
    public function commitPost(string $entityId, string $id, array $attributes, int $expectedVersion): ?CreditNote;

    /**
     * Appends one disposition fact and conditionally updates the note's five-field
     * current-state amounts and version in the same guarded write.
     *
     * @param  array<string,mixed>  $noteAttributes
     * @param  array<string,mixed>  $dispositionAttributes
     */
    public function appendDisposition(string $entityId, string $id, array $noteAttributes, array $dispositionAttributes, int $expectedVersion): ?CreditNoteDisposition;

    /** @param  list<array<string,mixed>>  $applicationRows */
    public function recordApplications(array $applicationRows): void;

    /** @param  array<string,mixed>  $reversalAttributes */
    public function commitReversal(string $entityId, string $id, array $reversalAttributes, int $expectedVersion): ?CreditNoteReversal;

    public function findReversal(string $entityId, string $id): ?CreditNoteReversal;
}
