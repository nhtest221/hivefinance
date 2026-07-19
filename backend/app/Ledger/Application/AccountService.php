<?php

namespace App\Ledger\Application;

use App\Models\IdempotencyRecord;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AccountService
{
    public function __construct(private LedgerAuthorizationService $authorization, private AuditLogger $audit, private Outbox $outbox)
    {
        // Promoted readonly dependencies keep the application service immutable.
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, string $entityId, array $data, ?string $idempotencyKey): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            return $this->validation('Idempotency-Key must be a UUID.', 400);
        }
        $operation = 'POST /v1/accounts';
        $hash = $this->hash($data);
        $replay = $this->replay($actor->id, $entityId, $operation, $idempotencyKey, $hash);
        if ($replay !== null) {
            return $replay;
        }

        $normalBalance = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';

        try {
            $result = DB::transaction(function () use ($actor, $entityId, $data, $normalBalance, $operation, $idempotencyKey, $hash): LedgerActionResult {
                $account = LedgerAccount::query()->create([
                    'entity_id' => $entityId,
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'type' => $data['type'],
                    'normal_balance' => $normalBalance,
                    'status' => 'active',
                ]);

                $this->audit->record(
                    module: 'ledger',
                    action: 'account_created',
                    recordType: 'ledger_account',
                    recordId: $account->id,
                    actorId: $actor->id,
                    entityId: $entityId,
                    after: $this->present($account),
                );
                $this->outbox->record('AccountCreated', 'LedgerAccount', $account->id, $this->present($account), $entityId);

                $body = ['account' => $this->present($account)];
                $this->store($actor->id, $entityId, $operation, $idempotencyKey, $hash, 201, $body);

                return new LedgerActionResult($body, 201);
            });
        } catch (UniqueConstraintViolationException) {
            return new LedgerActionResult(['error_code' => 'duplicate_resource', 'message' => 'The account code already exists.', 'details' => []], 409);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, string $entityId, string $accountId, array $data, ?string $idempotencyKey, ?string $ifMatch): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }
        $headers = $this->commandHeaders($idempotencyKey, $ifMatch);
        if ($headers !== null) {
            return $headers;
        }
        $expected = (int) trim((string) $ifMatch, '"');
        $operation = 'PATCH /v1/accounts/'.$accountId;
        $hash = $this->hash([$data, $expected]);
        $replay = $this->replay($actor->id, $entityId, $operation, (string) $idempotencyKey, $hash);
        if ($replay !== null) {
            return $replay;
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (($account instanceof LedgerAccount) === false) {
            return $this->notFound();
        }
        if ($account->version !== $expected) {
            return $this->conflict($account->version);
        }

        if (array_key_exists('type', $data) && $data['type'] !== $account->type && $account->journalLines()->exists()) {
            return new LedgerActionResult([
                'error_code' => 'invariant_violation',
                'message' => 'Account type cannot change after postings exist.',
                'details' => ['rule' => 'account_type_immutable_once_posted'],
            ], 422);
        }

        $result = DB::transaction(function () use ($actor, $entityId, $data, $account, $expected, $operation, $idempotencyKey, $hash): LedgerActionResult {
            $before = $this->present($account);
            $changes = array_intersect_key($data, array_flip(['name', 'description', 'type']));
            if (array_key_exists('type', $data)) {
                $changes['normal_balance'] = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';
            }
            $changes['version'] = $expected + 1;
            $changes['updated_at'] = now('UTC');
            if (LedgerAccount::query()->whereKey($account->id)->where('entity_id', $entityId)->where('version', $expected)->update($changes) !== 1) {
                return $this->conflict((int) LedgerAccount::query()->whereKey($account->id)->value('version'));
            }
            $account->refresh();

            $this->audit->record('ledger', 'account_updated', 'ledger_account', $account->id, $actor->id, $entityId, $before, $this->present($account));

            $body = ['account' => $this->present($account)];
            $this->store($actor->id, $entityId, $operation, (string) $idempotencyKey, $hash, 200, $body);

            return new LedgerActionResult($body);
        });

        return $result;
    }

    public function deactivate(User $actor, string $entityId, string $accountId, ?string $idempotencyKey, ?string $ifMatch): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }
        $headers = $this->commandHeaders($idempotencyKey, $ifMatch);
        if ($headers !== null) {
            return $headers;
        }
        $expected = (int) trim((string) $ifMatch, '"');
        $operation = 'POST /v1/accounts/'.$accountId.'/deactivate';
        $hash = $this->hash([$accountId, $expected]);
        $replay = $this->replay($actor->id, $entityId, $operation, (string) $idempotencyKey, $hash);
        if ($replay !== null) {
            return $replay;
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (($account instanceof LedgerAccount) === false) {
            return $this->notFound();
        }
        if ($account->version !== $expected) {
            return $this->conflict($account->version);
        }
        if ($account->status !== 'active') {
            return new LedgerActionResult(['error_code' => 'invariant_violation', 'message' => 'The account is already deactivated.', 'details' => ['rule' => 'account_already_deactivated']], 422);
        }

        $result = DB::transaction(function () use ($actor, $entityId, $account, $expected, $operation, $idempotencyKey, $hash): LedgerActionResult {
            $before = $this->present($account);
            if (LedgerAccount::query()->whereKey($account->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'active')->update(['status' => 'deactivated', 'version' => $expected + 1, 'updated_at' => now('UTC')]) !== 1) {
                return $this->conflict((int) LedgerAccount::query()->whereKey($account->id)->value('version'));
            }
            $account->refresh();

            $this->audit->record('ledger', 'account_deactivated', 'ledger_account', $account->id, $actor->id, $entityId, $before, $this->present($account));
            $this->outbox->record('AccountDeactivated', 'LedgerAccount', $account->id, $this->present($account), $entityId);

            $body = ['account' => $this->present($account)];
            $this->store($actor->id, $entityId, $operation, (string) $idempotencyKey, $hash, 200, $body);

            return new LedgerActionResult($body);
        });

        return $result;
    }

    public function list(User $actor, string $entityId, string $status = 'active', int $limit = 50, mixed $cursor = null): LedgerActionResult
    {
        $permission = 'ledger.accounts.read';
        if ($this->authorization->can($actor, $entityId, $permission) === false) {
            return $this->authorization->denyResponse($permission);
        }

        $binding = ['entity_id' => $entityId, 'status' => $status, 'order' => 'code,id'];
        try {
            [$decodedCursor, $boundary] = StableCursor::decode(is_string($cursor) ? $cursor : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->validation($exception->getMessage(), 400);
        }
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->where('status', $status)
            ->where('created_at', '<=', $boundary)
            ->orderBy('code')->orderBy('id')->cursorPaginate($limit, ['*'], 'cursor', $decodedCursor);

        return new LedgerActionResult([
            'accounts' => $accounts->getCollection()->map(fn (LedgerAccount $account): array => $this->present($account))->all(),
            'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($accounts->nextCursor(), $boundary, $binding)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(LedgerAccount $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'description' => $account->description,
            'type' => $account->type,
            'normal_balance' => $account->normal_balance,
            'status' => $account->status,
            'version' => $account->version,
            'created_at' => $account->created_at?->toISOString(),
            'updated_at' => $account->updated_at?->toISOString(),
        ];
    }

    private function commandHeaders(?string $key, ?string $ifMatch): ?LedgerActionResult
    {
        if (! is_string($key) || ! Str::isUuid($key)) {
            return $this->validation('Idempotency-Key must be a UUID.', 400);
        }
        if ($ifMatch === null) {
            return new LedgerActionResult(['error_code' => 'precondition_required', 'message' => 'If-Match is required.', 'details' => []], 428);
        }
        if (preg_match('/^"?\d+"?$/', $ifMatch) !== 1) {
            return $this->validation('If-Match must be an integer version.', 400);
        }

        return null;
    }

    /** @param array<mixed> $data */
    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function replay(string $actorId, string $entityId, string $operation, string $key, string $hash): ?LedgerActionResult
    {
        $record = IdempotencyRecord::query()->where('actor_id', $actorId)->where('entity_id', $entityId)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($record === null) {
            return null;
        }
        if ($record->request_hash !== $hash) {
            return new LedgerActionResult(['error_code' => 'idempotency_conflict', 'message' => 'The idempotency key was used for another request.', 'details' => []], 409);
        }

        return new LedgerActionResult($record->response_body, $record->response_status, ['Idempotent-Replay' => 'true']);
    }

    /** @param array<string, mixed> $body */
    private function store(string $actorId, string $entityId, string $operation, string $key, string $hash, int $status, array $body): void
    {
        IdempotencyRecord::query()->create(['actor_id' => $actorId, 'entity_id' => $entityId, 'operation' => $operation, 'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => $status, 'response_body' => $body]);
    }

    private function conflict(int $version): LedgerActionResult
    {
        return new LedgerActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The account version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function validation(string $message, int $status): LedgerActionResult
    {
        return new LedgerActionResult(['error_code' => 'validation', 'message' => $message, 'details' => []], $status);
    }

    private function notFound(): LedgerActionResult
    {
        return new LedgerActionResult([
            'error_code' => 'not_found',
            'message' => 'The account was not found.',
            'details' => [],
        ], 404);
    }
}
