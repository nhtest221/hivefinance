<?php

namespace App\Reconciliation\Application;

use App\Models\Reconciliation\BankReconciliation;
use App\Models\Reconciliation\ReconciliationStatementLine;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** API Contracts §14; Repository Contracts §4; Aggregate Design §13. */
interface BankReconciliationRepository
{
    public function getById(string $entityId, string $id): ?BankReconciliation;

    /** The current Completed reconciliation for a period — the Close-Gate lookup (API Contracts §14.11). */
    public function findCurrentCompleted(string $entityId, string $reconciliationAccountId, string $periodRef): ?BankReconciliation;

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, BankReconciliation>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator;

    /** @param array<string, mixed> $attributes */
    public function addDraft(array $attributes): BankReconciliation;

    public function bumpToInProgress(string $entityId, string $id): void;

    public function getLine(string $entityId, string $reconciliationId, string $lineId): ?ReconciliationStatementLine;

    /** @return Collection<int, ReconciliationStatementLine> */
    public function linesFor(string $reconciliationId): Collection;

    /** @param list<string> $statuses
     * @return Collection<int, ReconciliationStatementLine> */
    public function linesByStatus(string $reconciliationId, array $statuses): Collection;

    public function importFileExists(string $reconciliationAccountId, string $fileHash): bool;

    public function duplicateLineExists(string $reconciliationAccountId, string $transactionDate, string $amount, string $currency, string $normalizedNarration, ?string $externalBankReference): bool;

    /**
     * Rejects (never persists) any line whose duplicate identity already exists for this
     * account's full history (API Contracts §14.5); every other line is inserted Unreconciled.
     *
     * @param  array<string, mixed>  $batchAttributes
     * @param  list<array<string, mixed>>  $lines
     * @return array{imported: int, conflicts: list<array<string, mixed>>}
     */
    public function appendImportedLines(string $entityId, string $reconciliationId, string $reconciliationAccountId, array $batchAttributes, array $lines): array;

    /**
     * Replaces a line's attached (non-superseded) suggestions and sets its status.
     *
     * @param  list<array<string, mixed>>  $suggestions
     */
    public function replaceSuggestions(string $lineId, string $newStatus, array $suggestions, int $expectedLineVersion): ?ReconciliationStatementLine;

    /** @param list<string> $allocationIds */
    public function commitMatch(string $lineId, array $allocationIds, int $expectedLineVersion): ?ReconciliationStatementLine;

    public function commitConfirm(string $lineId, int $expectedLineVersion): ?ReconciliationStatementLine;

    public function commitBankOnlyResolution(string $lineId, string $journalEntryId, int $expectedLineVersion): ?ReconciliationStatementLine;

    public function commitCompletion(string $entityId, string $id, int $expectedVersion, Carbon $watermark, string $contentHash, string $actorId): ?BankReconciliation;

    public function commitReopen(string $entityId, string $id, int $expectedVersion, string $actorId): ?BankReconciliation;

    /** True if any of these Allocations is already consumed by a Reconciled line other than $excludingLineId.
     * @param list<string> $allocationIds */
    public function allocationsConsumedElsewhere(string $reconciliationAccountId, array $allocationIds, ?string $excludingLineId): bool;
}
