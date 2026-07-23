<?php

namespace App\Reconciliation\Application;

use App\Ledger\Application\AccountReferenceQuery;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use InvalidArgumentException;

/** API Contracts §14.4; Repository Contracts §2; Aggregate Design §13. */
final readonly class ReconciliationAccountService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private ReconciliationAccountRepository $accounts,
        private AccountReferenceQuery $ledgerAccounts,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string, mixed> $data */
    public function configure(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.accounts.configure')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $op = 'POST /v1/reconciliation-accounts';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $ledgerAccountId = $data['ledger_account_id'] ?? null;
        if (! is_string($ledgerAccountId) || ! $this->ledgerAccounts->isActiveAsset($entityId, $ledgerAccountId)) {
            return $this->commands->error('invalid_ledger_account', 'The ledger account was not found, is not active, or is not an asset-type account.', 422);
        }
        if ($this->accounts->findByLedgerAccount($entityId, $ledgerAccountId) !== null) {
            return $this->commands->error('duplicate_reconciliation_account', 'A reconciliation account is already configured for this ledger account.', 422);
        }
        $currency = $data['currency'] ?? null;
        if (! is_string($currency) || strlen($currency) !== 3) {
            return $this->commands->error('validation', 'currency must be a 3-letter ISO 4217 code.', 400);
        }
        $displayName = $data['display_name'] ?? null;
        if (! is_string($displayName) || $displayName === '') {
            return $this->commands->error('validation', 'display_name is required.', 400);
        }

        $account = $this->accounts->create([
            'entity_id' => $entityId, 'ledger_account_id' => $ledgerAccountId, 'currency' => $currency, 'display_name' => $displayName,
            'masked_bank_identifier' => isset($data['masked_bank_identifier']) && is_string($data['masked_bank_identifier']) ? $data['masked_bank_identifier'] : null,
            'reconciliation_enabled' => ! array_key_exists('reconciliation_enabled', $data) || (bool) $data['reconciliation_enabled'],
        ]);
        $body = ['reconciliation_account' => $this->summary($account)];
        $this->audit->record('reconciliation', 'reconciliation_account_configured', 'reconciliation_account', $account->id, $actor->id, $entityId, after: $body['reconciliation_account'], correlationId: $this->correlation());
        $this->outbox->record('ReconciliationAccountConfigured', 'ReconciliationAccount', $account->id, $body['reconciliation_account'], $entityId);
        $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

        return new DocumentActionResult($body, 201);
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.accounts.configure')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $op = 'PATCH /v1/reconciliation-accounts/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $existing = $this->accounts->getById($entityId, $id);
        if ($existing === null) {
            return $this->notFound();
        }
        $before = $this->summary($existing);
        $attributes = [];
        foreach (['display_name', 'masked_bank_identifier', 'reconciliation_enabled', 'column_mapping'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }
        $account = $this->accounts->update($entityId, $id, $attributes, $expected);
        if ($account === null) {
            return $this->conflict($entityId, $id);
        }

        $body = ['reconciliation_account' => $this->summary($account)];
        $this->audit->record('reconciliation', 'reconciliation_account_updated', 'reconciliation_account', $account->id, $actor->id, $entityId, before: $before, after: $body['reconciliation_account'], correlationId: $this->correlation());
        $this->outbox->record('ReconciliationAccountUpdated', 'ReconciliationAccount', $account->id, $body['reconciliation_account'], $entityId);
        $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

        return new DocumentActionResult($body);
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.accounts.read')) {
            return $denied;
        }
        $account = $this->accounts->getById($entityId, $id);

        return $account === null ? $this->notFound() : new DocumentActionResult(['reconciliation_account' => $this->summary($account)]);
    }

    /** @param array<string, mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reconciliation.accounts.read')) {
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
        $page = $this->accounts->search($entityId, $filters, $cursor, $limit);

        return new DocumentActionResult([
            'reconciliation_accounts' => $page->getCollection()->map(fn (ReconciliationAccount $a): array => $this->summary($a))->all(),
            'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(ReconciliationAccount $account): array
    {
        return [
            'id' => $account->id, 'entity_id' => $account->entity_id, 'ledger_account_id' => $account->ledger_account_id,
            'currency' => $account->currency, 'display_name' => $account->display_name, 'masked_bank_identifier' => $account->masked_bank_identifier,
            'reconciliation_enabled' => $account->reconciliation_enabled, 'column_mapping' => $account->column_mapping, 'version' => $account->version,
        ];
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The reconciliation account was not found.', 404);
    }

    private function conflict(string $entityId, string $id): DocumentActionResult
    {
        $current = $this->accounts->getById($entityId, $id);

        return $this->commands->error('concurrency_conflict', 'The reconciliation account was modified by another request.', 409, ['current_version' => $current?->version]);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
