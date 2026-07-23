<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\OutboxMessage;
use App\Models\Period\AccountingPeriod;
use App\Models\Period\PeriodCloseGateEvidence;
use App\Models\User;
use App\Period\Application\CloseGateProvider;
use App\Period\Application\CloseGateProviderRegistry;
use App\Period\Domain\CloseGateResult;
use App\Period\Infrastructure\UnavailableCloseGateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{User, User, Entity, AccountingPeriod} */
function m4PeriodActors(array $permissions): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4 Period '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $approver = User::query()->create(['name' => 'Approver', 'email' => 'approver-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'M4 Finance', 'slug' => 'm4-finance-'.Str::random(6), 'is_system' => false]);
    foreach (array_unique([...$permissions, 'identity.approvals.approve']) as $permission) {
        $role->permissions()->create(['permission' => $permission]);
    }
    foreach ([$maker, $approver] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    $period = AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open', 'vat_lock_status' => 'unlocked', 'version' => 1]);

    return [$maker->refresh(), $approver->refresh(), $entity, $period];
}

function m4AllGatesSatisfied(): void
{
    // Mutate the existing bound singleton in place: the mandatory-approval handler for
    // hard-close/reopen is wired once at container boot and holds a reference to this
    // same registry object, so replacing the container binding after boot would not
    // reach it — only mutating the object every holder already references does.
    $registry = app(CloseGateProviderRegistry::class);
    $provider = new class implements CloseGateProvider
    {
        public function evaluate(int $contractVersion, string $entityId, string $periodId, string $periodRef, string $gateType, string $correlationId, Carbon $evaluatedAt): CloseGateResult
        {
            $at = DateTimeImmutable::createFromInterface($evaluatedAt);

            return new CloseGateResult($gateType, 'satisfied', 'reporting', 'evidence-ref', $at, null, $at, 1, hash('sha256', $gateType));
        }
    };
    $registry->register('reporting', $provider);
    $registry->register('reconciliation', $provider);
}

function m4AllGatesUnmetAgain(): void
{
    $registry = app(CloseGateProviderRegistry::class);
    $registry->register('reporting', new UnavailableCloseGateProvider('reporting'));
    $registry->register('reconciliation', new UnavailableCloseGateProvider('reconciliation'));
}

it('soft closes an open period without posting or locking VAT', function (): void {
    [$maker, , $entity, $period] = m4PeriodActors(['periods.soft_close', 'periods.read']);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/periods/'.$period->id.'/soft-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('period.state', 'SoftClosed')
        ->assertJsonPath('period.vat_lock_status', 'unlocked')
        ->assertJsonPath('period.version', 2);

    expect(OutboxMessage::query()->where('event_type', 'PeriodSoftClosed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'period_soft_closed')->exists())->toBeTrue();
});

it('rejects soft close from the wrong state and rejects a stale version', function (): void {
    [$maker, , $entity, $period] = m4PeriodActors(['periods.soft_close']);
    $period->update(['state' => 'HardClosed']);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/periods/'.$period->id.'/soft-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'invalid_period_transition');

    $period->update(['state' => 'Open']);
    $this->postJson('/v1/periods/'.$period->id.'/soft-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '99'])
        ->assertStatus(409)->assertJsonPath('error_code', 'concurrency_conflict');
});

it('returns close_gate_unmet directly with no mutation while M5/M6 evidence is unavailable', function (): void {
    [$maker, , $entity, $period] = m4PeriodActors(['periods.hard_close', 'periods.soft_close']);
    $period->update(['state' => 'SoftClosed']);
    Sanctum::actingAs($maker);

    // bank_reconciliation_completed is vacuously satisfied here: this fixture configures no
    // ReconciliationAccount, and M6-GOV-001 defines "mandatory" as "configured" (API
    // Contracts §14.11) - zero configured accounts means zero requirements for this gate.
    $this->postJson('/v1/periods/'.$period->id.'/hard-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertUnprocessable()
        ->assertJsonPath('details.rule', 'close_gate_unmet')
        ->assertJsonPath('details.unmet_gates', ['trial_balance_reviewed', 'profit_and_loss_approved', 'balance_sheet_approved', 'vat_outputs_approved']);

    $period->refresh();
    expect($period->state)->toBe('SoftClosed')
        ->and($period->version)->toBe(1)
        ->and(PeriodCloseGateEvidence::query()->count())->toBe(0)
        ->and(OutboxMessage::query()->where('event_type', 'PeriodHardClosed')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'period_hard_closed')->exists())->toBeFalse();
});

it('hard closes only through mandatory four-eyes approval once every gate is satisfied, and locks VAT atomically', function (): void {
    m4AllGatesSatisfied();
    [$maker, $approver, $entity, $period] = m4PeriodActors(['periods.hard_close']);
    $period->update(['state' => 'SoftClosed']);
    Sanctum::actingAs($maker);

    $pending = $this->postJson('/v1/periods/'.$period->id.'/hard-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');

    $period->refresh();
    expect($period->state)->toBe('SoftClosed');

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('command_result.status', 200)
        ->assertJsonPath('command_result.body.period.state', 'HardClosed')
        ->assertJsonPath('command_result.body.period.vat_lock_status', 'locked');

    $period->refresh();
    expect($period->state)->toBe('HardClosed')
        ->and($period->vat_lock_status)->toBe('locked')
        ->and($period->close_evidence_set_hash)->not->toBeNull()
        ->and(PeriodCloseGateEvidence::query()->where('period_id', $period->id)->count())->toBe(5)
        ->and(OutboxMessage::query()->where('event_type', 'PeriodHardClosed')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'VATPeriodLocked')->exists())->toBeTrue();
});

it('leaves the approval pending on gate regression at approved-replay time instead of fabricating evidence', function (): void {
    [$maker, $approver, $entity, $period] = m4PeriodActors(['periods.hard_close']);
    m4AllGatesSatisfied();
    $period->update(['state' => 'SoftClosed']);
    Sanctum::actingAs($maker);
    $pending = $this->postJson('/v1/periods/'.$period->id.'/hard-close', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(202)->json('approval');

    // Evidence regresses to unmet before the approver acts.
    m4AllGatesUnmetAgain();

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(422)->assertJsonPath('error_code', 'originating_command_invalid');

    $period->refresh();
    expect($period->state)->toBe('SoftClosed')
        ->and(AuditLog::query()->where('action', 'approval_execution_failed')->exists())->toBeTrue();
});

it('reopens a hard closed period only through mandatory approval and unlocks VAT only under configured policy', function (): void {
    config()->set('period.vat_unlock_permitted', true);
    config()->set('documents.reason_codes', ['LATE_ADJUSTMENT']);
    [$maker, $approver, $entity, $period] = m4PeriodActors(['periods.reopen']);
    $period->update(['state' => 'HardClosed', 'vat_lock_status' => 'locked']);
    Sanctum::actingAs($maker);

    $pending = $this->postJson('/v1/periods/'.$period->id.'/reopen', ['reason_code' => 'LATE_ADJUSTMENT', 'narrative' => 'Approved late adjustment required', 'vat_unlock_requested' => true], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(202)->json('approval');

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('command_result.body.period.state', 'Reopened')
        ->assertJsonPath('command_result.body.period.vat_lock_status', 'unlocked_for_approved_adjustments')
        ->assertJsonPath('command_result.body.period.reclose_required', true)
        ->assertJsonPath('command_result.body.notification.event', 'PeriodReopened');

    $period->refresh();
    expect($period->state)->toBe('Reopened')
        ->and($period->reclose_required)->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'VATPeriodUnlocked')->exists())->toBeTrue();
});

it('rejects VAT unlock without a configured policy and rejects a missing reason', function (): void {
    config()->set('period.vat_unlock_permitted', null);
    config()->set('documents.reason_codes', ['LATE_ADJUSTMENT']);
    [$maker, , $entity, $period] = m4PeriodActors(['periods.reopen']);
    $period->update(['state' => 'HardClosed', 'vat_lock_status' => 'locked']);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/periods/'.$period->id.'/reopen', ['reason_code' => 'LATE_ADJUSTMENT', 'narrative' => 'Needs adjustment', 'vat_unlock_requested' => true], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'vat_unlock_policy_missing');

    $this->postJson('/v1/periods/'.$period->id.'/reopen', ['reason_code' => 'NOT_CONFIGURED', 'narrative' => 'Needs adjustment', 'vat_unlock_requested' => false], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'reopen_reason_required');
});

it('reads period detail with transitions and close-gate evidence, and lists with a close-gate summary', function (): void {
    [$maker, , $entity, $period] = m4PeriodActors(['periods.read']);
    Sanctum::actingAs($maker);

    $this->getJson('/v1/periods/'.$period->id, ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('period.state', 'Open')
        ->assertJsonCount(5, 'period.close_gates');

    // bank_reconciliation_completed is vacuously satisfied: no ReconciliationAccount is
    // configured for this entity (API Contracts §14.11, M6-GOV-001).
    $this->getJson('/v1/periods?state=Open&limit=10', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('periods.0.close_gate_summary.required', 5)
        ->assertJsonPath('periods.0.close_gate_summary.satisfied', 1);
});

it('registers exactly the five frozen M4 period endpoints', function (): void {
    $names = collect(app('router')->getRoutes())->map(fn ($route) => $route->getName())->filter();
    expect($names->intersect(['periods.index', 'periods.show', 'periods.soft-close', 'periods.hard-close', 'periods.reopen']))->toHaveCount(5);
});
