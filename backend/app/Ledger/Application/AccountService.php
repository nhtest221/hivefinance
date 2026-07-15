<?php

namespace App\Ledger\Application;

use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class AccountService
{
    public function __construct(
        private LedgerAuthorizationService $authorization,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, string $entityId, array $data): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $normalBalance = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';

        $account = DB::transaction(function () use ($actor, $entityId, $data, $normalBalance): LedgerAccount {
            $account = LedgerAccount::query()->create([
                'entity_id' => $entityId,
                'code' => $data['code'],
                'name' => $data['name'],
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

            return $account;
        });

        return new LedgerActionResult(['account' => $this->present($account)], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, string $entityId, string $accountId, array $data): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (! $account instanceof LedgerAccount) {
            return $this->notFound();
        }

        if (array_key_exists('type', $data) && $data['type'] !== $account->type && $account->journalLines()->exists()) {
            return new LedgerActionResult([
                'error_code' => 'invariant_violation',
                'message' => 'Account type cannot change after postings exist.',
                'details' => ['rule' => 'account_type_immutable_once_posted'],
            ], 422);
        }

        $account = DB::transaction(function () use ($actor, $entityId, $data, $account): LedgerAccount {
            $before = $this->present($account);
            $account->fill(array_intersect_key($data, array_flip(['name', 'type'])));
            if (array_key_exists('type', $data)) {
                $account->normal_balance = in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit';
            }
            $account->version++;
            $account->save();

            $this->audit->record('ledger', 'account_updated', 'ledger_account', $account->id, $actor->id, $entityId, $before, $this->present($account));

            return $account;
        });

        return new LedgerActionResult(['account' => $this->present($account)]);
    }

    public function deactivate(User $actor, string $entityId, string $accountId): LedgerActionResult
    {
        $permission = 'ledger.accounts.manage';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        $account = LedgerAccount::query()->where('entity_id', $entityId)->find($accountId);
        if (! $account instanceof LedgerAccount) {
            return $this->notFound();
        }

        $account = DB::transaction(function () use ($actor, $entityId, $account): LedgerAccount {
            $before = $this->present($account);
            $account->status = 'deactivated';
            $account->version++;
            $account->save();

            $this->audit->record('ledger', 'account_deactivated', 'ledger_account', $account->id, $actor->id, $entityId, $before, $this->present($account));
            $this->outbox->record('AccountDeactivated', 'LedgerAccount', $account->id, $this->present($account), $entityId);

            return $account;
        });

        return new LedgerActionResult(['account' => $this->present($account)]);
    }

    public function list(User $actor, string $entityId): LedgerActionResult
    {
        $permission = 'ledger.accounts.read';
        if (! $this->authorization->can($actor, $entityId, $permission)) {
            return $this->authorization->denyResponse($permission);
        }

        /** @var Collection<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->orderBy('code')->get();

        return new LedgerActionResult([
            'accounts' => $accounts->map(fn (LedgerAccount $account): array => $this->present($account))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(LedgerAccount $account): array
    {
        return [
            'id' => $account->id,
            'entity_id' => $account->entity_id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'normal_balance' => $account->normal_balance,
            'status' => $account->status,
            'version' => $account->version,
        ];
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
