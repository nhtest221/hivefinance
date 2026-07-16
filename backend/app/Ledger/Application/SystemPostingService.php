<?php

namespace App\Ledger\Application;

use App\Models\Ledger\JournalEntry;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;

final readonly class SystemPostingService
{
    public function __construct(private Outbox $outbox) {}

    /** @param array<int, array<string, mixed>> $lines */
    public function postRevaluation(string $entityId, string $periodId, string $periodRef, string $entryDate, string $sourceId, string $actorId, array $lines): string
    {
        $journal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $periodId, 'period_ref' => $periodRef, 'entry_type' => 'revaluation', 'entry_date' => $entryDate, 'state' => 'posted', 'narration' => 'FX revaluation', 'source_document_id' => $sourceId, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId]);
        foreach ($lines as $index => $line) {
            $journal->lines()->create([...$line, 'entity_id' => $entityId, 'line_no' => $index + 1]);
        }
        $payload = ['entryId' => $journal->id, 'entityId' => $entityId, 'periodRef' => $periodRef, 'entryDate' => $entryDate, 'sourceDocumentId' => $sourceId, 'lines' => collect($lines)->map(fn (array $line): array => ['accountId' => $line['account_id'], 'debit' => ['amount' => $line['debit'], 'currency' => $line['currency']], 'credit' => ['amount' => $line['credit'], 'currency' => $line['currency']], 'sbu' => null])->all()];
        $this->outbox->record('JournalPosted', 'JournalEntry', $journal->id, $payload, $entityId);
        $this->outbox->record('SystemEntryPosted', 'JournalEntry', $journal->id, ['entryId' => $journal->id, 'sourceDocumentId' => $sourceId], $entityId);

        return $journal->id;
    }

    /**
     * @param  array<int, string>  $journalIds
     * @return array<int, string>
     */
    public function reverseRevaluation(string $entityId, string $periodId, string $periodRef, string $entryDate, string $sourceId, string $actorId, array $journalIds): array
    {
        $reversalIds = [];
        foreach ($journalIds as $journalId) {
            $original = JournalEntry::query()->with('lines')->where('entity_id', $entityId)->whereKey($journalId)->where('entry_type', 'revaluation')->firstOrFail();
            $reversal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $periodId, 'period_ref' => $periodRef, 'entry_type' => 'reversal', 'entry_date' => $entryDate, 'state' => 'posted', 'narration' => 'FX revaluation reversal', 'source_document_id' => $sourceId, 'reversal_of_entry_id' => $original->id, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId]);
            foreach ($original->lines as $index => $line) {
                $reversal->lines()->create(['entity_id' => $entityId, 'account_id' => $line->account_id, 'line_no' => $index + 1, 'description' => 'Reversal: '.$line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'currency' => $line->currency, 'fx_amount' => $line->fx_amount, 'fx_currency' => $line->fx_currency, 'rate_record_id' => $line->rate_record_id, 'fx_rate' => $line->fx_rate, 'fx_rate_effective_date' => $line->fx_rate_effective_date]);
            }
            $payload = ['entryId' => $reversal->id, 'entityId' => $entityId, 'periodRef' => $periodRef, 'entryDate' => $entryDate, 'sourceDocumentId' => $sourceId, 'lines' => $reversal->lines->map(fn ($line): array => ['accountId' => $line->account_id, 'debit' => ['amount' => $line->debit, 'currency' => $line->currency], 'credit' => ['amount' => $line->credit, 'currency' => $line->currency], 'sbu' => null])->all()];
            $this->outbox->record('JournalPosted', 'JournalEntry', $reversal->id, $payload, $entityId);
            $this->outbox->record('JournalReversed', 'JournalEntry', $reversal->id, ['entryId' => $reversal->id, 'reversalOfEntryId' => $original->id, 'reversalEntryId' => $reversal->id], $entityId);
            $this->outbox->record('SystemEntryPosted', 'JournalEntry', $reversal->id, ['entryId' => $reversal->id, 'sourceDocumentId' => $sourceId], $entityId);
            $reversalIds[] = $reversal->id;
        }

        return $reversalIds;
    }
}
