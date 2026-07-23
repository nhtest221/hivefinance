<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User,User} */
function m5RunActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M5 ReportRun '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $generator = User::query()->create(['name' => 'Generator', 'email' => 'gen-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'chk-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    foreach ([$generator, $checker] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);
    $cash = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4010', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $entry = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'manual', 'entry_date' => '2026-07-15', 'state' => 'posted', 'narration' => 'fixture', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $cash->id, 'line_no' => 1, 'description' => 'Debit', 'debit' => '500.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $revenue->id, 'line_no' => 2, 'description' => 'Credit', 'debit' => '0.0000', 'credit' => '500.0000', 'currency' => 'BDT']);

    return [$entity, $generator, $checker];
}

it('generates an immutable ReportRun snapshot, retrieves it, and lists it', function (): void {
    [$entity, $generator] = m5RunActors();
    Sanctum::actingAs($generator);

    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('report_run.state', 'Generated')
        ->assertJsonPath('report_run.version', 1)
        ->json('report_run');

    expect($run['content_hash'])->not->toBeEmpty();

    $this->getJson('/v1/report-runs/'.$run['id'], ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('report_run.content.totals.balanced', true);

    $this->getJson('/v1/report-runs?report_type=trial_balance', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('report_runs.0.id', $run['id']);
});

it('requires a different actor to approve and blocks the generator approving their own run', function (): void {
    [$entity, $generator] = m5RunActors();
    Sanctum::actingAs($generator);
    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');

    $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'sod_exception_required');
});

it('approves a ReportRun with a durable checker action, recording review and approval atomically', function (): void {
    [$entity, $generator, $checker] = m5RunActors();
    Sanctum::actingAs($generator);
    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');

    Sanctum::actingAs($checker);
    $approved = $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('report_run.state', 'Approved')
        ->json('report_run');

    expect($approved['approved_by'])->toBe($checker->id)
        ->and($approved['reviewed_by'])->toBe($checker->id)
        ->and($approved['approved_at'])->toBe($approved['reviewed_at']);
});

it('automatically supersedes the prior approved run for the same reproducibility key', function (): void {
    [$entity, $generator, $checker] = m5RunActors();
    Sanctum::actingAs($generator);
    $first = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$first['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    Sanctum::actingAs($generator);
    $second = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$second['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    $this->getJson('/v1/report-runs/'.$first['id'], ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('report_run.state', 'Superseded')
        ->assertJsonPath('report_run.superseded_by_report_run_id', $second['id']);
});

it('rejects generation for an unknown report_type and approval of an already-approved run', function (): void {
    [$entity, $generator, $checker] = m5RunActors();
    Sanctum::actingAs($generator);
    $this->postJson('/v1/report-runs', ['report_type' => 'nonsense'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(400);

    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'report_run_not_approved');
});

it('registers exactly the 14 frozen M5 public endpoints', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => implode('|', $route->methods()).' /'.$route->uri())
        ->filter(fn (string $route): bool => (bool) preg_match('#/v1/(reports/(trial-balance|general-ledger|profit-loss|balance-sheet|ar-ageing|ap-ageing|tax-summary|fx-revaluation|cash-view)$|report-runs(/\{id\}(/approve|/export)?)?$)#', $route))
        ->unique()
        ->values();

    expect($routes)->toHaveCount(14);
});

it('exports an immutable ReportRun snapshot as CSV and PDF without recalculating, and rejects xlsx', function (): void {
    [$entity, $generator, $checker] = m5RunActors();
    Sanctum::actingAs($generator);
    $run = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$run['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    $csv = $this->get('/v1/report-runs/'.$run['id'].'/export?format=csv', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->content())->toContain('report_type', 'trial_balance', $run['content_hash'], 'Approved');

    $pdf = $this->get('/v1/report-runs/'.$run['id'].'/export?format=pdf', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->content(), 0, 4))->toBe('%PDF');

    $this->get('/v1/report-runs/'.$run['id'].'/export?format=xlsx', ['X-Entity-Id' => $entity->id])
        ->assertStatus(400);
});

it('allows exporting a superseded run for historical audit but visibly states its state', function (): void {
    [$entity, $generator, $checker] = m5RunActors();
    Sanctum::actingAs($generator);
    $first = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$first['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    Sanctum::actingAs($generator);
    $second = $this->postJson('/v1/report-runs', ['report_type' => 'trial_balance', 'as_of' => '2026-07-31'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('report_run');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/report-runs/'.$second['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    $csv = $this->get('/v1/report-runs/'.$first['id'].'/export?format=csv', ['X-Entity-Id' => $entity->id])->assertOk();
    expect($csv->content())->toContain('Superseded');
});
