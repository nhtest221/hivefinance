<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\Reporting\AccountClassificationVersion;
use App\Models\Reporting\ReportLayoutVersion;
use App\Models\Settlement\Allocation;
use App\Models\User;
use App\Period\Application\CloseGateProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User,User,AccountingPeriod,LedgerAccount,ReconciliationAccount} */
function m6GateActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M6 Gate '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'mk-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'chk-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    foreach ([$maker, $checker] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    $period = AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open', 'vat_lock_status' => 'unlocked', 'version' => 1]);
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'NRB Current', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $account = ReconciliationAccount::query()->create(['entity_id' => $entity->id, 'ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB Current', 'reconciliation_enabled' => true, 'version' => 1]);

    return [$entity, $maker, $checker, $period, $ledgerAccount, $account];
}

it('reports unmet with no evidence for bank_reconciliation_completed when no BankReconciliation exists', function (): void {
    [$entity, , , $period] = m6GateActors();
    $provider = app(CloseGateProviderRegistry::class)->resolve('reconciliation');

    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'bank_reconciliation_completed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('unmet')
        ->and($result->sourceReference)->toBeNull()
        ->and($result->evidenceHash)->toBeNull();
});

it('never satisfies a Reporting-owned gate - that remains M5-owned', function (): void {
    [$entity, , , $period] = m6GateActors();
    $provider = app(CloseGateProviderRegistry::class)->resolve('reconciliation');

    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'trial_balance_reviewed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('unmet');
});

it('is vacuously satisfied when no ReconciliationAccount has reconciliation_enabled=true', function (): void {
    $entity = Entity::query()->create(['legal_name' => 'M6 Gate No Accounts '.Str::uuid(), 'functional_currency' => 'BDT']);
    $period = AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);
    $provider = app(CloseGateProviderRegistry::class)->resolve('reconciliation');

    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'bank_reconciliation_completed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('satisfied')
        ->and($result->evidenceVersion)->toBe(0)
        ->and($result->sourceReference)->toBeNull();
});

it('satisfies the gate from a Completed reconciliation with composite evidence, and detects stale evidence from a later posting', function (): void {
    [$entity, $maker, $checker, $period, $ledgerAccount, $account] = m6GateActors();
    Allocation::query()->create(['entity_id' => $entity->id, 'allocation_number' => 'RCPT-1', 'operation' => 'receipt', 'party_type' => 'customer', 'party_id' => (string) Str::uuid(), 'settlement_date' => '2026-07-05', 'bank_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'gross_amount' => '9000.0000', 'bank_amount' => '9000.0000', 'withholding_amount' => '0.0000', 'allocated_amount' => '9000.0000', 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '9000.0000', 'journal_entry_ids' => [], 'state' => 'posted', 'version' => 1, 'created_by' => (string) Str::uuid(), 'posted_at' => now('UTC')]);

    Sanctum::actingAs($maker);
    $reconciliation = $this->postJson('/v1/reconciliations', ['reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '9000.0000'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation');
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', ['file_hash' => 'h1', 'lines' => [['source_line_identity' => 'row-1', 'transaction_date' => '2026-07-05', 'narration' => 'NEFT', 'amount' => ['amount' => '9000.0000', 'currency' => 'BDT'], 'external_bank_reference' => 'REF1']]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $suggestions = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/match-suggestions', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json();
    $lineId = $suggestions['lines'][0]['line_id'];
    $allocationId = $suggestions['lines'][0]['suggestions'][0]['allocation_ids'][0];
    $matched = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/match', ['allocation_ids' => [$allocationId]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $suggestions['lines'][0]['version']])->json('line');
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/confirm', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $matched['version']])->assertOk();
    $pending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    $provider = app(CloseGateProviderRegistry::class)->resolve('reconciliation');
    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'bank_reconciliation_completed', (string) Str::uuid(), Carbon::now('UTC'));
    expect($result->status)->toBe('satisfied')
        ->and($result->sourceContext)->toBe('reconciliation')
        ->and($result->sourceReference)->toBe($reconciliation['id'])
        ->and($result->reviewedBy)->toBe($checker->id)
        ->and($result->evidenceVersion)->toBe(1);

    // A later Allocation posted against the same bank account/period makes the gate stale.
    Allocation::query()->create(['entity_id' => $entity->id, 'allocation_number' => 'RCPT-2', 'operation' => 'receipt', 'party_type' => 'customer', 'party_id' => (string) Str::uuid(), 'settlement_date' => '2026-07-06', 'bank_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'gross_amount' => '100.0000', 'bank_amount' => '100.0000', 'withholding_amount' => '0.0000', 'allocated_amount' => '100.0000', 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '100.0000', 'journal_entry_ids' => [], 'state' => 'posted', 'version' => 1, 'created_by' => (string) Str::uuid(), 'posted_at' => now('UTC')->addMinute()]);
    $stale = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'bank_reconciliation_completed', (string) Str::uuid(), Carbon::now('UTC'));
    expect($stale->status)->toBe('unmet');
});

it('unlocks Hard Close once every M5 Reporting gate and the M6 Reconciliation gate are satisfied', function (): void {
    [$entity, $maker, $checker, $period, $ledgerAccount, $account] = m6GateActors();
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4010', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $entry = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => $period->id, 'period_ref' => '2026-07', 'entry_type' => 'manual', 'entry_date' => '2026-07-15', 'state' => 'posted', 'narration' => 'fixture', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $ledgerAccount->id, 'line_no' => 1, 'description' => 'Debit', 'debit' => '9000.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $revenue->id, 'line_no' => 2, 'description' => 'Credit', 'debit' => '0.0000', 'credit' => '9000.0000', 'currency' => 'BDT']);
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'balance_sheet', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'entries' => [
        ['account_id' => $revenue->id, 'code' => '4010', 'classification' => 'sales_revenue'],
        ['account_id' => $ledgerAccount->id, 'code' => '1010', 'classification' => 'asset_current'],
    ], 'effective_from' => '2026-01-01', 'effective_to' => null]);

    Sanctum::actingAs($maker);
    foreach ([
        ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'],
        ['report_type' => 'profit_and_loss', 'period_ref' => '2026-07'],
        ['report_type' => 'balance_sheet', 'as_of' => '2026-07-31'],
        ['report_type' => 'tax_summary', 'period_ref' => '2026-07'],
    ] as $request) {
        Sanctum::actingAs($maker);
        $run = $this->postJson('/v1/report-runs', $request, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('report_run');
        Sanctum::actingAs($checker);
        $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    }

    Allocation::query()->create(['entity_id' => $entity->id, 'allocation_number' => 'RCPT-1', 'operation' => 'receipt', 'party_type' => 'customer', 'party_id' => (string) Str::uuid(), 'settlement_date' => '2026-07-05', 'bank_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'gross_amount' => '9000.0000', 'bank_amount' => '9000.0000', 'withholding_amount' => '0.0000', 'allocated_amount' => '9000.0000', 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '9000.0000', 'journal_entry_ids' => [], 'state' => 'posted', 'version' => 1, 'created_by' => (string) Str::uuid(), 'posted_at' => now('UTC')]);
    Sanctum::actingAs($maker);
    $reconciliation = $this->postJson('/v1/reconciliations', ['reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '9000.0000'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation');
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', ['file_hash' => 'h1', 'lines' => [['source_line_identity' => 'row-1', 'transaction_date' => '2026-07-05', 'narration' => 'NEFT', 'amount' => ['amount' => '9000.0000', 'currency' => 'BDT'], 'external_bank_reference' => 'REF1']]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $suggestions = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/match-suggestions', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json();
    $lineId = $suggestions['lines'][0]['line_id'];
    $allocationId = $suggestions['lines'][0]['suggestions'][0]['allocation_ids'][0];
    $matched = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/match', ['allocation_ids' => [$allocationId]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $suggestions['lines'][0]['version']])->json('line');
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/confirm', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $matched['version']])->assertOk();
    $pending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    Sanctum::actingAs($maker);
    $this->postJson('/v1/periods/'.$period->id.'/soft-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    $hardClosePending = $this->postJson('/v1/periods/'.$period->id.'/hard-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertStatus(202)->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$hardClosePending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('command_result.body.period.state', 'HardClosed');
});
