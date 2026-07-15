<?php

namespace App\Ledger\Application;

use App\Ledger\Domain\DecimalAmount;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class JournalService
{
    public function __construct(
        private LedgerAuthorizationService $authorization,
        private PeriodService $periods,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(User $actor, string $entityId, array $data): LedgerActionResult
    {
        $permission = 'ledger.journals.create';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $period = $this->periods->findForDate($entityId, (string) $data['entry_date']);
        if ($period === null) {
            return $this->periodLocked('No accounting period exists for the journal date.');
        }

        $validation = $this->validateLines($entityId, $data['lines']);
        if ($validation instanceof LedgerActionResult) {
            return $validation;
        }

        $journal = DB::transaction(function () use ($actor, $entityId, $data, $period): JournalEntry {
            $journal = JournalEntry::query()->create([
                'entity_id' => $entityId,
                'period_id' => $period->id,
                'period_ref' => $period->period_ref,
                'entry_type' => $data['entry_type'] ?? 'manual',
                'entry_date' => $data['entry_date'],
                'state' => 'draft',
                'narration' => $data['narration'] ?? null,
                'reference' => $data['reference'] ?? null,
            ]);

            foreach ($data['lines'] as $index => $line) {
                $journal->lines()->create([
                    'entity_id' => $entityId,
                    'account_id' => $line['account_id'],
                    'line_no' => $index + 1,
                    'description' => $line['description'] ?? null,
                    'debit' => DecimalAmount::fromString($line['debit'] ?? '0')->toString(),
                    'credit' => DecimalAmount::fromString($line['credit'] ?? '0')->toString(),
                    'currency' => $line['currency'],
                ]);
            }

            $this->audit->record('ledger', 'journal_draft_created', 'journal_entry', $journal->id, $actor->id, $entityId, after: [
                'entry_date' => $data['entry_date'],
                'line_count' => count($data['lines']),
            ]);

            return $journal->load('lines');
        });

        return new LedgerActionResult(['journal' => $this->present($journal)], 201);
    }

    public function post(User $actor, string $entityId, string $journalId, ?string $idempotencyKey): LedgerActionResult
    {
        $permission = 'ledger.journals.post';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $this->validation('Idempotency-Key header is required.', ['header' => 'Idempotency-Key']);
        }

        $existingEvent = $this->findOutboxReplay('JournalPosted', $journalId, $idempotencyKey);
        if ($existingEvent !== null) {
            return new LedgerActionResult($existingEvent);
        }

        $journal = JournalEntry::query()->with('lines')->where('entity_id', $entityId)->find($journalId);
        if (! $journal instanceof JournalEntry) {
            return $this->notFound();
        }

        if ($journal->state !== 'draft') {
            return new LedgerActionResult([
                'error_code' => 'invariant_violation',
                'message' => 'Only draft journals can be posted.',
                'details' => ['state' => $journal->state],
            ], 422);
        }

        $period = $this->periods->postablePeriodForDate($entityId, $journal->entry_date->toDateString(), $journal->entry_type);
        if ($period === null) {
            return $this->periodLocked('The journal date is not postable for the period state.');
        }

        $validation = $this->validateLines($entityId, $journal->lines->map(fn ($line): array => [
            'account_id' => $line->account_id,
            'debit' => $line->debit,
            'credit' => $line->credit,
            'currency' => $line->currency,
        ])->all());
        if ($validation instanceof LedgerActionResult) {
            return $validation;
        }

        $journal = DB::transaction(function () use ($actor, $entityId, $journal, $idempotencyKey): JournalEntry {
            $journal->state = 'posted';
            $journal->posted_at = Carbon::now('UTC');
            $journal->posted_by = $actor->id;
            $journal->version++;
            $journal->save();

            $payload = $this->eventPayload($journal->load('lines'));
            $this->outbox->record('JournalPosted', 'JournalEntry', $journal->id, $payload, $entityId, metadata: [
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit->record('ledger', 'journal_posted', 'journal_entry', $journal->id, $actor->id, $entityId, after: $payload);

            return $journal->load('lines');
        });

        return new LedgerActionResult(['journal' => $this->present($journal)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function reverse(User $actor, string $entityId, string $journalId, array $data, ?string $idempotencyKey): LedgerActionResult
    {
        $permission = 'ledger.journals.reverse';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $this->validation('Idempotency-Key header is required.', ['header' => 'Idempotency-Key']);
        }

        $existingEvent = $this->findOutboxReplay('JournalReversed', $journalId, $idempotencyKey);
        if ($existingEvent !== null) {
            return new LedgerActionResult($existingEvent, 201);
        }

        $original = JournalEntry::query()->with('lines')->where('entity_id', $entityId)->find($journalId);
        if (! $original instanceof JournalEntry) {
            return $this->notFound();
        }

        if ($original->state !== 'posted') {
            return new LedgerActionResult([
                'error_code' => 'invariant_violation',
                'message' => 'Only posted journals can be reversed.',
                'details' => ['state' => $original->state],
            ], 422);
        }

        $reversalDate = (string) $data['entry_date'];
        $period = $this->periods->postablePeriodForDate($entityId, $reversalDate, 'reversal');
        if ($period === null) {
            return $this->periodLocked('The reversal date is not postable for the period state.');
        }

        $reversal = DB::transaction(function () use ($actor, $entityId, $original, $period, $reversalDate, $data, $idempotencyKey): JournalEntry {
            $reversal = JournalEntry::query()->create([
                'entity_id' => $entityId,
                'period_id' => $period->id,
                'period_ref' => $period->period_ref,
                'entry_type' => 'reversal',
                'entry_date' => $reversalDate,
                'state' => 'posted',
                'narration' => $data['reason'] ?? 'Reversal',
                'reference' => $original->id,
                'reversal_of_entry_id' => $original->id,
                'posted_at' => Carbon::now('UTC'),
                'posted_by' => $actor->id,
            ]);

            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'entity_id' => $entityId,
                    'account_id' => $line->account_id,
                    'line_no' => $line->line_no,
                    'description' => 'Reversal: '.$line->description,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'currency' => $line->currency,
                    'fx_amount' => $line->fx_amount,
                    'rate_record_id' => $line->rate_record_id,
                    'sbu_tag' => $line->sbu_tag,
                ]);
            }

            $payload = [
                'entryId' => $original->id,
                'reversalOfEntryId' => $original->id,
                'reversalEntryId' => $reversal->id,
            ];
            $this->outbox->record('JournalReversed', 'JournalEntry', $original->id, $payload, $entityId, metadata: [
                'idempotency_key' => $idempotencyKey,
            ]);
            $this->audit->record('ledger', 'journal_reversed', 'journal_entry', $original->id, $actor->id, $entityId, after: $payload);

            return $reversal->load('lines');
        });

        return new LedgerActionResult(['journal' => $this->present($reversal)], 201);
    }

    public function list(User $actor, string $entityId, ?string $accountId, ?string $periodRef, ?string $state): LedgerActionResult
    {
        $permission = 'ledger.journals.read';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $query = JournalEntry::query()
            ->with('lines')
            ->where('entity_id', $entityId)
            ->when($periodRef !== null, fn ($query) => $query->where('period_ref', $periodRef))
            ->when($state !== null, fn ($query) => $query->where('state', $state))
            ->when($accountId !== null, fn ($query) => $query->whereHas('lines', fn ($lines) => $lines->where('account_id', $accountId)))
            ->orderByDesc('entry_date');

        /** @var Collection<int, JournalEntry> $journals */
        $journals = $query->get();

        return new LedgerActionResult([
            'journals' => $journals->map(fn (JournalEntry $journal): array => $this->present($journal))->all(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateLines(string $entityId, array $lines): ?LedgerActionResult
    {
        if (count($lines) < 2) {
            return $this->validation('A journal requires at least two lines.', ['rule' => 'minimum_two_lines']);
        }

        $debits = DecimalAmount::zero();
        $credits = DecimalAmount::zero();
        foreach ($lines as $line) {
            $accountExists = LedgerAccount::query()
                ->where('entity_id', $entityId)
                ->where('id', $line['account_id'] ?? null)
                ->where('status', 'active')
                ->exists();

            if (! $accountExists) {
                return $this->validation('Each journal line must reference an active account in the entity.', ['account_id' => $line['account_id'] ?? null]);
            }

            $debit = DecimalAmount::fromString($line['debit'] ?? '0');
            $credit = DecimalAmount::fromString($line['credit'] ?? '0');

            if ($debit->isPositive() && $credit->isPositive()) {
                return $this->validation('A journal line cannot carry both debit and credit.', ['rule' => 'single_sided_line']);
            }

            if ($debit->isZero() && $credit->isZero()) {
                return $this->validation('A journal line must carry either debit or credit.', ['rule' => 'non_zero_line']);
            }

            $debits = $debits->add($debit);
            $credits = $credits->add($credit);
        }

        if (! $debits->equals($credits)) {
            return $this->validation('Journal debits must equal credits.', [
                'rule' => 'balanced_journal',
                'debits' => $debits->toString(),
                'credits' => $credits->toString(),
            ]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOutboxReplay(string $eventType, string $aggregateId, string $idempotencyKey): ?array
    {
        $event = OutboxMessage::query()
            ->where('event_type', $eventType)
            ->where('aggregate_id', $aggregateId)
            ->where('metadata->idempotency_key', $idempotencyKey)
            ->first();

        if ($event === null) {
            return null;
        }

        return ['event' => $event->payload, 'idempotent_replay' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(JournalEntry $journal): array
    {
        return [
            'entryId' => $journal->id,
            'entityId' => $journal->entity_id,
            'periodRef' => $journal->period_ref,
            'entryDate' => $journal->entry_date->toDateString(),
            'lines' => $journal->lines->map(fn ($line): array => [
                'accountId' => $line->account_id,
                'debit' => ['amount' => $line->debit, 'currency' => $line->currency],
                'credit' => ['amount' => $line->credit, 'currency' => $line->currency],
                'sbu' => $line->sbu_tag,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(JournalEntry $journal): array
    {
        return [
            'id' => $journal->id,
            'entity_id' => $journal->entity_id,
            'period_ref' => $journal->period_ref,
            'entry_type' => $journal->entry_type,
            'entry_date' => $journal->entry_date->toDateString(),
            'state' => $journal->state,
            'narration' => $journal->narration,
            'reference' => $journal->reference,
            'reversal_of_entry_id' => $journal->reversal_of_entry_id,
            'posted_at' => $journal->posted_at?->toISOString(),
            'posted_by' => $journal->posted_by,
            'version' => $journal->version,
            'lines' => $journal->lines->map(fn ($line): array => [
                'id' => $line->id,
                'account_id' => $line->account_id,
                'line_no' => $line->line_no,
                'description' => $line->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'currency' => $line->currency,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function validation(string $message, array $details): LedgerActionResult
    {
        return new LedgerActionResult([
            'error_code' => 'invariant_violation',
            'message' => $message,
            'details' => $details,
        ], 422);
    }

    private function periodLocked(string $message): LedgerActionResult
    {
        return new LedgerActionResult([
            'error_code' => 'period_locked',
            'message' => $message,
            'details' => [],
        ], 423);
    }

    private function notFound(): LedgerActionResult
    {
        return new LedgerActionResult([
            'error_code' => 'not_found',
            'message' => 'The journal entry was not found.',
            'details' => [],
        ], 404);
    }
}
