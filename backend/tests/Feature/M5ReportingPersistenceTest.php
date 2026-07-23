<?php

use App\Models\Identity\Entity;
use App\Models\Reporting\AccountClassificationVersion;
use App\Models\Reporting\AgeingBucketSetVersion;
use App\Models\Reporting\CashViewPolicyVersion;
use App\Models\Reporting\ReportLayoutVersion;
use App\Reporting\Application\AccountClassificationProvider;
use App\Reporting\Application\AgeingBucketProvider;
use App\Reporting\Application\CashViewPolicyProvider;
use App\Reporting\Application\ReportLayoutProvider;
use App\Reporting\Application\ReportRunRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function m5Entity(): Entity
{
    return Entity::query()->create(['legal_name' => 'M5 Reporting '.Str::uuid(), 'functional_currency' => 'BDT']);
}

/** @return array<string, mixed> */
function m5ReportRunAttributes(Entity $entity): array
{
    return [
        'entity_id' => $entity->id, 'report_type' => 'trial_balance', 'period_ref' => null, 'as_of' => '2026-07-31',
        'range_from' => null, 'range_to' => null, 'basis' => 'accrual', 'functional_currency' => 'BDT', 'filters' => [],
        'layout_version' => null, 'classification_version' => null, 'policy_version' => null,
        'source_data_watermark' => '2026-07-31T18:00:00Z', 'content' => ['as_of' => '2026-07-31', 'rows' => [], 'totals' => ['debit' => '0.0000', 'credit' => '0.0000', 'balanced' => true]],
        'content_hash' => hash('sha256', 'seed'), 'generated_by' => (string) Str::uuid(), 'generated_at' => now('UTC'),
    ];
}

it('creates a generated ReportRun and keeps it entity-isolated', function (): void {
    $entityA = m5Entity();
    $entityB = m5Entity();
    $repo = app(ReportRunRepository::class);

    $run = $repo->addGenerated(m5ReportRunAttributes($entityA));

    expect($run->state)->toBe('Generated')->and($run->version)->toBe(1)
        ->and($repo->getById($entityA->id, $run->id))->not->toBeNull()
        ->and($repo->getById($entityB->id, $run->id))->toBeNull();
});

it('enforces optimistic concurrency on ReportRun approval and rejection', function (): void {
    $entity = m5Entity();
    $repo = app(ReportRunRepository::class);
    $run = $repo->addGenerated(m5ReportRunAttributes($entity));

    $stale = $repo->commitApproval($entity->id, $run->id, ['state' => 'Approved', 'approved_by' => (string) Str::uuid(), 'approved_at' => now('UTC'), 'reviewed_by' => (string) Str::uuid(), 'reviewed_at' => now('UTC')], 99);
    expect($stale)->toBeNull();

    $approved = $repo->commitApproval($entity->id, $run->id, ['state' => 'Approved', 'approved_by' => (string) Str::uuid(), 'approved_at' => now('UTC'), 'reviewed_by' => (string) Str::uuid(), 'reviewed_at' => now('UTC')], 1);
    expect($approved)->not->toBeNull()->and($approved->state)->toBe('Approved')->and($approved->version)->toBe(2);

    $run2 = $repo->addGenerated(m5ReportRunAttributes($entity));
    $rejected = $repo->commitRejection($entity->id, $run2->id, 1);
    expect($rejected->state)->toBe('Rejected')->and($rejected->version)->toBe(2);
});

it('finds only the current approved run for an exact reproducibility key and supersedes atomically', function (): void {
    $entity = m5Entity();
    $repo = app(ReportRunRepository::class);
    $first = $repo->addGenerated(m5ReportRunAttributes($entity));
    $repo->commitApproval($entity->id, $first->id, ['state' => 'Approved', 'approved_by' => (string) Str::uuid(), 'approved_at' => now('UTC'), 'reviewed_by' => (string) Str::uuid(), 'reviewed_at' => now('UTC')], 1);

    $found = $repo->findCurrentApproved($entity->id, 'trial_balance', 'accrual', null, '2026-07-31', []);
    expect($found?->id)->toBe($first->id);

    $second = $repo->addGenerated(m5ReportRunAttributes($entity));
    $repo->commitApproval($entity->id, $second->id, ['state' => 'Approved', 'approved_by' => (string) Str::uuid(), 'approved_at' => now('UTC'), 'reviewed_by' => (string) Str::uuid(), 'reviewed_at' => now('UTC')], 1);
    $repo->commitSupersession($entity->id, $first->id, $second->id, 2);

    $refound = $repo->findCurrentApproved($entity->id, 'trial_balance', 'accrual', null, '2026-07-31', []);
    expect($refound?->id)->toBe($second->id);
    $stale = $repo->getById($entity->id, $first->id);
    expect($stale->state)->toBe('Superseded')->and($stale->superseded_by_report_run_id)->toBe($second->id);
});

it('resolves the latest effective versioned configuration per entity and date', function (): void {
    $entityA = m5Entity();
    $entityB = m5Entity();
    ReportLayoutVersion::query()->create(['entity_id' => $entityA->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [['section_id' => 'sales_revenue']], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    ReportLayoutVersion::query()->create(['entity_id' => $entityA->id, 'report_type' => 'profit_and_loss', 'version_number' => 2, 'sections' => [['section_id' => 'sales_revenue_v2']], 'effective_from' => '2026-08-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entityA->id, 'version_number' => 1, 'entries' => [['account_id' => (string) Str::uuid(), 'code' => '4010', 'classification' => 'sales_revenue']], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AgeingBucketSetVersion::query()->create(['entity_id' => $entityA->id, 'version_number' => 1, 'buckets' => [['bucket_id' => 'not_due', 'label' => 'Not Due', 'lower_days' => null, 'upper_days' => -1, 'order' => 1]], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    CashViewPolicyVersion::query()->create(['entity_id' => $entityA->id, 'version_number' => 1, 'policy' => ['recognition_date_source' => 'settlement_date'], 'effective_from' => '2026-01-01', 'effective_to' => null]);

    $layout = app(ReportLayoutProvider::class)->getEffective($entityA->id, 'profit_and_loss', '2026-07-01');
    expect($layout?->versionNumber)->toBe(1);
    $latest = app(ReportLayoutProvider::class)->getEffective($entityA->id, 'profit_and_loss', '2026-09-01');
    expect($latest?->versionNumber)->toBe(2);
    expect(app(ReportLayoutProvider::class)->getEffective($entityB->id, 'profit_and_loss', '2026-09-01'))->toBeNull();

    $classification = app(AccountClassificationProvider::class)->getEffective($entityA->id, '2026-07-01');
    expect($classification?->versionNumber)->toBe(1)->and($classification->classify('unknown-id'))->toBeNull();

    $buckets = app(AgeingBucketProvider::class)->getEffective($entityA->id, '2026-07-01');
    expect($buckets?->versionNumber)->toBe(1)->and($buckets->bucketFor(-5))->toBe('not_due');

    $policy = app(CashViewPolicyProvider::class)->getEffective($entityA->id, '2026-07-01');
    expect($policy?->versionNumber)->toBe(1);
});

it('protects posted ReportRun facts and configuration versions from mutation in PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only immutability guard.');
    }
    $entity = m5Entity();
    $repo = app(ReportRunRepository::class);
    $run = $repo->addGenerated(m5ReportRunAttributes($entity));

    expect(fn () => DB::transaction(fn () => DB::table('report_runs')->where('id', $run->id)->update(['content' => json_encode(['tampered' => true])])))
        ->toThrow(QueryException::class);

    $layout = ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    expect(fn () => DB::transaction(fn () => DB::table('report_layout_versions')->where('id', $layout->id)->update(['sections' => json_encode([['x' => 1]])])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('report_layout_versions')->where('id', $layout->id)->delete()))
        ->toThrow(QueryException::class);
});
