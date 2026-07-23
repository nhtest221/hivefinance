<?php

use App\CurrencyFx\Application\FxService;
use App\Models\AuditLog;
use App\Models\CurrencyFx\RateRecord;
use App\Models\CurrencyFx\RevaluationRun;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Period\AccountingPeriod;
use App\Models\Tax\TaxCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{User, User, Entity} */
function m1Actors(array $permissions): array
{
    $entity = Entity::query()->create(['legal_name' => 'M1 '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $approver = User::query()->create(['name' => 'Approver', 'email' => 'approver-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'M1 Finance', 'slug' => 'm1-finance-'.Str::random(6), 'is_system' => false]);
    foreach (array_unique([...$permissions, 'identity.approvals.approve']) as $permission) {
        $role->permissions()->create(['permission' => $permission]);
    }
    foreach ([$maker, $approver] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    return [$maker->refresh(), $approver->refresh(), $entity];
}

it('executes four-eyes tax configuration through the durable approval lifecycle', function (): void {
    config()->set('valuation.tax.jurisdictions', ['TEST']);
    [$maker, $approver, $entity] = m1Actors(['tax.codes.manage', 'tax.codes.read']);
    Sanctum::actingAs($maker);
    $pending = $this->postJson('/v1/tax/codes', ['code' => 'TEST-ZR', 'name' => 'Configured zero rate', 'jurisdiction' => 'TEST'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');
    expect(TaxCode::query()->count())->toBe(0);

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('approval.status', 'approved')->assertJsonPath('command_result.status', 201);

    expect(TaxCode::query()->where('code', 'TEST-ZR')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'tax_code_created')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->exists())->toBeTrue();
});

it('stores append-only rates and binds foreign journal lines to the exact record', function (): void {
    config()->set('valuation.fx.sources', ['TEST_SOURCE']);
    config()->set('valuation.fx.source_precedence', ['TEST_SOURCE']);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('valuation.fx.rounding_scale', 4);
    [$maker, , $entity] = m1Actors(['fx.rates.manage', 'fx.rates.read', 'ledger.journals.create']);
    $cash = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    Sanctum::actingAs($maker);
    $rate = $this->postJson('/v1/fx/rates', ['base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-15', 'source' => 'TEST_SOURCE', 'is_override' => false, 'override_reason' => null], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->json('rate_record');

    $this->postJson('/v1/journals', ['entry_date' => '2026-07-15', 'entry_type' => 'manual', 'lines' => [
        ['account_id' => $cash->id, 'debit' => ['amount' => '99.0000', 'currency' => 'BDT'], 'credit' => null, 'foreign_amount' => ['amount' => '1.0000', 'currency' => 'USD'], 'exchange_rate_reference' => ['rate_record_id' => $rate['id'], 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-15']],
        ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '99.0000', 'currency' => 'BDT'], 'foreign_amount' => null, 'exchange_rate_reference' => null],
    ]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'functional_balance_mismatch');

    $this->postJson('/v1/journals', ['entry_date' => '2026-07-15', 'entry_type' => 'manual', 'lines' => [
        ['account_id' => $cash->id, 'debit' => ['amount' => '100.0000', 'currency' => 'BDT'], 'credit' => null, 'foreign_amount' => ['amount' => '1.0000', 'currency' => 'USD'], 'exchange_rate_reference' => ['rate_record_id' => $rate['id'], 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-15']],
        ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '100.0000', 'currency' => 'BDT'], 'foreign_amount' => null, 'exchange_rate_reference' => null],
    ]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('journal.lines.0.exchange_rate_reference.rate_record_id', $rate['id']);

    expect(RateRecord::query()->whereKey($rate['id'])->exists())->toBeTrue()
        ->and(DB::table('ledger_account_balance_projections')->where('entity_id', $entity->id)->count())->toBe(0)
        ->and(OutboxMessage::query()->where('event_type', 'RateRecordAdded')->exists())->toBeTrue();
});

it('routes configured FX commands through durable maker checker approval', function (): void {
    config()->set('valuation.fx.sources', ['TEST_SOURCE']);
    [$maker, $approver, $entity] = m1Actors(['fx.rates.manage']);
    $entity->approval_policy = ['configured' => true];
    $entity->save();

    Sanctum::actingAs($maker);
    $approval = $this->postJson('/v1/fx/rates', [
        'base_currency' => 'USD',
        'quote_currency' => 'BDT',
        'rate' => '100.00000000',
        'effective_date' => '2026-07-15',
        'source' => 'TEST_SOURCE',
        'is_override' => false,
        'override_reason' => null,
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(202)
        ->assertJsonPath('approval.status', 'pending')
        ->json('approval');
    expect(RateRecord::query()->count())->toBe(0);

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$approval['id'].'/approve', [], [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => (string) Str::uuid(),
        'If-Match' => '1',
    ])->assertOk()->assertJsonPath('command_result.status', 201);

    expect(RateRecord::query()->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->exists())->toBeTrue();
});

it('binds FX cursors to the entity filters and read boundary', function (): void {
    [$maker, , $entity] = m1Actors(['fx.rates.read']);
    foreach (['2026-07-14', '2026-07-15'] as $date) {
        RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => $date, 'source' => 'TEST_SOURCE']);
    }
    Sanctum::actingAs($maker);
    $cursor = $this->getJson('/v1/fx/rates?base_currency=USD&quote_currency=BDT&limit=1', ['X-Entity-Id' => $entity->id])
        ->assertOk()->json('page.next_cursor');

    $this->getJson('/v1/fx/rates?base_currency=EUR&quote_currency=BDT&limit=1&cursor='.urlencode((string) $cursor), ['X-Entity-Id' => $entity->id])
        ->assertBadRequest()->assertJsonPath('error_code', 'validation');
});

it('fails revaluation safely when policy configuration is absent', function (): void {
    [$maker, , $entity] = m1Actors(['fx.revaluation.run']);
    AccountingPeriod::query()->where('entity_id', $entity->id)->update(['state' => 'SoftClosed']);
    Sanctum::actingAs($maker);
    $this->postJson('/v1/fx/revaluation', ['period_ref' => '2026-07'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'missing_period_end_rate');
});

it('accepts only persisted revaluation run statuses in the query', function (): void {
    [$maker, , $entity] = m1Actors(['fx.revaluation.read']);
    Sanctum::actingAs($maker);

    foreach (['posted', 'reversed'] as $status) {
        $this->getJson('/v1/fx/revaluation?period=2026-07&status='.$status, ['X-Entity-Id' => $entity->id])
            ->assertOk()
            ->assertJsonPath('revaluation_runs', []);
    }

    $this->getJson('/v1/fx/revaluation?period=2026-07&status=pending_approval', ['X-Entity-Id' => $entity->id])
        ->assertBadRequest()
        ->assertJsonPath('error_code', 'validation');
});

it('posts and links the configured next-period revaluation reversal', function (): void {
    [$maker, , $entity] = m1Actors(['fx.revaluation.run']);
    AccountingPeriod::query()->where('entity_id', $entity->id)->update(['state' => 'SoftClosed']);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-08', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'state' => 'Open']);
    $bank = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1200', 'name' => 'USD Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active', 'bank_attributes' => ['currency' => 'USD']]);
    $offset = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '7900', 'name' => 'FX Gain', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $source = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->where('period_ref', '2026-07')->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'manual', 'entry_date' => '2026-07-15', 'state' => 'posted', 'posted_by' => $maker->id]);
    $source->lines()->createMany([
        ['entity_id' => $entity->id, 'account_id' => $bank->id, 'line_no' => 1, 'debit' => '100.0000', 'credit' => '0.0000', 'currency' => 'BDT', 'fx_amount' => '1.0000', 'fx_currency' => 'USD'],
        ['entity_id' => $entity->id, 'account_id' => $offset->id, 'line_no' => 2, 'debit' => '0.0000', 'credit' => '100.0000', 'currency' => 'BDT'],
    ]);
    $rate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '110.00000000', 'effective_date' => '2026-07-31', 'source' => 'TEST_SOURCE']);
    config()->set('valuation.fx.source_precedence', ['TEST_SOURCE']);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.unrealised_gain_account_id', $offset->id);
    config()->set('valuation.fx.unrealised_loss_account_id', $offset->id);

    Sanctum::actingAs($maker);
    $run = $this->postJson('/v1/fx/revaluation', ['period_ref' => '2026-07'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('revaluation_run.rate_record_ids.0', $rate->id)->json('revaluation_run');

    expect($rate->refresh()->referenced)->toBeTrue()
        ->and(app(FxService::class)->reverseRevaluation($entity->id, $run['id'], $maker->id))->toBeTrue();
    $reversalId = JournalEntry::query()->where('reversal_of_entry_id', $run['journal_entry_ids'][0])->value('id');
    $originalLines = JournalEntry::query()->with('lines')->findOrFail($run['journal_entry_ids'][0])->lines->sortBy('line_no')->values();
    $reversalLines = JournalEntry::query()->with('lines')->findOrFail($reversalId)->lines->sortBy('line_no')->values();
    expect($reversalId)->not->toBeNull()
        ->and($reversalLines->pluck('debit')->all())->toBe($originalLines->pluck('credit')->all())
        ->and($reversalLines->pluck('credit')->all())->toBe($originalLines->pluck('debit')->all())
        ->and(OutboxMessage::query()->where('event_type', 'RevaluationReversed')->exists())->toBeTrue();

    // A second reversal attempt on the same run is rejected — it is no longer 'scheduled'.
    expect(app(FxService::class)->reverseRevaluation($entity->id, $run['id'], $maker->id))->toBeFalse()
        ->and(OutboxMessage::query()->where('event_type', 'RevaluationReversed')->count())->toBe(1);
});

it('protects posted FX revaluation run facts from mutation while allowing its own reversal-lifecycle fields, in PostgreSQL', function (): void {
    [, , $entity] = m1Actors([]);
    $run = RevaluationRun::query()->create([
        'entity_id' => $entity->id, 'period_ref' => '2026-07', 'status' => 'posted',
        'figures' => [['account_id' => (string) Str::uuid(), 'amount' => ['amount' => '25.0000', 'currency' => 'BDT']]],
        'rate_record_ids' => [], 'journal_entry_ids' => [], 'reversal_status' => 'scheduled',
        'target_period_ref' => '2026-08', 'reversal_journal_entry_ids' => [], 'version' => 1,
    ]);

    // Whitelisted reversal-lifecycle fields remain mutable after posting.
    DB::transaction(fn () => DB::table('fx_revaluation_runs')->where('id', $run->id)->update(['journal_entry_ids' => json_encode([(string) Str::uuid()]), 'version' => 2]));
    expect(DB::table('fx_revaluation_runs')->where('id', $run->id)->value('version'))->toBe(2);

    // The posted fact itself (figures) is immutable.
    expect(fn () => DB::transaction(fn () => DB::table('fx_revaluation_runs')->where('id', $run->id)->update(['figures' => json_encode([['tampered' => true]])])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('fx_revaluation_runs')->where('id', $run->id)->delete()))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL trigger validation.');

it('routes configured journal reversal through durable maker checker approval', function (): void {
    [$maker, $approver, $entity] = m1Actors(['ledger.journals.create', 'ledger.journals.post', 'ledger.journals.reverse']);
    $entity->approval_policy = ['configured' => true];
    $entity->save();
    $cash = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4100', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    Sanctum::actingAs($maker);
    $journal = $this->postJson('/v1/journals', ['entry_date' => '2026-07-15', 'lines' => [['account_id' => $cash->id, 'debit' => ['amount' => '10.0000', 'currency' => 'BDT'], 'credit' => null], ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '10.0000', 'currency' => 'BDT']]]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('journal');
    $this->postJson('/v1/journals/'.$journal['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();
    $approval = $this->postJson('/v1/journals/'.$journal['id'].'/reverse', ['entry_date' => '2026-07-16', 'reason' => 'Approved correction'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(202)->json('approval');
    expect(JournalEntry::query()->where('reversal_of_entry_id', $journal['id'])->exists())->toBeFalse();
    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$approval['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk()->assertJsonPath('command_result.status', 201);
    expect(JournalEntry::query()->where('reversal_of_entry_id', $journal['id'])->count())->toBe(1);
});
