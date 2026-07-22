<?php

namespace App\Receivables\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\SettlementPostingService;
use App\Models\Receivables\CreditNote;
use App\Models\Settlement\Allocation;
use App\Models\Settlement\CreditConsumption;
use App\Models\Settlement\CreditTranche;
use App\Models\User;
use App\Numbering\Application\SequenceRepository;
use App\Numbering\Domain\SequenceScope;
use App\Settlement\Application\CreditTrancheLedger;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * M4A — Credit Note apply/hold/refund/reverse (API Contracts §§12.3.6-12.3.9). Preserves
 * posted_amount = applied_amount + refunded_amount + held_remaining_amount + undisposed_amount
 * with every value non-negative. Held-source operations go through the Settlement-owned
 * CreditTrancheLedger (AP-001); Apply always creates a real settlement_allocations row
 * (operation `credit_application`) so its Ledger journal is uniformly discoverable via
 * disposition.settlement_allocation_id, whether the value came from undisposed or held.
 */
final readonly class CreditNoteDispositionService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private CreditNoteRepository $notes,
        private OpenReceivableService $receivables,
        private CreditTrancheLedger $tranches,
        private AccountReferenceQuery $accounts,
        private RateReferenceService $rates,
        private RealisedFxCalculator $fx,
        private SettlementPostingService $posting,
        private SequenceRepository $sequences,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string,mixed> $data */
    public function hold(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'hold', $id, $data, $key, $ifMatch, 'receivables.credit_notes.hold');
    }

    /** @param array<string,mixed> $data */
    public function apply(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'apply', $id, $data, $key, $ifMatch, 'receivables.credit_notes.apply');
    }

    /** @param array<string,mixed> $data */
    public function refund(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'refund', $id, $data, $key, $ifMatch, 'receivables.credit_notes.refund');
    }

    /** @param array<string,mixed> $data */
    public function reverse(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        return $this->submit($actor, $entityId, 'reverse', $id, $data, $key, $ifMatch, 'receivables.credit_notes.reverse');
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(string $type, array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        $data = $payload['data'] ?? null;
        if (! is_string($id) || ! is_int($expected) || ! is_string($key) || ! is_array($data)) {
            return $this->commands->error('validation', 'The approved credit note disposition payload is invalid.', 400);
        }

        return $this->execute($type, $context->entityId, $id, $data, $expected, $key, $context->makerId, $context->approverId, $context->causationId);
    }

    /** @param array<string,mixed> $data */
    private function submit(User $actor, string $entityId, string $type, string $id, array $data, ?string $key, ?string $ifMatch, string $capability): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, $capability)) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $note = $this->notes->getById($entityId, $id);
        if ($note === null) {
            return $this->commands->error('not_found', 'The credit note was not found.', 404);
        }
        if ($note->state !== 'posted') {
            return $this->commands->error('invariant_violation', $note->state === 'reversed' ? 'The credit note has been reversed.' : 'The credit note is not posted.', 422, ['rule' => $note->state === 'reversed' ? 'note_reversed' : 'note_not_posted']);
        }
        if ($note->version !== $expected) {
            return $this->conflict($note->version);
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key, 'data' => $data];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('credit_note_'.$type, 1, $payload, $id, $capability, $expected), 'credit_note_'.$type.':'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }

        return $this->execute($type, $entityId, $id, $data, $expected, (string) $key, $actor->id, $actor->id, (string) $key);
    }

    /** @param array<string,mixed> $data */
    private function execute(string $type, string $entityId, string $id, array $data, int $expected, string $key, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        $operation = 'credit_note_'.$type.':'.$id;
        $hash = $this->commands->hash([$id, $data, $expected]);
        if ($replay = $this->commands->replay($makerId, $entityId, $operation, $key, $hash)) {
            return $replay;
        }

        return match ($type) {
            'hold' => $this->executeHold($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId),
            'apply' => $this->executeApply($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId),
            'refund' => $this->executeRefund($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId),
            'reverse' => $this->executeReverse($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId),
            default => $this->commands->error('validation', 'Unsupported CreditNote disposition command.', 400),
        };
    }

    /** @param array<string,mixed> $data */
    private function executeHold(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $note = CreditNote::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($note === null || $note->state !== 'posted' || $note->version !== $expected) {
                return $this->reconflict($entityId, $id, $expected, $note);
            }
            $amount = ExactDecimal::normalize((string) $data['amount']['amount']);
            if (ExactDecimal::compare($amount, $note->undisposed_amount) > 0) {
                return $this->commands->error('invariant_violation', 'The hold amount exceeds the undisposed balance.', 422, ['rule' => 'insufficient_note_remaining']);
            }
            $held = $this->tranches->holdFromNote($entityId, 'customer', $note->customer_id, $note->currency, $amount, $note->source_exchange_rate_reference, $note->id, $note->document_number);
            if (isset($held['error'])) {
                return $this->commands->error($held['error']['code'], $held['error']['message'], $held['error']['status']);
            }
            if ($note->source_rate_record_id !== null) {
                $this->rates->markReferenced($entityId, $note->source_rate_record_id);
            }
            $disposition = $this->notes->appendDisposition($entityId, $id, [
                'held_remaining_amount' => ExactDecimal::add($note->held_remaining_amount, $amount),
                'undisposed_amount' => ExactDecimal::subtract($note->undisposed_amount, $amount),
            ], [
                'operation' => 'hold', 'amount' => $amount, 'functional_amount' => $held['functional_amount'], 'occurred_on' => $data['hold_date'],
                'actor_id' => $executorId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId,
                'credit_tranche_ids' => [$held['tranche']->id],
            ], $expected);
            if ($disposition === null) {
                return $this->conflict((int) $this->notes->getById($entityId, $id)?->version);
            }
            $updated = $this->notes->getById($entityId, $id);
            $body = ['credit_note' => $this->summary($updated), 'credit_sources' => [$this->presentTranche($held['tranche'])]];
            $this->audit->record('receivables', 'credit_note_held', 'credit_note', $id, $executorId, $entityId, after: $body['credit_note'], metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
            $this->outbox->record('CreditNoteHeld', 'CreditNote', $id, ['creditNoteId' => $id, 'transferredAmount' => $this->money($amount, $note->currency), 'resultingState' => $body['credit_note'], 'creditTrancheId' => $held['tranche']->id], $entityId, metadata: ['causation_id' => $causationId]);
            $this->outbox->record('CreditHeld', 'CreditTranche', $held['tranche']->id, ['creditTrancheId' => $held['tranche']->id, 'partyType' => 'customer', 'partyId' => $note->customer_id, 'money' => $this->money($amount, $note->currency), 'sourceNoteId' => $id], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($makerId, $entityId, 'credit_note_hold:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeApply(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $note = CreditNote::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($note === null || $note->state !== 'posted' || $note->version !== $expected) {
                return $this->reconflict($entityId, $id, $expected, $note);
            }
            $source = (string) $data['source'];
            $allocationTotal = '0.0000';
            foreach ($data['allocations'] as $line) {
                $allocationTotal = ExactDecimal::add($allocationTotal, (string) $line['amount']['amount']);
            }
            $creditAccount = config('settlement.accounts.customer_credit');
            $receivableAccount = config('documents.invoice.receivable_account_id');
            if (! is_string($creditAccount) || ! Str::isUuid($creditAccount) || ! is_string($receivableAccount) || ! Str::isUuid($receivableAccount)) {
                return $this->commands->error('missing_posting_configuration', 'Party-credit account mapping is unavailable.', 422);
            }
            if ($source === 'held' && ExactDecimal::compare($allocationTotal, $note->held_remaining_amount) > 0) {
                return $this->commands->error('invariant_violation', 'The application exceeds the held balance.', 422, ['rule' => 'insufficient_held_credit']);
            }
            if ($source !== 'held' && ExactDecimal::compare($allocationTotal, $note->undisposed_amount) > 0) {
                return $this->commands->error('invariant_violation', 'The application exceeds the undisposed balance.', 422, ['rule' => 'insufficient_note_remaining']);
            }
            $sources = [];
            if ($source === 'held') {
                $sources = $this->loadSources($entityId, $data['credit_sources'] ?? []);
                if ($sources instanceof DocumentActionResult) {
                    return $sources;
                }
            }
            // The Allocation must exist before consuming tranches: CreditConsumption.allocation_id
            // is a NOT NULL foreign key to settlement_allocations, so consumption can never
            // precede its owning Allocation row.
            $allocation = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => null, 'operation' => 'credit_application', 'party_type' => 'customer', 'party_id' => $note->customer_id, 'settlement_date' => $data['application_date'], 'bank_account_id' => null, 'currency' => $note->currency, 'gross_amount' => $allocationTotal, 'bank_amount' => '0.0000', 'withholding_amount' => '0.0000', 'allocated_amount' => $allocationTotal, 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '0.0000', 'rate_record_id' => null, 'exchange_rate_reference' => null, 'journal_entry_ids' => [], 'state' => 'building', 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
            $consumedSources = [];
            $fxResults = [];
            $trancheIds = [];
            if ($source === 'held') {
                $result = $this->tranches->consume($entityId, 'customer', $sources, null, $allocation->id, 'application');
                if (isset($result['error'])) {
                    return $this->commands->error($result['error']['code'], $result['error']['message'], $result['error']['status']);
                }
                $consumedSources = $result['consumed'];
                $fxResults = $result['fx_results'];
                $trancheIds = array_map(fn (array $s): string => $s['tranche']->id, $sources);
            }
            $postingLines = [];
            $functionalTotal = '0.0000';
            $applications = [];
            foreach ($data['allocations'] as $line) {
                $documentId = (string) $line['document_id'];
                $before = $this->receivables->getOpenReceivable($entityId, $documentId);
                if ($before === null || $before['party_id'] !== $note->customer_id || $before['currency'] !== $note->currency) {
                    return $this->commands->error('document_party_mismatch', 'The target document is not eligible for this credit note.', 422);
                }
                $amount = ExactDecimal::normalize((string) $line['amount']['amount']);
                $applied = $this->receivables->applySettlement($entityId, $documentId, $amount, (int) $line['expected_version']);
                if (isset($applied['error'])) {
                    return $this->documentError($applied);
                }
                // OpenReceivableService::applySettlement only changes the document's own
                // balance/status — it computes no FX. Source rate is the note's own
                // (both undisposed and held-tranche value carry the note's post-time rate);
                // comparison rate is the target document's own. This intentionally uses one
                // comparison per command (the common single-document application), not a
                // per-tranche/per-document matrix.
                $fxResult = $this->creditFx($note->source_exchange_rate_reference, $applied['before']['exchange_rate_reference'] ?? null, $amount);
                if ($fxResult === false) {
                    return $this->commands->error('missing_rate_reference', 'A required RateRecord is unavailable.', 422, ['rule' => 'missing_rate_reference']);
                }
                $functional = $fxResult['functional'];
                $functionalTotal = ExactDecimal::add($functionalTotal, $functional);
                if ($fxResult['fx'] !== null) {
                    $fxResults[] = $fxResult['fx'];
                    $this->rates->markReferenced($entityId, (string) $fxResult['fx']['source_rate_record_id']);
                    $this->rates->markReferenced($entityId, (string) $fxResult['fx']['comparison_rate_record_id']);
                }
                $postingLines[] = $this->postingLine($receivableAccount, 'Credit note applied to document', '0.0000', (string) $functional, $note->currency, $amount, null);
                $allocation->links()->create(['entity_id' => $entityId, 'document_type' => 'invoice', 'document_id' => $documentId, 'document_number' => $applied['before']['document_number'], 'document_party_id' => $applied['before']['party_id'], 'credit_tranche_id' => $trancheIds[0] ?? null, 'applied_amount' => $amount, 'expected_version' => (int) $line['expected_version'], 'open_balance_before' => $applied['before']['open_balance'], 'open_balance_after' => $applied['after']['open_balance'], 'version_before' => $applied['before']['version'], 'version_after' => $applied['after']['version'], 'status_before' => $applied['before']['status'], 'status_after' => $applied['after']['status'], 'document_rate_record_id' => $applied['before']['rate_record_id'] ?? null]);
                $applications[] = ['document_id' => $documentId, 'amount' => $this->money($amount, $note->currency), 'version_after' => $applied['after']['version']];
                $this->outbox->record('InvoiceStatusChanged', 'Invoice', $documentId, ['documentId' => $documentId, 'status' => $applied['after']['status'], 'openBalance' => $this->money((string) $applied['after']['open_balance'], $note->currency)], $entityId, metadata: ['causation_id' => $causationId]);
            }
            $postingLines[] = $this->postingLine($creditAccount, 'Credit note credit consumed', $functionalTotal, '0.0000', $note->currency, $allocationTotal, null);
            $posted = $this->posting->post($entityId, $allocation->id, (string) $data['application_date'], $executorId, $causationId, $postingLines);
            if ($posted->errorCode !== null || $posted->journalId === null) {
                return $this->postingError($posted->errorCode);
            }
            $allocation->journal_entry_ids = [$posted->journalId];
            $allocation->functional_gross_amount = $functionalTotal;
            $allocation->state = 'posted';
            $allocation->save();
            $noteChanges = $source === 'held'
                ? ['applied_amount' => ExactDecimal::add($note->applied_amount, $allocationTotal), 'held_remaining_amount' => ExactDecimal::subtract($note->held_remaining_amount, $allocationTotal)]
                : ['applied_amount' => ExactDecimal::add($note->applied_amount, $allocationTotal), 'undisposed_amount' => ExactDecimal::subtract($note->undisposed_amount, $allocationTotal)];
            $disposition = $this->notes->appendDisposition($entityId, $id, $noteChanges, [
                'operation' => 'apply', 'amount' => $allocationTotal, 'functional_amount' => $functionalTotal, 'occurred_on' => $data['application_date'],
                'actor_id' => $executorId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId,
                'settlement_allocation_id' => $allocation->id, 'credit_tranche_ids' => $trancheIds === [] ? null : $trancheIds,
            ], $expected);
            if ($disposition === null) {
                return $this->conflict((int) $this->notes->getById($entityId, $id)?->version);
            }
            $updated = $this->notes->getById($entityId, $id);
            $body = ['credit_note' => $this->summary($updated), 'applications' => $applications, 'consumed_credit_sources' => $consumedSources, 'realised_fx_results' => $fxResults];
            $this->audit->record('receivables', 'credit_note_applied', 'credit_note', $id, $executorId, $entityId, after: $body['credit_note'], metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
            $this->outbox->record('CreditNoteApplied', 'CreditNote', $id, ['creditNoteId' => $id, 'source' => $source, 'money' => $this->money($allocationTotal, $note->currency), 'resultingState' => $body['credit_note']], $entityId, metadata: ['causation_id' => $causationId]);
            if ($source === 'held') {
                $this->outbox->record('CreditApplied', 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'partyType' => 'customer', 'partyId' => $note->customer_id, 'money' => $this->money($allocationTotal, $note->currency)], $entityId, 2, ['causation_id' => $causationId]);
            }
            $this->commands->store($makerId, $entityId, 'credit_note_apply:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeRefund(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        $draw = $this->drawNumber($entityId, 'refund', (string) $data['refund_date']);
        if ($draw === null) {
            return $this->commands->error('missing_numbering_configuration', 'Refund numbering configuration is unavailable.', 422);
        }
        try {
            return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId, $draw): DocumentActionResult {
                $note = CreditNote::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
                if ($note === null || $note->state !== 'posted' || $note->version !== $expected) {
                    return $this->reconflict($entityId, $id, $expected, $note);
                }
                $refundAmount = ExactDecimal::normalize((string) $data['refund_amount']['amount']);
                if (ExactDecimal::compare($refundAmount, $note->held_remaining_amount) > 0) {
                    return $this->commands->error('invariant_violation', 'The refund exceeds the held balance.', 422, ['rule' => 'insufficient_held_credit']);
                }
                if (! $this->accounts->isActiveBank($entityId, (string) $data['bank_account_id'])) {
                    return $this->commands->error('missing_posting_configuration', 'The bank account is invalid or inactive.', 422);
                }
                $creditAccount = config('settlement.accounts.customer_credit');
                if (! is_string($creditAccount) || ! Str::isUuid($creditAccount)) {
                    return $this->commands->error('missing_posting_configuration', 'Party-credit account mapping is unavailable.', 422);
                }
                $sources = $this->loadSources($entityId, $data['credit_sources'] ?? []);
                if ($sources instanceof DocumentActionResult) {
                    return $sources;
                }
                $sourceTotal = array_reduce($sources, fn (string $sum, array $s): string => ExactDecimal::add($sum, $s['amount']), '0.0000');
                if ($sourceTotal !== $refundAmount) {
                    return $this->commands->error('invariant_violation', 'Selected credit sources must equal the refund amount.', 422, ['rule' => 'amount_equation_mismatch']);
                }
                // The Allocation must exist before consuming tranches (see executeApply).
                $allocation = Allocation::query()->create(['entity_id' => $entityId, 'allocation_number' => $draw['number'], 'operation' => 'credit_refund', 'party_type' => 'customer', 'party_id' => $note->customer_id, 'settlement_date' => $data['refund_date'], 'bank_account_id' => $data['bank_account_id'], 'currency' => $note->currency, 'gross_amount' => $refundAmount, 'bank_amount' => $refundAmount, 'withholding_amount' => '0.0000', 'allocated_amount' => $refundAmount, 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '0.0000', 'rate_record_id' => null, 'exchange_rate_reference' => null, 'journal_entry_ids' => [], 'state' => 'building', 'version' => 1, 'created_by' => $makerId, 'posted_at' => Carbon::now('UTC')]);
                $result = $this->tranches->consume($entityId, 'customer', $sources, null, $allocation->id, 'refund');
                if (isset($result['error'])) {
                    return $this->commands->error($result['error']['code'], $result['error']['message'], $result['error']['status']);
                }
                $allocation->functional_gross_amount = $result['comparison_total'];
                $postingLines = [
                    $this->postingLine($creditAccount, 'Customer credit refunded', $result['functional_total'], '0.0000', $note->currency, $refundAmount, null),
                    $this->postingLine((string) $data['bank_account_id'], 'Credit refund bank movement', '0.0000', $result['comparison_total'], $note->currency, $refundAmount, null),
                ];
                $posted = $this->posting->post($entityId, $allocation->id, (string) $data['refund_date'], $executorId, $causationId, $postingLines);
                if ($posted->errorCode !== null || $posted->journalId === null) {
                    return $this->postingError($posted->errorCode);
                }
                $allocation->journal_entry_ids = [$posted->journalId];
                $allocation->state = 'posted';
                $allocation->save();
                $trancheIds = array_map(fn (array $s): string => $s['tranche']->id, $sources);
                $disposition = $this->notes->appendDisposition($entityId, $id, [
                    'refunded_amount' => ExactDecimal::add($note->refunded_amount, $refundAmount),
                    'held_remaining_amount' => ExactDecimal::subtract($note->held_remaining_amount, $refundAmount),
                ], [
                    'operation' => 'refund', 'amount' => $refundAmount, 'functional_amount' => $result['functional_total'], 'occurred_on' => $data['refund_date'],
                    'actor_id' => $executorId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId,
                    'settlement_allocation_id' => $allocation->id, 'credit_tranche_ids' => $trancheIds,
                ], $expected);
                if ($disposition === null) {
                    $this->numbers()->recordVoided($draw['prefix'], $draw['scope'], $draw['value']);

                    return $this->conflict((int) $this->notes->getById($entityId, $id)?->version);
                }
                $updated = $this->notes->getById($entityId, $id);
                $body = ['credit_note' => $this->summary($updated), 'allocation' => ['id' => $allocation->id, 'operation' => 'credit_refund', 'state' => 'posted'], 'realised_fx_results' => $result['fx_results']];
                $this->audit->record('receivables', 'credit_note_refunded', 'credit_note', $id, $executorId, $entityId, after: $body['credit_note'], metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
                $this->outbox->record('CreditNoteRefunded', 'CreditNote', $id, ['creditNoteId' => $id, 'money' => $this->money($refundAmount, $note->currency), 'resultingState' => $body['credit_note'], 'settlementAllocationId' => $allocation->id], $entityId, metadata: ['causation_id' => $causationId]);
                $this->outbox->record('CreditRefunded', 'Allocation', $allocation->id, ['allocationId' => $allocation->id, 'partyType' => 'customer', 'partyId' => $note->customer_id, 'money' => $this->money($refundAmount, $note->currency)], $entityId, 2, ['causation_id' => $causationId]);
                $this->commands->store($makerId, $entityId, 'credit_note_refund:'.$id, $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (\Throwable $throwable) {
            $this->numbers()->recordVoided($draw['prefix'], $draw['scope'], $draw['value']);
            throw $throwable;
        }
    }

    /** @param array<string,mixed> $data */
    private function executeReverse(string $entityId, string $id, array $data, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $id, $data, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $note = CreditNote::query()->with('dispositions')->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($note === null || $note->state !== 'posted' || $note->version !== $expected) {
                return $this->reconflict($entityId, $id, $expected, $note);
            }
            $allJournalIds = [...($note->journal_entry_ids ?? [])];
            $restoredDocuments = [];
            $restoredTranches = [];
            foreach ($note->dispositions as $disposition) {
                if ($disposition->settlement_allocation_id !== null) {
                    $allocation = Allocation::query()->where('entity_id', $entityId)->with('links')->find($disposition->settlement_allocation_id);
                    if ($allocation === null) {
                        return $this->commands->error('invariant_violation', 'A disposition allocation could not be found.', 422, ['rule' => 'note_reversal_blocked_by_downstream_activity']);
                    }
                    foreach ($allocation->links as $link) {
                        $current = $this->receivables->getOpenReceivable($entityId, $link->document_id);
                        if ($current === null) {
                            return $this->commands->error('invariant_violation', 'A restored document no longer exists.', 422, ['rule' => 'note_reversal_blocked_by_downstream_activity']);
                        }
                        $restored = $this->receivables->reverseSettlement($entityId, $link->document_id, (string) $link->applied_amount, (int) $current['version']);
                        if (isset($restored['error'])) {
                            return $this->documentError($restored);
                        }
                        $restoredDocuments[] = ['document_id' => $link->document_id, 'open_balance' => $restored['after']['open_balance']];
                        $this->outbox->record('InvoiceStatusChanged', 'Invoice', $link->document_id, ['documentId' => $link->document_id, 'status' => $restored['after']['status'], 'openBalance' => $this->money((string) $restored['after']['open_balance'], $note->currency)], $entityId, metadata: ['causation_id' => $causationId]);
                    }
                    $consumptions = CreditConsumption::query()->where('entity_id', $entityId)->where('allocation_id', $allocation->id)->whereIn('operation', ['application', 'refund'])->orderBy('id')->get();
                    if ($consumptions->isNotEmpty()) {
                        $restoration = $this->tranches->restore($entityId, $consumptions->pluck('id')->all(), $allocation->id);
                        if (isset($restoration['error'])) {
                            return $this->commands->error($restoration['error']['code'], $restoration['error']['message'], $restoration['error']['status']);
                        }
                        $restoredTranches = [...$restoredTranches, ...$restoration['restored']];
                    }
                    $reversedJournal = $this->posting->reverse($entityId, $allocation->id, Carbon::today('UTC')->toDateString(), $executorId, $causationId, $allocation->journal_entry_ids);
                    if ($reversedJournal->errorCode !== null || $reversedJournal->journalId === null) {
                        return $this->postingError($reversedJournal->errorCode);
                    }
                    $allJournalIds[] = $reversedJournal->journalId;
                } elseif ($disposition->operation === 'hold') {
                    foreach (($disposition->credit_tranche_ids ?? []) as $trancheId) {
                        $tranche = CreditTranche::query()->where('entity_id', $entityId)->whereKey($trancheId)->lockForUpdate()->first();
                        if ($tranche === null || $tranche->remaining_amount !== $tranche->original_amount) {
                            return $this->commands->error('invariant_violation', 'Held credit was subsequently consumed and cannot be reversed.', 422, ['rule' => 'note_reversal_blocked_by_downstream_activity']);
                        }
                        CreditTranche::query()->whereKey($tranche->id)->where('version', $tranche->version)->update(['remaining_amount' => '0.0000', 'remaining_functional_amount' => '0.0000', 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]);
                        $restoredTranches[] = ['credit_tranche_id' => $tranche->id, 'released' => true];
                    }
                }
            }
            $postingReversal = $this->posting->reverse($entityId, $note->id, (string) $data['reversal_date'], $executorId, $causationId, $note->journal_entry_ids ?? []);
            if ($postingReversal->errorCode !== null || $postingReversal->journalId === null) {
                return $this->postingError($postingReversal->errorCode);
            }
            $allJournalIds[] = $postingReversal->journalId;
            $impactHash = hash('sha256', json_encode(['documents' => $restoredDocuments, 'tranches' => $restoredTranches, 'journals' => $allJournalIds], JSON_THROW_ON_ERROR));
            $reversal = $this->notes->commitReversal($entityId, $id, [
                'reversal_date' => $data['reversal_date'], 'reason_code' => $data['reason_code'], 'narrative' => $data['narrative'],
                'impact_graph_hash' => $impactHash, 'journal_entry_ids' => $allJournalIds, 'actor_id' => $executorId, 'reversed_at' => Carbon::now('UTC'),
            ], $expected);
            if ($reversal === null) {
                return $this->conflict((int) $this->notes->getById($entityId, $id)?->version);
            }
            $updated = $this->notes->getById($entityId, $id);
            $body = ['credit_note' => $this->summary($updated), 'reversal' => ['id' => $reversal->id, 'original_note_id' => $id, 'reversal_date' => $reversal->reversal_date->toDateString()], 'journal_entry_ids' => $allJournalIds];
            $this->audit->record('receivables', 'credit_note_reversed', 'credit_note', $id, $executorId, $entityId, before: ['state' => 'posted', 'version' => $expected], after: $body['credit_note'], metadata: ['maker_id' => $makerId, 'reason_code' => $data['reason_code']], correlationId: $this->correlation());
            $this->outbox->record('CreditNoteReversed', 'CreditNote', $id, ['creditNoteId' => $id, 'reversalId' => $reversal->id, 'impactGraphHash' => $impactHash, 'resultingState' => $body['credit_note'], 'journalEntryIds' => $allJournalIds], $entityId, metadata: ['causation_id' => $causationId]);
            $this->commands->store($makerId, $entityId, 'credit_note_reverse:'.$id, $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param list<array<string,mixed>> $requested
     * @return list<array{tranche:CreditTranche,amount:string,expected_version:int}>|DocumentActionResult
     */
    private function loadSources(string $entityId, array $requested): array|DocumentActionResult
    {
        if ($requested === []) {
            return $this->commands->error('validation', 'credit_sources is required for held-source operations.', 400);
        }
        $sources = [];
        foreach ($requested as $source) {
            $tranche = CreditTranche::query()->where('entity_id', $entityId)->find($source['credit_tranche_id']);
            if ($tranche === null) {
                return $this->commands->error('credit_tranche_not_found', 'A selected credit source was not found.', 404);
            }
            $amount = ExactDecimal::normalize((string) $source['amount']['amount']);
            if ($tranche->version !== (int) $source['expected_version']) {
                return $this->commands->error('concurrency_conflict', 'Credit tranche version is stale.', 409, ['rule' => 'credit_tranche_concurrency_conflict', 'required_version' => $tranche->version]);
            }
            if (ExactDecimal::compare($amount, $tranche->remaining_amount) > 0) {
                return $this->commands->error('invariant_violation', 'The selected amount exceeds the tranche remainder.', 422, ['rule' => 'insufficient_held_credit']);
            }
            $sources[] = ['tranche' => $tranche, 'amount' => $amount, 'expected_version' => (int) $source['expected_version']];
        }

        return $sources;
    }

    /** @return array{number:string,prefix:string,scope:SequenceScope,value:int}|null */
    private function drawNumber(string $entityId, string $kind, string $date): ?array
    {
        $prefix = config('settlement.'.$kind.'.number_prefix');
        $format = config('settlement.'.$kind.'.number_format');
        $fiscalYear = substr($date, 0, 4);
        if (! is_string($prefix) || $prefix === '' || ! is_string($format) || $format === '') {
            return null;
        }
        $scope = new SequenceScope($entityId, $fiscalYear);
        $sequence = $this->sequences->drawNext($prefix, $scope);
        $number = str_replace(['{prefix}', '{fiscal_year}', '{sequence}'], [$prefix, $fiscalYear, (string) $sequence->currentValue], $format);

        return ['number' => $number, 'prefix' => $prefix, 'scope' => $scope, 'value' => $sequence->currentValue];
    }

    private function numbers(): SequenceRepository
    {
        return $this->sequences;
    }

    /** @param array<string,mixed> $applied */
    private function documentError(array $applied): DocumentActionResult
    {
        return match ($applied['error'] ?? null) {
            'not_found' => $this->commands->error('not_found', 'The target document was not found.', 404),
            'concurrency_conflict' => new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The target document version has changed.', 'details' => [], 'required_version' => $applied['required_version'] ?? null], 409),
            default => $this->commands->error('invariant_violation', 'The target document cannot accept this disposition.', 422, ['rule' => (string) ($applied['error'] ?? 'invalid_document_state')]),
        };
    }

    private function postingError(?string $code): DocumentActionResult
    {
        $code ??= 'unbalanced_note_posting';

        return $this->commands->error($code, $code === 'period_locked' ? 'The accounting period is not postable.' : 'Credit note posting could not be balanced.', $code === 'period_locked' ? 423 : 422);
    }

    /** @param array<string,mixed>|null $reference
     * @return array<string,mixed>
     */
    private function postingLine(string $account, string $description, string $debit, string $credit, string $documentCurrency, string $foreignAmount, ?array $reference): array
    {
        return ['account_id' => $account, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'currency' => $reference['quote_currency'] ?? $documentCurrency, 'fx_amount' => $reference ? $foreignAmount : null, 'fx_currency' => $reference ? $documentCurrency : null, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'fx_rate' => $reference['rate'] ?? null, 'fx_rate_effective_date' => $reference['effective_date'] ?? null];
    }

    /**
     * @param  array<string,mixed>|null  $sourceReference
     * @param  array<string,mixed>|null  $comparisonReference
     * @return array{functional:string,fx:array<string,mixed>|null}|false
     */
    private function creditFx(?array $sourceReference, ?array $comparisonReference, string $amount): array|false
    {
        if ($sourceReference === null && $comparisonReference === null) {
            return ['functional' => ExactDecimal::normalize($amount), 'fx' => null];
        }
        if ($sourceReference === null || $comparisonReference === null) {
            return false;
        }
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($scale) || ! is_string($mode)) {
            return false;
        }
        $calculated = $this->fx->calculateCredit($amount, (string) $sourceReference['rate'], (string) $comparisonReference['rate'], 'customer', (int) $scale, $mode);

        return ['functional' => $calculated['comparison_functional'], 'fx' => $calculated['classification'] === 'none' ? null : [...$calculated, 'source_rate_record_id' => $sourceReference['rate_record_id'] ?? null, 'comparison_rate_record_id' => $comparisonReference['rate_record_id'] ?? null]];
    }

    /** @return array<string,mixed> */
    private function presentTranche(CreditTranche $tranche): array
    {
        return ['credit_tranche_id' => $tranche->id, 'amount' => $this->money($tranche->remaining_amount, $tranche->currency), 'functional_amount' => $this->money($tranche->remaining_functional_amount, $tranche->currency), 'source_rate_record_id' => $tranche->source_rate_record_id, 'version' => $tranche->version];
    }

    /** @return array<string,mixed> */
    private function summary(CreditNote $note): array
    {
        return ['id' => $note->id, 'document_number' => $note->document_number, 'posted_amount' => $this->money($note->posted_amount, $note->currency), 'applied_amount' => $this->money($note->applied_amount, $note->currency), 'refunded_amount' => $this->money($note->refunded_amount, $note->currency), 'held_remaining_amount' => $this->money($note->held_remaining_amount, $note->currency), 'undisposed_amount' => $this->money($note->undisposed_amount, $note->currency), 'state' => $note->state, 'version' => $note->version];
    }

    /** @return array<string,mixed> */
    private function money(string $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }

    private function reconflict(string $entityId, string $id, int $expected, ?CreditNote $note): DocumentActionResult
    {
        if ($note === null) {
            return $this->commands->error('not_found', 'The credit note was not found.', 404);
        }
        if ($note->state !== 'posted') {
            return $this->commands->error('invariant_violation', $note->state === 'reversed' ? 'The credit note has been reversed.' : 'The credit note is not posted.', 422, ['rule' => $note->state === 'reversed' ? 'note_reversed' : 'note_not_posted']);
        }

        return $this->conflict($note->version);
    }

    private function conflict(int $version): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The credit note version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
