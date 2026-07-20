<?php

namespace App\Ledger\Infrastructure;

use App\Ledger\Application\RecognitionPostingResult;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Period\Application\PeriodQuery;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final readonly class EloquentRecognitionPostingService implements RecognitionPostingService
{
    public function __construct(private PeriodQuery $periods, private Outbox $outbox) {}

    public function post(string $entityId, string $sourceDocumentId, string $date, string $entryType, string $actorId, array $lines): RecognitionPostingResult
    {
        $period = $this->periods->postablePeriodForDate($entityId, $date, $entryType);
        if ($period === null) {
            return new RecognitionPostingResult(null, 'period_locked');
        }
        $debits = '0.0000';
        $credits = '0.0000';
        try {
            foreach ($lines as $line) {
                $debits = ExactDecimal::add($debits, (string) ($line['debit'] ?? '0.0000'));
                $credits = ExactDecimal::add($credits, (string) ($line['credit'] ?? '0.0000'));
                if (! LedgerAccount::query()->where('entity_id', $entityId)->whereKey($line['account_id'] ?? null)->where('status', 'active')->exists()) {
                    return new RecognitionPostingResult(null, 'missing_posting_configuration');
                }
            }
        } catch (InvalidArgumentException) {
            return new RecognitionPostingResult(null, 'unbalanced_recognition');
        }
        if ($debits !== $credits || $debits === '0.0000') {
            return new RecognitionPostingResult(null, 'unbalanced_recognition');
        }
        $journal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $period->id, 'period_ref' => $period->period_ref, 'entry_type' => $entryType, 'entry_date' => $date, 'state' => 'posted', 'narration' => 'Document recognition', 'source_document_id' => $sourceDocumentId, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId, 'version' => 1]);
        foreach ($lines as $index => $line) {
            $journal->lines()->create([...$line, 'entity_id' => $entityId, 'line_no' => $index + 1]);
        }
        $payload = ['entryId' => $journal->id, 'entityId' => $entityId, 'periodRef' => $period->period_ref, 'entryDate' => $date, 'sourceDocumentId' => $sourceDocumentId, 'lines' => collect($lines)->map(fn (array $line): array => ['accountId' => $line['account_id'], 'debit' => ['amount' => $line['debit'], 'currency' => $line['currency']], 'credit' => ['amount' => $line['credit'], 'currency' => $line['currency']], 'sbu' => $line['sbu_tag'] ?? null])->all()];
        $this->outbox->record('JournalPosted', 'JournalEntry', $journal->id, $payload, $entityId);
        $this->outbox->record('SystemEntryPosted', 'JournalEntry', $journal->id, ['entryId' => $journal->id, 'sourceDocumentId' => $sourceDocumentId], $entityId);

        return new RecognitionPostingResult($journal->id);
    }
}
