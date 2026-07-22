<?php

namespace App\Models\Reporting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $report_type
 * @property string|null $period_ref
 * @property Carbon|null $as_of
 * @property Carbon|null $range_from
 * @property Carbon|null $range_to
 * @property string $basis
 * @property string $functional_currency
 * @property array<string, mixed> $filters
 * @property int|null $layout_version
 * @property int|null $classification_version
 * @property int|null $policy_version
 * @property Carbon $source_data_watermark
 * @property array<string, mixed> $content
 * @property string $content_hash
 * @property string $generated_by
 * @property Carbon $generated_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string $state
 * @property int $version
 * @property string|null $superseded_by_report_run_id
 */
final class ReportRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id', 'report_type', 'period_ref', 'as_of', 'range_from', 'range_to', 'basis', 'functional_currency',
        'filters', 'layout_version', 'classification_version', 'policy_version', 'source_data_watermark',
        'content', 'content_hash', 'generated_by', 'generated_at', 'reviewed_by', 'reviewed_at',
        'approved_by', 'approved_at', 'state', 'version', 'superseded_by_report_run_id',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'as_of' => 'immutable_date', 'range_from' => 'immutable_date', 'range_to' => 'immutable_date',
            'filters' => 'array', 'layout_version' => 'integer', 'classification_version' => 'integer', 'policy_version' => 'integer',
            'source_data_watermark' => 'immutable_datetime', 'content' => 'array', 'generated_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'version' => 'integer',
        ];
    }
}
