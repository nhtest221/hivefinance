<?php

namespace App\Ledger\Application;

use App\Models\Ledger\JournalEntry;
use App\Period\Application\PeriodQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class JournalReversalExecutor
{
    public function __construct(private PeriodQuery $periods, private AuditLogger $audit, private Outbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function execute(string $entityId, string $journalId, array $data, string $actorId, string $correlationId, ?string $causationId = null): array
    {
        $original = JournalEntry::query()->with('lines')->where('entity_id', $entityId)->find($journalId);
        if (! $original instanceof JournalEntry || $original->state !== 'posted' || $original->entry_type === 'system' || JournalEntry::query()->where('entity_id', $entityId)->where('reversal_of_entry_id', $journalId)->exists()) {
            throw new RuntimeException('The journal is not eligible for reversal.');
        }
        $period = $this->periods->postablePeriodForDate($entityId, (string) $data['entry_date'], 'reversal');
        if ($period === null) {
            throw new RuntimeException('The reversal period is not postable.');
        }
        $reversal = JournalEntry::query()->create(['entity_id' => $entityId, 'period_id' => $period->id, 'period_ref' => $period->period_ref, 'entry_type' => 'reversal', 'entry_date' => $data['entry_date'], 'state' => 'posted', 'narration' => $data['reason'], 'reference' => $original->id, 'reversal_of_entry_id' => $original->id, 'posted_at' => Carbon::now('UTC'), 'posted_by' => $actorId]);
        foreach ($original->lines as $line) {
            $reversal->lines()->create(['entity_id' => $entityId, 'account_id' => $line->account_id, 'line_no' => $line->line_no, 'description' => 'Reversal: '.$line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'currency' => $line->currency, 'fx_amount' => $line->fx_amount, 'fx_currency' => $line->fx_currency, 'rate_record_id' => $line->rate_record_id, 'fx_rate' => $line->fx_rate, 'fx_rate_effective_date' => $line->fx_rate_effective_date, 'sbu_tag' => $line->sbu_tag]);
        }
        $event = ['entryId' => $reversal->id, 'reversalOfEntryId' => $original->id, 'reversalEntryId' => $reversal->id];
        $this->outbox->record('JournalReversed', 'JournalEntry', $reversal->id, $event, $entityId, metadata: array_filter(['correlation_id' => $correlationId, 'causation_id' => $causationId]));
        $this->audit->record('ledger', 'journal_reversed', 'journal_entry', $original->id, $actorId, $entityId, after: $event, correlationId: $correlationId);

        return ['journal' => ['id' => $reversal->id, 'entry_date' => $reversal->entry_date->toDateString(), 'entry_type' => 'reversal', 'state' => 'posted', 'reversal_of_entry_id' => $original->id, 'version' => $reversal->version]];
    }
}
