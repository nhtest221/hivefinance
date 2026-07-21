<?php

namespace App\Ledger\Infrastructure;

use App\Ledger\Application\RecognitionPostingResult;
use App\Ledger\Application\SettlementPostingService;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Period\Application\PeriodQuery;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class EloquentSettlementPostingService implements SettlementPostingService
{
    public function __construct(private PeriodQuery $periods, private Outbox $outbox) {}

    public function post(string $entityId, string $allocationId, string $date, string $actorId, string $causationId, array $lines): RecognitionPostingResult
    {
        $period = $this->periods->postablePeriodForDate($entityId, $date, 'settlement');
        if ($period === null) {
            return new RecognitionPostingResult(null, 'period_locked');
        }
        if ($validation = $this->validationError($entityId, $lines)) {
            return new RecognitionPostingResult(null, $validation);
        }
        $journal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $period->id, 'period_ref' => $period->period_ref, 'entry_type' => 'settlement', 'entry_date' => $date, 'state' => 'posted', 'narration' => 'Settlement allocation', 'source_document_id' => $allocationId, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId, 'version' => 1]);
        foreach ($lines as $index => $line) {
            $journal->lines()->create([...$line, 'entity_id' => $entityId, 'line_no' => $index + 1]);
        }
        $this->events($journal, $lines, $entityId, $allocationId, $date, $causationId);

        return new RecognitionPostingResult($journal->id);
    }

    public function reverse(string $entityId, string $reversalAllocationId, string $date, string $actorId, string $causationId, array $journalIds): RecognitionPostingResult
    {
        $period = $this->periods->postablePeriodForDate($entityId, $date, 'reversal');
        if ($period === null) {
            return new RecognitionPostingResult(null, 'period_locked');
        }
        $reversalIds = [];
        foreach ($journalIds as $journalId) {
            $original = JournalEntry::query()->with('lines')->where('entity_id', $entityId)->whereKey($journalId)->where('state', 'posted')->first();
            if (! $original) {
                return new RecognitionPostingResult(null, 'missing_posting_configuration');
            }
            $lines = $original->lines->map(fn ($line): array => ['account_id' => $line->account_id, 'description' => 'Reversal: '.$line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'currency' => $line->currency, 'fx_amount' => $line->fx_amount, 'fx_currency' => $line->fx_currency, 'rate_record_id' => $line->rate_record_id, 'fx_rate' => $line->fx_rate, 'fx_rate_effective_date' => $line->fx_rate_effective_date, 'sbu_tag' => $line->sbu_tag])->all();
            if ($validation = $this->validationError($entityId, $lines)) {
                return new RecognitionPostingResult(null, $validation === 'missing_posting_configuration' ? $validation : 'unbalanced_reversal');
            }
            $journal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $period->id, 'period_ref' => $period->period_ref, 'entry_type' => 'reversal', 'entry_date' => $date, 'state' => 'posted', 'narration' => 'Settlement reversal', 'source_document_id' => $reversalAllocationId, 'reversal_of_entry_id' => $original->id, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId, 'version' => 1]);
            foreach ($lines as $index => $line) {
                $journal->lines()->create([...$line, 'entity_id' => $entityId, 'line_no' => $index + 1]);
            }
            $this->events($journal, $lines, $entityId, $reversalAllocationId, $date, $causationId);
            $this->outbox->record('JournalReversed', 'JournalEntry', $journal->id, ['entryId' => $journal->id, 'reversalOfEntryId' => $original->id, 'reversalEntryId' => $journal->id], $entityId, metadata: ['causation_id' => $causationId]);
            $reversalIds[] = $journal->id;
        }

        return new RecognitionPostingResult($reversalIds === [] ? null : implode(',', $reversalIds), $reversalIds === [] ? 'missing_posting_configuration' : null);
    }

    /** @param list<array<string,mixed>> $lines */
    private function validationError(string $entityId, array $lines): ?string
    {
        $debits = '0.0000';
        $credits = '0.0000';
        try {
            foreach ($lines as $line) {
                if (! LedgerAccount::query()->where('entity_id', $entityId)->whereKey($line['account_id'] ?? null)->where('status', 'active')->exists()) {
                    return 'missing_posting_configuration';
                }
                $debits = ExactDecimal::add($debits, (string) ($line['debit'] ?? '0.0000'));
                $credits = ExactDecimal::add($credits, (string) ($line['credit'] ?? '0.0000'));
            }
        } catch (InvalidArgumentException) {
            return 'unbalanced_settlement';
        }

        return $debits === $credits && $debits !== '0.0000' ? null : 'unbalanced_settlement';
    }

    /** @param list<array<string,mixed>> $lines */
    private function events(JournalEntry $journal, array $lines, string $entityId, string $allocationId, string $date, string $causationId): void
    {
        $payload = ['entryId' => $journal->id, 'entityId' => $entityId, 'periodRef' => $journal->period_ref, 'entryDate' => $date, 'sourceDocumentId' => $allocationId, 'lines' => collect($lines)->map(fn (array $line): array => ['accountId' => $line['account_id'], 'debit' => ['amount' => $line['debit'], 'currency' => $line['currency']], 'credit' => ['amount' => $line['credit'], 'currency' => $line['currency']], 'sbu' => $line['sbu_tag'] ?? null])->all()];
        $this->outbox->record('JournalPosted', 'JournalEntry', $journal->id, $payload, $entityId, metadata: ['causation_id' => $causationId]);
        $this->outbox->record('SystemEntryPosted', 'JournalEntry', $journal->id, ['entryId' => $journal->id, 'sourceDocumentId' => $allocationId], $entityId, metadata: ['causation_id' => $causationId]);
    }
}
