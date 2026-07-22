<?php

namespace App\Models\Period;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $original_period_id
 * @property string $posting_period_id
 * @property string|null $source_document_id
 * @property string|null $correction_id
 * @property string|null $journal_entry_id
 * @property string $reason_code
 * @property string|null $tax_snapshot_hash
 * @property string|null $rate_record_hash
 * @property Carbon $occurred_at
 */
final class PeriodLateAdjustmentLink extends Model
{
    use HasUuids;

    protected $table = 'period_late_adjustment_links';

    protected $fillable = [
        'entity_id', 'original_period_id', 'posting_period_id', 'source_document_id',
        'correction_id', 'journal_entry_id', 'reason_code', 'tax_snapshot_hash',
        'rate_record_hash', 'occurred_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
