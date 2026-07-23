<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\Reporting\AccountClassificationVersion;
use App\Models\Reporting\ReportLayoutVersion;
use App\Models\User;
use App\Period\Application\CloseGateProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User,User,AccountingPeriod} */
function m5GateActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M5 Gate '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $generator = User::query()->create(['name' => 'Generator', 'email' => 'gen-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'chk-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    foreach ([$generator, $checker] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    $period = AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open', 'vat_lock_status' => 'unlocked', 'version' => 1]);

    return [$entity, $generator, $checker, $period];
}

function m5GateJournal(Entity $entity, ?string $postedAt = null): void
{
    $cash = LedgerAccount::query()->firstOrCreate(['entity_id' => $entity->id, 'code' => '1010'], ['name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $revenue = LedgerAccount::query()->firstOrCreate(['entity_id' => $entity->id, 'code' => '4010'], ['name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $entry = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'manual', 'entry_date' => '2026-07-15', 'state' => 'posted', 'narration' => 'fixture', 'source_document_id' => (string) Str::uuid(), 'posted_at' => $postedAt ?? now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $cash->id, 'line_no' => 1, 'description' => 'Debit', 'debit' => '500.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $revenue->id, 'line_no' => 2, 'description' => 'Credit', 'debit' => '0.0000', 'credit' => '500.0000', 'currency' => 'BDT']);
}

it('reports unmet with no evidence for a Reporting gate when no ReportRun exists', function (): void {
    [$entity, , , $period] = m5GateActors();
    $provider = app(CloseGateProviderRegistry::class)->resolve('reporting');

    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'trial_balance_reviewed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('unmet')
        ->and($result->sourceReference)->toBeNull()
        ->and($result->evidenceHash)->toBeNull();
});

it('never satisfies bank_reconciliation_completed - that remains M6-owned', function (): void {
    [$entity, , , $period] = m5GateActors();
    $provider = app(CloseGateProviderRegistry::class)->resolve('reporting');

    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'bank_reconciliation_completed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('unmet');
});

it('satisfies a gate from the current Approved ReportRun with lossless evidence mapping, and detects stale evidence', function (): void {
    [$entity, $generator, $checker, $period] = m5GateActors();
    m5GateJournal($entity);
    Sanctum::actingAs($generator);
    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $approved = $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk()->json('report_run');

    $provider = app(CloseGateProviderRegistry::class)->resolve('reporting');
    $result = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'trial_balance_reviewed', (string) Str::uuid(), Carbon::now('UTC'));

    expect($result->status)->toBe('satisfied')
        ->and($result->sourceContext)->toBe('reporting')
        ->and($result->sourceReference)->toBe($run['id'])
        ->and($result->reviewedBy)->toBe($checker->id)
        ->and($result->evidenceVersion)->toBe($approved['version'])
        ->and($result->evidenceHash)->toBe($approved['content_hash']);

    // A later posting in the same period makes the approved run stale.
    m5GateJournal($entity, now('UTC')->addMinute()->toIso8601String());
    $stale = $provider->evaluate(1, $entity->id, $period->id, '2026-07', 'trial_balance_reviewed', (string) Str::uuid(), Carbon::now('UTC'));
    expect($stale->status)->toBe('unmet');
});

it('unlocks Hard Close for the four Reporting gates once each ReportRun is approved, leaving only bank_reconciliation_completed unmet', function (): void {
    [$entity, $generator, $checker, $period] = m5GateActors();
    m5GateJournal($entity);
    // A mandatory (reconciliation_enabled=true) ReconciliationAccount with no Completed
    // BankReconciliation keeps bank_reconciliation_completed genuinely unmet (API Contracts
    // §14.11) - without this, M6-GOV-001's vacuous-satisfaction rule would satisfy it by
    // default, defeating this test's purpose of proving Reporting gates alone are insufficient.
    ReconciliationAccount::query()->create(['entity_id' => $entity->id, 'ledger_account_id' => LedgerAccount::query()->where('entity_id', $entity->id)->where('code', '1010')->value('id'), 'currency' => 'BDT', 'display_name' => 'Cash', 'reconciliation_enabled' => true, 'version' => 1]);
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'balance_sheet', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'entries' => [
        ['account_id' => LedgerAccount::query()->where('entity_id', $entity->id)->where('code', '4010')->value('id'), 'code' => '4010', 'classification' => 'sales_revenue'],
        ['account_id' => LedgerAccount::query()->where('entity_id', $entity->id)->where('code', '1010')->value('id'), 'code' => '1010', 'classification' => 'asset_current'],
    ], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($generator);

    foreach ([
        ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'],
        ['report_type' => 'profit_and_loss', 'period_ref' => '2026-07'],
        ['report_type' => 'balance_sheet', 'as_of' => '2026-07-31'],
        ['report_type' => 'tax_summary', 'period_ref' => '2026-07'],
    ] as $request) {
        Sanctum::actingAs($generator);
        $run = $this->postJson('/v1/report-runs', $request, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('report_run');
        Sanctum::actingAs($checker);
        $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    }

    Sanctum::actingAs($generator);
    $this->postJson('/v1/periods/'.$period->id.'/soft-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    $this->postJson('/v1/periods/'.$period->id.'/hard-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertUnprocessable()
        ->assertJsonPath('details.rule', 'close_gate_unmet')
        ->assertJsonPath('details.unmet_gates', ['bank_reconciliation_completed']);
});
