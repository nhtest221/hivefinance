<?php

namespace App\Reconciliation\Infrastructure;

use App\Models\Reconciliation\BankReconciliation;
use App\Models\Reconciliation\ReconciliationImportBatch;
use App\Models\Reconciliation\ReconciliationMatchSuggestion;
use App\Models\Reconciliation\ReconciliationMatchSuggestionAllocation;
use App\Models\Reconciliation\ReconciliationStatementLine;
use App\Reconciliation\Application\BankReconciliationRepository;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentBankReconciliationRepository implements BankReconciliationRepository
{
    public function getById(string $entityId, string $id): ?BankReconciliation
    {
        return BankReconciliation::query()->where('entity_id', $entityId)->find($id);
    }

    public function findCurrentCompleted(string $entityId, string $reconciliationAccountId, string $periodRef): ?BankReconciliation
    {
        return BankReconciliation::query()->where('entity_id', $entityId)->where('reconciliation_account_id', $reconciliationAccountId)
            ->where('period_ref', $periodRef)->where('state', 'Completed')->orderByDesc('completed_at')->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, BankReconciliation>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator
    {
        $query = BankReconciliation::query()->where('entity_id', $entityId)
            ->when($filters['bank_account_id'] ?? null, fn ($q, $v) => $q->where('reconciliation_account_id', $v))
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v));

        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /** @param array<string, mixed> $attributes */
    public function addDraft(array $attributes): BankReconciliation
    {
        return BankReconciliation::query()->create([...$attributes, 'state' => 'Draft', 'version' => 1]);
    }

    public function bumpToInProgress(string $entityId, string $id): void
    {
        BankReconciliation::query()->where('entity_id', $entityId)->where('id', $id)->where('state', 'Draft')
            ->update(['state' => 'InProgress', 'version' => DB::raw('version + 1')]);
    }

    public function getLine(string $entityId, string $reconciliationId, string $lineId): ?ReconciliationStatementLine
    {
        if ($this->getById($entityId, $reconciliationId) === null) {
            return null;
        }

        return ReconciliationStatementLine::query()->where('reconciliation_id', $reconciliationId)->find($lineId);
    }

    /** @return Collection<int, ReconciliationStatementLine> */
    public function linesFor(string $reconciliationId): Collection
    {
        return ReconciliationStatementLine::query()->where('reconciliation_id', $reconciliationId)->get();
    }

    /** @param list<string> $statuses
     * @return Collection<int, ReconciliationStatementLine> */
    public function linesByStatus(string $reconciliationId, array $statuses): Collection
    {
        return ReconciliationStatementLine::query()->where('reconciliation_id', $reconciliationId)->whereIn('status', $statuses)->get();
    }

    public function importFileExists(string $reconciliationAccountId, string $fileHash): bool
    {
        return ReconciliationImportBatch::query()->where('reconciliation_account_id', $reconciliationAccountId)->where('file_hash', $fileHash)->exists();
    }

    public function duplicateLineExists(string $reconciliationAccountId, string $transactionDate, string $amount, string $currency, string $normalizedNarration, ?string $externalBankReference): bool
    {
        return ReconciliationStatementLine::query()
            ->where('reconciliation_account_id', $reconciliationAccountId)
            ->where('transaction_date', $transactionDate)
            ->where('amount', $amount)
            ->where('currency', $currency)
            ->where('normalized_narration', $normalizedNarration)
            ->whereRaw("COALESCE(external_bank_reference, '') = ?", [$externalBankReference ?? ''])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $batchAttributes
     * @param  list<array<string, mixed>>  $lines
     * @return array{imported: int, conflicts: list<array<string, mixed>>}
     */
    public function appendImportedLines(string $entityId, string $reconciliationId, string $reconciliationAccountId, array $batchAttributes, array $lines): array
    {
        return DB::transaction(function () use ($entityId, $reconciliationId, $reconciliationAccountId, $batchAttributes, $lines): array {
            $batch = ReconciliationImportBatch::query()->create([...$batchAttributes, 'reconciliation_id' => $reconciliationId, 'reconciliation_account_id' => $reconciliationAccountId, 'line_count' => count($lines)]);

            $imported = 0;
            $conflicts = [];
            foreach ($lines as $line) {
                if ($this->duplicateLineExists($reconciliationAccountId, (string) $line['transaction_date'], (string) $line['amount'], (string) $line['currency'], (string) $line['normalized_narration'], $line['external_bank_reference'] ?? null)) {
                    $conflicts[] = ['source_line_identity' => $line['source_line_identity'], 'reason' => 'duplicate_statement_line'];

                    continue;
                }
                ReconciliationStatementLine::query()->create([
                    'reconciliation_id' => $reconciliationId, 'reconciliation_account_id' => $reconciliationAccountId, 'import_batch_id' => $batch->id,
                    'source_line_identity' => $line['source_line_identity'], 'transaction_date' => $line['transaction_date'],
                    'narration' => $line['narration'], 'normalized_narration' => $line['normalized_narration'],
                    'amount' => $line['amount'], 'currency' => $line['currency'], 'external_bank_reference' => $line['external_bank_reference'] ?? null,
                    'status' => 'Unreconciled', 'version' => 1,
                ]);
                $imported++;
            }
            $this->bumpToInProgress($entityId, $reconciliationId);

            return ['imported' => $imported, 'conflicts' => $conflicts];
        });
    }

    /** @param list<array<string, mixed>> $suggestions */
    public function replaceSuggestions(string $lineId, string $newStatus, array $suggestions, int $expectedLineVersion): ?ReconciliationStatementLine
    {
        return DB::transaction(function () use ($lineId, $newStatus, $suggestions, $expectedLineVersion): ?ReconciliationStatementLine {
            $affected = ReconciliationStatementLine::query()->where('id', $lineId)->where('version', $expectedLineVersion)
                ->whereIn('status', ['Unreconciled', 'Suggested', 'Unexplained'])
                ->update(['status' => $newStatus, 'version' => $expectedLineVersion + 1]);
            if ($affected !== 1) {
                return null;
            }
            ReconciliationMatchSuggestion::query()->where('statement_line_id', $lineId)->where('superseded', false)->update(['superseded' => true]);
            foreach ($suggestions as $suggestion) {
                $row = ReconciliationMatchSuggestion::query()->create([
                    'statement_line_id' => $lineId, 'rank' => $suggestion['rank'], 'total_amount' => $suggestion['total_amount'],
                    'currency' => $suggestion['currency'], 'reference_match' => $suggestion['reference_match'], 'superseded' => false,
                ]);
                foreach ($suggestion['allocation_ids'] as $allocationId) {
                    ReconciliationMatchSuggestionAllocation::query()->create(['suggestion_id' => $row->id, 'allocation_id' => $allocationId]);
                }
            }

            return ReconciliationStatementLine::query()->find($lineId);
        });
    }

    /** @param list<string> $allocationIds */
    public function commitMatch(string $lineId, array $allocationIds, int $expectedLineVersion): ?ReconciliationStatementLine
    {
        $affected = ReconciliationStatementLine::query()->where('id', $lineId)->where('version', $expectedLineVersion)
            ->whereIn('status', ['Unreconciled', 'Suggested', 'Matched', 'Unexplained'])
            ->update(['status' => 'Matched', 'matched_allocation_ids' => json_encode($allocationIds, JSON_THROW_ON_ERROR), 'version' => $expectedLineVersion + 1]);

        return $affected === 1 ? ReconciliationStatementLine::query()->find($lineId) : null;
    }

    public function commitConfirm(string $lineId, int $expectedLineVersion): ?ReconciliationStatementLine
    {
        $affected = ReconciliationStatementLine::query()->where('id', $lineId)->where('version', $expectedLineVersion)->where('status', 'Matched')
            ->update(['status' => 'Reconciled', 'version' => $expectedLineVersion + 1]);

        return $affected === 1 ? ReconciliationStatementLine::query()->find($lineId) : null;
    }

    public function commitBankOnlyResolution(string $lineId, string $journalEntryId, int $expectedLineVersion): ?ReconciliationStatementLine
    {
        $affected = ReconciliationStatementLine::query()->where('id', $lineId)->where('version', $expectedLineVersion)
            ->whereIn('status', ['Unreconciled', 'Unexplained'])
            ->update(['status' => 'Reconciled', 'resolved_by_journal_entry_id' => $journalEntryId, 'version' => $expectedLineVersion + 1]);

        return $affected === 1 ? ReconciliationStatementLine::query()->find($lineId) : null;
    }

    public function commitCompletion(string $entityId, string $id, int $expectedVersion, Carbon $watermark, string $contentHash, string $actorId): ?BankReconciliation
    {
        $affected = BankReconciliation::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)
            ->whereIn('state', ['InProgress', 'Reopened'])
            ->update(['state' => 'Completed', 'source_data_watermark' => $watermark, 'content_hash' => $contentHash, 'completed_by' => $actorId, 'completed_at' => Carbon::now('UTC'), 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    public function commitReopen(string $entityId, string $id, int $expectedVersion, string $actorId): ?BankReconciliation
    {
        $affected = BankReconciliation::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)->where('state', 'Completed')
            ->update(['state' => 'Reopened', 'reopened_by' => $actorId, 'reopened_at' => Carbon::now('UTC'), 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    /** @param list<string> $allocationIds */
    public function allocationsConsumedElsewhere(string $reconciliationAccountId, array $allocationIds, ?string $excludingLineId): bool
    {
        $reconciledLines = ReconciliationStatementLine::query()->where('reconciliation_account_id', $reconciliationAccountId)->where('status', 'Reconciled')
            ->when($excludingLineId !== null, fn ($q) => $q->where('id', '!=', $excludingLineId))
            ->pluck('matched_allocation_ids');

        foreach ($reconciledLines as $consumed) {
            if (array_intersect($allocationIds, (array) $consumed) !== []) {
                return true;
            }
        }

        return false;
    }
}
