<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Period\AccountingPeriod;
use App\Models\Reporting\ReportRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API Contracts §13.4; Governance Clarification Record M5-GOV-002 (docs/HiveFin_Decision_Log.md):
 * report_source_not_ready fires only when the system cannot prove a complete, reproducible
 * source snapshot for the requested report scope — never merely because the result is empty.
 */
uses(RefreshDatabase::class);

it('rejects trial_balance generation with no as_of, an unavailable source scope, with zero side effects', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $response = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_source_not_ready')
        ->assertJsonPath('details.rule', 'report_source_not_ready')
        ->assertJsonPath('details.source_category', 'as_of')
        ->assertJsonPath('details.readiness_state', 'missing');

    expect($response->json('message'))->toBeString();
    expect(ReportRun::query()->where('entity_id', $entity->id)->count())->toBe(0);
    expect(AuditLog::query()->where('entity_id', $entity->id)->where('action', 'report_run_generated')->count())->toBe(0);
    expect(OutboxMessage::query()->where('entity_id', $entity->id)->where('event_type', 'ReportRunGenerated')->count())->toBe(0);
});

it('rejects profit_and_loss generation with no period_ref, an incomplete watermark scope, with zero side effects', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $this->postJson('/v1/report-runs', ['report_type' => 'profit_and_loss'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_source_not_ready')
        ->assertJsonPath('details.source_category', 'period_ref')
        ->assertJsonPath('details.readiness_state', 'missing');

    expect(ReportRun::query()->where('entity_id', $entity->id)->count())->toBe(0);
});

it('rejects balance_sheet, ar_ageing, ap_ageing, tax_summary, fx_revaluation, and cash_view the same way when their required scope is missing', function (string $reportType, string $expectedCategory): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $this->postJson('/v1/report-runs', ['report_type' => $reportType], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_source_not_ready')
        ->assertJsonPath('details.source_category', $expectedCategory);

    expect(ReportRun::query()->where('entity_id', $entity->id)->count())->toBe(0);
})->with([
    ['balance_sheet', 'as_of'],
    ['ar_ageing', 'as_of'],
    ['ap_ageing', 'as_of'],
    ['tax_summary', 'period_ref'],
    ['fx_revaluation', 'period_ref'],
    ['cash_view', 'period_ref'],
]);

it('leaves general_ledger on its pre-existing validation error, unrelated to report_source_not_ready', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $this->postJson('/v1/report-runs', ['report_type' => 'general_ledger'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(400);
});

it('rejects fx_revaluation for a period_ref that does not exist with the existing not_found rule, not report_source_not_ready', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $this->postJson('/v1/report-runs', ['report_type' => 'fx_revaluation', 'period_ref' => '2099-12'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(404)
        ->assertJsonPath('error_code', 'not_found');
});

it('generates a successful, zero-totals ReportRun when the source scope is complete but the entity has no matching activity', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $response = $this->postJson('/v1/report-runs', ['report_type' => 'fx_revaluation', 'period_ref' => '2026-07'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('report_run.state', 'Generated');

    $run = ReportRun::query()->findOrFail($response->json('report_run.id'));
    expect($run->content['net_revaluation']['amount'])->toBe('0.0000')
        ->and($run->content['figures'])->toBe([])
        ->and($run->content['revaluation_run_ids'])->toBe([]);
});

it('generates a successful trial_balance with valid zero totals for a brand new entity with a complete as_of scope', function (): void {
    $entity = Entity::query()->create(['legal_name' => 'M5 Readiness Empty '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $generator = User::query()->create(['name' => 'Generator', 'email' => 'gen-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $generator->entities()->attach($entity->id, ['status' => 'active']);
    $generator->roles()->attach($role->id, ['entity_id' => $entity->id]);

    Sanctum::actingAs($generator);
    $response = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('report_run.state', 'Generated');

    $run = ReportRun::query()->findOrFail($response->json('report_run.id'));
    expect($run->content['rows'])->toBe([])
        ->and($run->content['totals']['debit'])->toBe('0.0000')
        ->and($run->content['totals']['credit'])->toBe('0.0000')
        ->and($run->content['totals']['balanced'])->toBeTrue();
});

it('keeps exact idempotency replay of a report_source_not_ready failure side-effect free', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);
    $key = (string) Str::uuid();

    $first = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_source_not_ready');
    $second = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_source_not_ready');

    expect($first->json())->toBe($second->json());
    expect(ReportRun::query()->where('entity_id', $entity->id)->count())->toBe(0);
    expect(AuditLog::query()->where('entity_id', $entity->id)->count())->toBe(0);
    expect(OutboxMessage::query()->where('entity_id', $entity->id)->count())->toBe(0);
});

it('exposes no infrastructure detail in the report_source_not_ready error', function (): void {
    [$entity, $generator] = m5ReadinessActors();
    Sanctum::actingAs($generator);

    $body = $this->postJson('/v1/report-runs', ['report_type' => 'ar_ageing'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->json();

    expect(array_keys($body['details']))->toBe(['rule', 'source_category', 'readiness_state']);
    $serialized = json_encode($body);
    expect($serialized)->not->toContain('SELECT')
        ->and($serialized)->not->toContain('select ')
        ->and($serialized)->not->toContain('/Users/')
        ->and($serialized)->not->toContain('.php');
});

/** @return array{Entity,User} */
function m5ReadinessActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M5 Readiness '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $generator = User::query()->create(['name' => 'Generator', 'email' => 'gen-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $generator->entities()->attach($entity->id, ['status' => 'active']);
    $generator->roles()->attach($role->id, ['entity_id' => $entity->id]);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);
    $cash = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4010', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $entry = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'manual', 'entry_date' => '2026-07-15', 'state' => 'posted', 'narration' => 'fixture', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $cash->id, 'line_no' => 1, 'description' => 'Debit', 'debit' => '500.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $revenue->id, 'line_no' => 2, 'description' => 'Credit', 'debit' => '0.0000', 'credit' => '500.0000', 'currency' => 'BDT']);

    return [$entity, $generator];
}
