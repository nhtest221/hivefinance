<?php

namespace App\Reconciliation\Application;

use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Reconciliation\BankReconciliation;
use App\Models\Reconciliation\ReconciliationStatementLine;
use App\Models\Settlement\Allocation;
use App\Models\User;
use App\Settlement\Application\AllocationQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * M6 — Reconciliation: BankReconciliation lifecycle, statement import, matching, bank-only
 * entries, and completion/reopen (API Contracts §14; Aggregate Design §13; M6-GOV-001).
 */
final readonly class ReconciliationService
{
    /** Deliberate, stated bound on match-suggestion combination search — not a silent cap. */
    private const int MAX_COMBINATION_SIZE = 3;

    public function __construct(
        private DocumentCommandSupport $commands,
        private BankReconciliationRepository $reconciliations,
        private ReconciliationAccountRepository $accounts,
        private AllocationQuery $allocations,
        private AccountReferenceQuery $ledgerAccounts,
        private RecognitionPostingService $posting,
        private ApprovalLifecycleService $approvals,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function open(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.open')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $op = 'POST /v1/reconciliations';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $accountId = $data['reconciliation_account_id'] ?? null;
        if (! is_string($accountId) || $this->accounts->getById($entityId, $accountId) === null) {
            return $this->commands->error('not_found', 'The reconciliation account was not found.', 404);
        }
        $periodRef = $data['period_ref'] ?? null;
        $opening = $data['opening_balance'] ?? null;
        $closing = $data['closing_balance'] ?? null;
        if (! is_string($periodRef) || ! is_string($opening) || ! is_string($closing)) {
            return $this->commands->error('validation', 'period_ref, opening_balance, and closing_balance are required.', 400);
        }

        $reconciliation = $this->reconciliations->addDraft([
            'entity_id' => $entityId, 'reconciliation_account_id' => $accountId, 'period_ref' => $periodRef,
            'opening_balance' => $opening, 'closing_balance' => $closing, 'opened_by' => $actor->id,
        ]);
        $body = ['reconciliation' => $this->summary($reconciliation)];
        $this->audit->record('reconciliation', 'reconciliation_opened', 'bank_reconciliation', $reconciliation->id, $actor->id, $entityId, after: $body['reconciliation'], correlationId: $this->correlation());
        $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

        return new DocumentActionResult($body, 201);
    }

    /** @param array<string, mixed> $data */
    public function importStatement(User $actor, string $entityId, string $id, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.import')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $op = 'POST /v1/reconciliations/'.$id.'/import';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null) {
            return $this->notFound();
        }
        if (! in_array($reconciliation->state, ['Draft', 'InProgress', 'Reopened'], true)) {
            return $this->commands->error('reconciliation_not_editable', 'This reconciliation batch does not accept imports in its current state.', 422);
        }
        $account = $this->accounts->getById($entityId, $reconciliation->reconciliation_account_id);
        if ($account === null) {
            return $this->notFound();
        }
        $fileHash = $data['file_hash'] ?? null;
        $lines = $data['lines'] ?? null;
        if (! is_string($fileHash) || $fileHash === '' || ! is_array($lines)) {
            return $this->commands->error('validation', 'file_hash and lines are required.', 400);
        }
        if ($this->reconciliations->importFileExists($account->id, $fileHash)) {
            return $this->commands->error('duplicate_import_file', 'This exact statement file has already been imported for this account.', 422);
        }

        $prepared = [];
        foreach ($lines as $line) {
            if (! is_array($line) || ! isset($line['source_line_identity'], $line['transaction_date'], $line['narration'], $line['amount']['amount'], $line['amount']['currency'])) {
                return $this->commands->error('validation', 'Each line requires source_line_identity, transaction_date, narration, and amount.', 400);
            }
            if ((string) $line['amount']['currency'] !== $account->currency) {
                return $this->commands->error('currency_mismatch', 'A line currency differs from the reconciliation account currency.', 422);
            }
            $prepared[] = [
                'source_line_identity' => (string) $line['source_line_identity'], 'transaction_date' => (string) $line['transaction_date'],
                'narration' => (string) $line['narration'], 'normalized_narration' => $this->normalize((string) $line['narration']),
                'amount' => ExactDecimal::normalize((string) $line['amount']['amount']), 'currency' => (string) $line['amount']['currency'],
                'external_bank_reference' => isset($line['external_bank_reference']) ? (string) $line['external_bank_reference'] : null,
            ];
        }

        $columnMapping = isset($data['column_mapping']) && is_array($data['column_mapping']) ? $data['column_mapping'] : null;
        $result = $this->reconciliations->appendImportedLines($entityId, $id, $account->id, [
            'file_hash' => $fileHash, 'column_mapping' => $columnMapping, 'imported_by' => $actor->id, 'imported_at' => Carbon::now('UTC'),
        ], $prepared);

        if ($columnMapping !== null) {
            $this->accounts->update($entityId, $account->id, ['column_mapping' => $columnMapping], $account->version);
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        $body = ['reconciliation' => $this->summary($reconciliation), 'imported' => $result['imported'], 'conflicts' => $result['conflicts']];
        $this->audit->record('reconciliation', 'statement_imported', 'bank_reconciliation', $id, $actor->id, $entityId, after: $body, correlationId: $this->correlation());
        $this->outbox->record('StatementImported', 'BankReconciliation', $id, ['reconciliationId' => $id, 'reconciliationAccountId' => $account->id, 'fileHash' => $fileHash, 'importedCount' => $result['imported'], 'conflictCount' => count($result['conflicts'])], $entityId);
        $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

        return new DocumentActionResult($body, 201);
    }

    public function generateMatchSuggestions(User $actor, string $entityId, string $id, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.generate_suggestions')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $op = 'POST /v1/reconciliations/'.$id.'/match-suggestions';
        $hash = $this->commands->hash([]);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null) {
            return $this->notFound();
        }
        if (! in_array($reconciliation->state, ['Draft', 'InProgress', 'Reopened'], true)) {
            return $this->commands->error('reconciliation_not_editable', 'This reconciliation batch does not accept new suggestions in its current state.', 422);
        }
        $account = $this->accounts->getById($entityId, $reconciliation->reconciliation_account_id);
        $targets = $this->reconciliations->linesByStatus($id, ['Unreconciled', 'Suggested', 'Unexplained']);
        if ($account === null || $targets->isEmpty()) {
            $body = ['suggested' => 0, 'unexplained' => 0, 'lines' => []];
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        }

        $from = $targets->min('transaction_date')->copy()->subDays(3)->toDateString();
        $to = $targets->max('transaction_date')->copy()->addDays(3)->toDateString();
        $consumed = $this->consumedAllocationIds($id);
        $candidates = $this->allocations->candidatesForBankAccount($entityId, $account->ledger_account_id, $account->currency, $from, $to)
            ->reject(fn (Allocation $a): bool => in_array($a->id, $consumed, true))->values();

        [$lineGroups, $groupedLineIds] = $this->matchLinesToGroups($targets, $candidates);

        $suggested = 0;
        $unexplained = 0;
        $summary = [];
        foreach ($targets as $line) {
            $groups = $lineGroups->get($line->id, collect());
            $newStatus = $groups->isEmpty() ? 'Unexplained' : 'Suggested';
            $updated = $this->reconciliations->replaceSuggestions($line->id, $newStatus, $groups->all(), $line->version);
            $newStatus === 'Suggested' ? $suggested++ : $unexplained++;
            $summary[] = ['line_id' => $line->id, 'status' => $newStatus, 'version' => $updated->version, 'suggestions' => $groups->all()];
        }
        unset($groupedLineIds);

        $body = ['suggested' => $suggested, 'unexplained' => $unexplained, 'lines' => $summary];
        $this->audit->record('reconciliation', 'match_suggestions_generated', 'bank_reconciliation', $id, $actor->id, $entityId, after: ['suggested' => $suggested, 'unexplained' => $unexplained], correlationId: $this->correlation());
        $this->outbox->record('MatchSuggestionsGenerated', 'BankReconciliation', $id, ['reconciliationId' => $id, 'suggestedCount' => $suggested, 'unexplainedCount' => $unexplained], $entityId);
        $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

        return new DocumentActionResult($body);
    }

    /** @param array<string, mixed> $data */
    public function matchLine(User $actor, string $entityId, string $id, string $lineId, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.match')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null || ! in_array($reconciliation->state, ['Draft', 'InProgress', 'Reopened'], true)) {
            return $reconciliation === null ? $this->notFound() : $this->commands->error('reconciliation_not_editable', 'This reconciliation batch is not editable.', 422);
        }
        $line = $this->reconciliations->getLine($entityId, $id, $lineId);
        if ($line === null) {
            return $this->notFound();
        }
        if (! in_array($line->status, ['Unreconciled', 'Suggested', 'Matched', 'Unexplained'], true)) {
            return $this->commands->error('line_not_matchable', 'This line cannot be matched in its current state.', 422);
        }
        $allocationIds = $data['allocation_ids'] ?? null;
        if (! is_array($allocationIds) || $allocationIds === []) {
            return $this->commands->error('validation', 'allocation_ids is required.', 400);
        }
        $allocationIds = array_map(strval(...), $allocationIds);
        $lineIds = array_unique(array_map(strval(...), $data['line_ids'] ?? [$lineId]));
        if (! in_array($lineId, $lineIds, true)) {
            $lineIds[] = $lineId;
        }

        $selected = $this->allocations->findByIds($entityId, $allocationIds);
        if ($selected->count() !== count($allocationIds)) {
            return $this->commands->error('validation', 'One or more allocation_ids do not exist for this entity.', 400);
        }
        $currency = $selected->first()?->currency;
        if ($selected->contains(fn (Allocation $a): bool => $a->currency !== $currency) || $currency !== $line->currency) {
            return $this->commands->error('currency_mismatch', 'Every allocation and every line in the group must share the same currency.', 422);
        }
        if ($this->reconciliations->allocationsConsumedElsewhere($line->reconciliation_account_id, $allocationIds, $lineId)) {
            return $this->commands->error('allocation_already_consumed', 'One or more allocations is already reconciled by another line.', 422);
        }
        $groupLines = collect($lineIds)->map(fn (string $lid): ?ReconciliationStatementLine => $this->reconciliations->getLine($entityId, $id, $lid))->filter();
        if ($groupLines->count() !== count($lineIds)) {
            return $this->commands->error('validation', 'One or more line_ids do not exist in this reconciliation.', 400);
        }
        $groupTotal = $groupLines->reduce(fn (string $sum, ReconciliationStatementLine $l): string => ExactDecimal::add($sum, $l->amount), '0.0000');
        $allocationTotal = $selected->reduce(fn (string $sum, Allocation $a): string => ExactDecimal::add($sum, $a->bank_amount), '0.0000');
        if (ExactDecimal::compare($groupTotal, $allocationTotal) !== 0) {
            return $this->commands->error('match_total_mismatch', 'The selected allocations do not exactly total the line group amount.', 422);
        }

        foreach ($groupLines as $groupLine) {
            $version = $groupLine->id === $lineId ? $expected : $groupLine->version;
            if ($this->reconciliations->commitMatch($groupLine->id, $allocationIds, $version) === null) {
                return $this->conflict($entityId, $id, $lineId);
            }
        }
        $line = $this->reconciliations->getLine($entityId, $id, $lineId);
        $this->audit->record('reconciliation', 'line_matched', 'reconciliation_statement_line', $lineId, $actor->id, $entityId, after: ['allocation_ids' => $allocationIds, 'line_ids' => $lineIds], correlationId: $this->correlation());
        $this->outbox->record('LineMatched', 'BankReconciliation', $id, ['reconciliationId' => $id, 'lineId' => $lineId, 'allocationIds' => $allocationIds], $entityId);
        $body = ['line' => $this->lineSummary($line)];
        $this->commands->store($actor->id, $entityId, 'POST /v1/reconciliations/'.$id.'/lines/'.$lineId.'/match', (string) $key, $this->commands->hash($data), 200, $body);

        return new DocumentActionResult($body);
    }

    public function confirmMatch(User $actor, string $entityId, string $id, string $lineId, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.confirm')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $line = $this->reconciliations->getLine($entityId, $id, $lineId);
        if ($line === null) {
            return $this->notFound();
        }
        if ($line->status !== 'Matched') {
            return $this->commands->error('line_not_matched', 'This line is not currently Matched.', 422);
        }
        if ($this->reconciliations->commitConfirm($lineId, $expected) === null) {
            return $this->conflict($entityId, $id, $lineId);
        }
        // Cascade to every sibling line matched together in the same MatchLine group.
        $siblings = $this->reconciliations->linesByStatus($id, ['Matched'])
            ->filter(fn (ReconciliationStatementLine $l): bool => $l->id !== $lineId && $l->matched_allocation_ids === $line->matched_allocation_ids);
        foreach ($siblings as $sibling) {
            $this->reconciliations->commitConfirm($sibling->id, $sibling->version);
        }
        $line = $this->reconciliations->getLine($entityId, $id, $lineId);
        $this->audit->record('reconciliation', 'line_reconciled', 'reconciliation_statement_line', $lineId, $actor->id, $entityId, after: $this->lineSummary($line), correlationId: $this->correlation());
        $this->outbox->record('LineReconciled', 'BankReconciliation', $id, ['reconciliationId' => $id, 'lineId' => $lineId, 'allocationIds' => $line->matched_allocation_ids], $entityId);
        $body = ['line' => $this->lineSummary($line)];
        $this->commands->store($actor->id, $entityId, 'POST /v1/reconciliations/'.$id.'/lines/'.$lineId.'/confirm', (string) $key, $this->commands->hash([]), 200, $body);

        return new DocumentActionResult($body);
    }

    /** @param array<string, mixed> $data */
    public function createBankEntry(User $actor, string $entityId, string $id, string $lineId, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.create_bank_entry')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $preflight = $this->preflightBankEntry($entityId, $id, $lineId, $data, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        $payload = ['reconciliation_id' => $id, 'line_id' => $lineId, 'offset_account_id' => $data['offset_account_id'], 'narration' => $data['narration'] ?? null, 'expected_version' => $expected, 'idempotency_key' => $key];
        $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('reconciliation_create_bank_entry', 1, $payload, $lineId, 'reconciliation.reconciliations.create_bank_entry', $expected), 'reconciliation_create_bank_entry:'.$lineId, (string) $key, $this->correlation() ?? (string) Str::uuid());

        return new DocumentActionResult($result->payload, $result->status, $result->headers);
    }

    public function complete(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.complete')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $preflight = $this->preflightComplete($entityId, $id, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        $payload = ['reconciliation_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key];
        $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('reconciliation_complete', 1, $payload, $id, 'reconciliation.reconciliations.complete', $expected), 'reconciliation_complete:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

        return new DocumentActionResult($result->payload, $result->status, $result->headers);
    }

    public function reopen(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.reopen')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null) {
            return $this->notFound();
        }
        if ($reconciliation->state !== 'Completed') {
            return $this->commands->error('reconciliation_not_reopenable', 'Only a Completed reconciliation may be reopened.', 422);
        }
        if ($reconciliation->version !== $expected) {
            return $this->conflict($entityId, $id, null);
        }
        $payload = ['reconciliation_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key];
        $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('reconciliation_reopen', 1, $payload, $id, 'reconciliation.reconciliations.reopen', $expected), 'reconciliation_reopen:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

        return new DocumentActionResult($result->payload, $result->status, $result->headers);
    }

    /** @param array<string, mixed> $payload */
    public function executeApproved(string $type, array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        return match ($type) {
            'create_bank_entry' => $this->executeBankEntry($payload, $context),
            'complete' => $this->executeComplete($payload, $context),
            'reopen' => $this->executeReopen($payload, $context),
            default => $this->commands->error('validation', 'Unknown reconciliation approval type.', 400),
        };
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.read')) {
            return $denied;
        }
        $reconciliation = $this->reconciliations->getById($entityId, $id);

        return $reconciliation === null ? $this->notFound() : new DocumentActionResult(['reconciliation' => $this->detail($reconciliation)]);
    }

    /** @param array<string, mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'created_at_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        unset($filters['limit'], $filters['cursor']);
        $page = $this->reconciliations->search($entityId, $filters, $cursor, $limit);

        return new DocumentActionResult([
            'reconciliations' => $page->getCollection()->map(fn (BankReconciliation $r): array => $this->summary($r))->all(),
            'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)],
        ]);
    }

    public function unmatched(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.reconciliations.read')) {
            return $denied;
        }
        if ($this->reconciliations->getById($entityId, $id) === null) {
            return $this->notFound();
        }
        $lines = $this->reconciliations->linesByStatus($id, ['Unreconciled', 'Suggested', 'Matched', 'Unexplained']);

        return new DocumentActionResult(['lines' => $lines->map(fn (ReconciliationStatementLine $l): array => $this->lineSummary($l))->all()]);
    }

    // --- private helpers -----------------------------------------------------------------

    /** @param array<string, mixed> $data */
    private function preflightBankEntry(string $entityId, string $id, string $lineId, array $data, int $expected): ?DocumentActionResult
    {
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null || ! in_array($reconciliation->state, ['Draft', 'InProgress', 'Reopened'], true)) {
            return $reconciliation === null ? $this->notFound() : $this->commands->error('reconciliation_not_editable', 'This reconciliation batch is not editable.', 422);
        }
        $line = $this->reconciliations->getLine($entityId, $id, $lineId);
        if ($line === null) {
            return $this->notFound();
        }
        if (! in_array($line->status, ['Unreconciled', 'Unexplained'], true)) {
            return $this->commands->error('line_not_resolvable', 'This line cannot be resolved by a bank-only entry in its current state.', 422);
        }
        if ($line->version !== $expected) {
            return $this->conflict($entityId, $id, $lineId);
        }
        $offsetAccountId = $data['offset_account_id'] ?? null;
        if (! is_string($offsetAccountId) || $offsetAccountId === '') {
            return $this->commands->error('offset_account_required', 'offset_account_id is required.', 400);
        }
        if (! $this->ledgerAccounts->isOwnedByEntity($entityId, $offsetAccountId)) {
            return $this->commands->error('invalid_offset_account', 'The offset account was not found for this entity.', 422);
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function executeBankEntry(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = (string) ($payload['reconciliation_id'] ?? '');
        $lineId = (string) ($payload['line_id'] ?? '');
        $offsetAccountId = (string) ($payload['offset_account_id'] ?? '');
        $expected = $payload['expected_version'] ?? null;
        if (! is_int($expected)) {
            return $this->commands->error('validation', 'Invalid approved bank-entry payload.', 400);
        }
        $preflight = $this->preflightBankEntry($context->entityId, $id, $lineId, ['offset_account_id' => $offsetAccountId], $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        $line = $this->reconciliations->getLine($context->entityId, $id, $lineId);
        $reconciliation = $this->reconciliations->getById($context->entityId, $id);
        $account = $this->accounts->getById($context->entityId, $reconciliation->reconciliation_account_id);
        $isInflow = ExactDecimal::compare($line->amount, '0.0000') >= 0;
        $magnitude = ExactDecimal::compare($line->amount, '0.0000') >= 0 ? $line->amount : ExactDecimal::multiply($line->amount, '-1.0000');
        $lines = [
            ['account_id' => $account->ledger_account_id, 'description' => (string) ($payload['narration'] ?? 'Bank-only reconciliation entry'), 'debit' => $isInflow ? $magnitude : '0.0000', 'credit' => $isInflow ? '0.0000' : $magnitude, 'currency' => $line->currency],
            ['account_id' => $offsetAccountId, 'description' => (string) ($payload['narration'] ?? 'Bank-only reconciliation entry'), 'debit' => $isInflow ? '0.0000' : $magnitude, 'credit' => $isInflow ? $magnitude : '0.0000', 'currency' => $line->currency],
        ];
        $posted = $this->posting->post($context->entityId, $lineId, $line->transaction_date->toDateString(), 'reconciliation_bank_entry', $context->approverId, $lines);
        if ($posted->journalId === null) {
            return $this->commands->error((string) ($posted->errorCode ?? 'validation'), 'The bank-only entry could not be posted.', $posted->errorCode === 'period_locked' ? 423 : 422);
        }
        $updated = $this->reconciliations->commitBankOnlyResolution($lineId, $posted->journalId, $expected);
        if ($updated === null) {
            return $this->conflict($context->entityId, $id, $lineId);
        }
        $this->audit->record('reconciliation', 'bank_only_entry_posted', 'reconciliation_statement_line', $lineId, $context->approverId, $context->entityId, after: $this->lineSummary($updated), correlationId: $context->correlationId);
        $this->outbox->record('BankOnlyEntryPosted', 'BankReconciliation', $id, ['reconciliationId' => $id, 'lineId' => $lineId, 'journalEntryId' => $posted->journalId, 'offsetAccountId' => $offsetAccountId], $context->entityId, metadata: ['causation_id' => $context->causationId]);

        return new DocumentActionResult(['line' => $this->lineSummary($updated)]);
    }

    private function preflightComplete(string $entityId, string $id, int $expected): ?DocumentActionResult
    {
        $reconciliation = $this->reconciliations->getById($entityId, $id);
        if ($reconciliation === null) {
            return $this->notFound();
        }
        if (! in_array($reconciliation->state, ['InProgress', 'Reopened'], true)) {
            return $this->commands->error('reconciliation_not_completable', 'Only an InProgress or Reopened reconciliation may be completed.', 422);
        }
        if ($reconciliation->version !== $expected) {
            return $this->conflict($entityId, $id, null);
        }
        $lines = $this->reconciliations->linesFor($id);
        $unreconciled = $lines->reject(fn (ReconciliationStatementLine $l): bool => $l->status === 'Reconciled');
        if ($unreconciled->isNotEmpty()) {
            return $this->commands->error('lines_not_fully_reconciled', 'Every statement line must be Reconciled before completion.', 422, ['unreconciled_line_ids' => $unreconciled->pluck('id')->values()->all()]);
        }
        $reconciledTotal = $lines->reduce(fn (string $sum, ReconciliationStatementLine $l): string => ExactDecimal::add($sum, $l->amount), '0.0000');
        $expectedClosing = ExactDecimal::add($reconciliation->opening_balance, $reconciledTotal);
        $difference = ExactDecimal::subtract($reconciliation->closing_balance, $expectedClosing);
        if (ExactDecimal::compare($difference, '0.0000') !== 0) {
            return $this->commands->error('unexplained_difference', 'The statement closing balance does not equal the reconciled system balance.', 422, ['difference' => $difference]);
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    private function executeComplete(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = (string) ($payload['reconciliation_id'] ?? '');
        $expected = $payload['expected_version'] ?? null;
        if (! is_int($expected)) {
            return $this->commands->error('validation', 'Invalid approved completion payload.', 400);
        }
        $preflight = $this->preflightComplete($context->entityId, $id, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        $reconciliation = $this->reconciliations->getById($context->entityId, $id);
        $account = $this->accounts->getById($context->entityId, $reconciliation->reconciliation_account_id);
        $watermark = $this->watermark($context->entityId, $account->ledger_account_id);
        $content = ['reconciliation_account_id' => $account->id, 'period_ref' => $reconciliation->period_ref, 'opening_balance' => $reconciliation->opening_balance, 'closing_balance' => $reconciliation->closing_balance, 'lines' => $this->reconciliations->linesFor($id)->map(fn (ReconciliationStatementLine $l): array => $this->lineSummary($l))->all()];
        $contentHash = hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $updated = $this->reconciliations->commitCompletion($context->entityId, $id, $expected, $watermark, $contentHash, $context->approverId);
        if ($updated === null) {
            return $this->conflict($context->entityId, $id, null);
        }
        $this->audit->record('reconciliation', 'reconciliation_completed', 'bank_reconciliation', $id, $context->approverId, $context->entityId, after: $this->summary($updated), correlationId: $context->correlationId);
        $this->outbox->record('ReconciliationCompleted', 'BankReconciliation', $id, ['reconciliationId' => $id, 'reconciliationAccountId' => $account->id, 'periodRef' => $reconciliation->period_ref, 'closingBalance' => $reconciliation->closing_balance, 'sourceDataWatermark' => $watermark->toISOString(), 'contentHash' => $contentHash], $context->entityId, metadata: ['causation_id' => $context->causationId]);

        return new DocumentActionResult(['reconciliation' => $this->summary($updated)]);
    }

    /** @param array<string, mixed> $payload */
    private function executeReopen(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = (string) ($payload['reconciliation_id'] ?? '');
        $expected = $payload['expected_version'] ?? null;
        if (! is_int($expected)) {
            return $this->commands->error('validation', 'Invalid approved reopen payload.', 400);
        }
        $reconciliation = $this->reconciliations->getById($context->entityId, $id);
        if ($reconciliation === null) {
            return $this->notFound();
        }
        if ($reconciliation->state !== 'Completed' || $reconciliation->version !== $expected) {
            return $reconciliation->state !== 'Completed' ? $this->commands->error('reconciliation_not_reopenable', 'Only a Completed reconciliation may be reopened.', 422) : $this->conflict($context->entityId, $id, null);
        }
        $updated = $this->reconciliations->commitReopen($context->entityId, $id, $expected, $context->approverId);
        if ($updated === null) {
            return $this->conflict($context->entityId, $id, null);
        }
        $this->audit->record('reconciliation', 'reconciliation_reopened', 'bank_reconciliation', $id, $context->approverId, $context->entityId, after: $this->summary($updated), correlationId: $context->correlationId);
        $this->outbox->record('ReconciliationReopened', 'BankReconciliation', $id, ['reconciliationId' => $id, 'reopenedBy' => $context->approverId], $context->entityId, metadata: ['causation_id' => $context->causationId]);

        return new DocumentActionResult(['reconciliation' => $this->summary($updated)]);
    }

    /** API Contracts §14.9/§14.11: the source-data watermark frozen at Completed, mirroring
     * ReportRun's watermark — the maximum posted_at of any qualifying Allocation activity on
     * this bank ledger account up to the moment of completion. */
    private function watermark(string $entityId, string $ledgerAccountId): Carbon
    {
        $max = $this->allocations->latestActivityAt($entityId, $ledgerAccountId, '1900-01-01', Carbon::now('UTC')->toDateString());

        return $max !== null ? Carbon::parse($max, 'UTC') : Carbon::now('UTC');
    }

    /** @return list<string> */
    private function consumedAllocationIds(string $reconciliationId): array
    {
        $ids = [];
        foreach ($this->reconciliations->linesByStatus($reconciliationId, ['Matched', 'Reconciled']) as $line) {
            foreach ((array) $line->matched_allocation_ids as $allocationId) {
                $ids[] = (string) $allocationId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * API Contracts §14.6: exact currency/amount, +/-3 day window, one-to-one/one-to-many/
     * many-to-one only, grouped total exactly equal. Combination search is bounded to
     * MAX_COMBINATION_SIZE items per side — a deliberate, stated scope limit, not a silent cap.
     *
     * @param  Collection<int, ReconciliationStatementLine>  $lines
     * @param  Collection<int, Allocation>  $candidates
     * @return array{0: Collection<string, Collection<int, array<string, mixed>>>, 1: list<string>}
     */
    private function matchLinesToGroups(Collection $lines, Collection $candidates): array
    {
        $maxSize = self::MAX_COMBINATION_SIZE;
        $result = collect();
        $usedAllocationIds = [];

        foreach ($lines as $line) {
            $pool = $candidates->reject(fn (Allocation $a): bool => in_array($a->id, $usedAllocationIds, true))
                ->filter(fn (Allocation $a): bool => abs($a->settlement_date->diffInDays($line->transaction_date, false)) <= 3);
            // combinationsOfSize() indexes with range(0, $size-1) against a plain array —
            // reject()/filter() preserve original (non-sequential) integer keys, so this
            // must be reindexed or combinationsOfSize() reads a nonexistent $items[0] as
            // soon as any earlier candidate has already been filtered/rejected out.
            $group = $this->findExactCombination($pool->values()->all(), $line->amount, $maxSize);
            if ($group === null) {
                continue;
            }
            $refMatch = $this->referenceMatches($line, $group);
            $result->put($line->id, collect([[
                'rank' => 1, 'total_amount' => $line->amount, 'currency' => $line->currency, 'reference_match' => $refMatch,
                'allocation_ids' => array_map(fn (Allocation $a): string => $a->id, $group),
            ]]));
            foreach ($group as $allocation) {
                $usedAllocationIds[] = $allocation->id;
            }
        }

        // Many-to-one: unmatched lines whose combined total exactly equals one remaining allocation.
        $remaining = $candidates->reject(fn (Allocation $a): bool => in_array($a->id, $usedAllocationIds, true))->values();
        $unmatchedLines = $lines->reject(fn (ReconciliationStatementLine $l): bool => $result->has($l->id))->values();
        foreach ($remaining as $allocation) {
            // Same reindexing requirement as above — filter() alone does not renumber keys.
            $pool = $unmatchedLines->filter(fn (ReconciliationStatementLine $l): bool => ! $result->has($l->id) && abs($allocation->settlement_date->diffInDays($l->transaction_date, false)) <= 3)->values()->all();
            $lineGroup = $this->findExactLineCombination($pool, $allocation->bank_amount, $maxSize);
            if ($lineGroup === null) {
                continue;
            }
            $refMatch = false;
            foreach ($lineGroup as $groupLine) {
                $result->put($groupLine->id, collect([[
                    'rank' => 1, 'total_amount' => $allocation->bank_amount, 'currency' => $allocation->currency, 'reference_match' => $refMatch,
                    'allocation_ids' => [$allocation->id],
                ]]));
            }
        }

        return [$result, array_keys($result->all())];
    }

    /** @param list<Allocation> $pool
     * @return list<Allocation>|null */
    private function findExactCombination(array $pool, string $target, int $maxSize): ?array
    {
        foreach ($this->combinations($pool, min($maxSize, count($pool))) as $combo) {
            $sum = array_reduce($combo, fn (string $s, Allocation $a): string => ExactDecimal::add($s, $a->bank_amount), '0.0000');
            if (ExactDecimal::compare($sum, $target) === 0) {
                return $combo;
            }
        }

        return null;
    }

    /** @param list<ReconciliationStatementLine> $pool
     * @return list<ReconciliationStatementLine>|null */
    private function findExactLineCombination(array $pool, string $target, int $maxSize): ?array
    {
        foreach ($this->combinations($pool, min($maxSize, count($pool))) as $combo) {
            $sum = array_reduce($combo, fn (string $s, ReconciliationStatementLine $l): string => ExactDecimal::add($s, $l->amount), '0.0000');
            if (ExactDecimal::compare($sum, $target) === 0) {
                return $combo;
            }
        }

        return null;
    }

    /** @param list<mixed> $items
     * @return iterable<int, list<mixed>> */
    private function combinations(array $items, int $maxSize): iterable
    {
        for ($size = 1; $size <= $maxSize; $size++) {
            yield from $this->combinationsOfSize($items, $size);
        }
    }

    /** @param list<mixed> $items
     * @return iterable<int, list<mixed>> */
    private function combinationsOfSize(array $items, int $size): iterable
    {
        $count = count($items);
        if ($size > $count) {
            return;
        }
        $indices = range(0, $size - 1);
        while (true) {
            yield array_map(fn (int $i): mixed => $items[$i], $indices);
            $i = $size - 1;
            while ($i >= 0 && $indices[$i] === $i + $count - $size) {
                $i--;
            }
            if ($i < 0) {
                return;
            }
            $indices[$i]++;
            for ($j = $i + 1; $j < $size; $j++) {
                $indices[$j] = $indices[$j - 1] + 1;
            }
        }
    }

    /** @param list<Allocation> $group */
    private function referenceMatches(ReconciliationStatementLine $line, array $group): bool
    {
        $needle = $this->normalize((string) ($line->external_bank_reference ?? $line->narration));
        if ($needle === '') {
            return false;
        }
        foreach ($group as $allocation) {
            $haystack = $this->normalize((string) ($allocation->allocation_number ?? ''));
            if ($haystack !== '' && (str_contains($haystack, $needle) || str_contains($needle, $haystack))) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($value)));
    }

    /** @return array<string, mixed> */
    private function summary(BankReconciliation $r): array
    {
        return [
            'id' => $r->id, 'entity_id' => $r->entity_id, 'reconciliation_account_id' => $r->reconciliation_account_id,
            'period_ref' => $r->period_ref, 'opening_balance' => $r->opening_balance, 'closing_balance' => $r->closing_balance,
            'state' => $r->state, 'source_data_watermark' => $r->source_data_watermark?->toISOString(), 'content_hash' => $r->content_hash,
            'opened_by' => $r->opened_by, 'completed_by' => $r->completed_by, 'completed_at' => $r->completed_at?->toISOString(),
            'reopened_by' => $r->reopened_by, 'reopened_at' => $r->reopened_at?->toISOString(), 'version' => $r->version,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(BankReconciliation $r): array
    {
        return [...$this->summary($r), 'lines' => $this->reconciliations->linesFor($r->id)->map(fn (ReconciliationStatementLine $l): array => $this->lineSummary($l))->all()];
    }

    /** @return array<string, mixed> */
    private function lineSummary(ReconciliationStatementLine $l): array
    {
        return [
            'id' => $l->id, 'reconciliation_id' => $l->reconciliation_id, 'source_line_identity' => $l->source_line_identity,
            'transaction_date' => $l->transaction_date->toDateString(), 'narration' => $l->narration,
            'amount' => ['amount' => $l->amount, 'currency' => $l->currency], 'external_bank_reference' => $l->external_bank_reference,
            'status' => $l->status, 'matched_allocation_ids' => $l->matched_allocation_ids, 'resolved_by_journal_entry_id' => $l->resolved_by_journal_entry_id,
            'version' => $l->version,
        ];
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The reconciliation was not found.', 404);
    }

    private function conflict(string $entityId, string $id, ?string $lineId): DocumentActionResult
    {
        $current = $lineId !== null ? $this->reconciliations->getLine($entityId, $id, $lineId) : $this->reconciliations->getById($entityId, $id);

        return $this->commands->error('concurrency_conflict', 'The resource was modified by another request.', 409, ['current_version' => $current?->version]);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
