<?php

namespace App\Payables\Application;

use App\Models\Payables\DebitNote;
use App\Models\Payables\DebitNoteDisposition;
use App\Models\Payables\DebitNoteReversal;

/**
 * Repository Contracts §"BillRepository / DebitNoteRepository / ExpenseRepository" — mirror
 * of CreditNoteRepository with vendor/bill direction. See
 * App\Receivables\Application\CreditNoteRepository for the full contract rationale.
 */
interface DebitNoteRepository
{
    public function getById(string $entityId, string $id): ?DebitNote;

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>  $lines
     */
    public function addDraft(array $attributes, array $lines): DebitNote;

    /**
     * @param  array<string,mixed>  $attributes
     * @param  list<array<string,mixed>>  $lines
     */
    public function saveDraft(string $entityId, string $id, array $attributes, array $lines, int $expectedVersion): ?DebitNote;

    /** @param  array<string,mixed>  $attributes */
    public function commitPost(string $entityId, string $id, array $attributes, int $expectedVersion): ?DebitNote;

    /**
     * @param  array<string,mixed>  $noteAttributes
     * @param  array<string,mixed>  $dispositionAttributes
     */
    public function appendDisposition(string $entityId, string $id, array $noteAttributes, array $dispositionAttributes, int $expectedVersion): ?DebitNoteDisposition;

    /** @param  list<array<string,mixed>>  $applicationRows */
    public function recordApplications(array $applicationRows): void;

    /** @param  array<string,mixed>  $reversalAttributes */
    public function commitReversal(string $entityId, string $id, array $reversalAttributes, int $expectedVersion): ?DebitNoteReversal;

    public function findReversal(string $entityId, string $id): ?DebitNoteReversal;
}
