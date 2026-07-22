<?php

namespace App\Models\Period;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property int $id
 * @property string $period_id
 * @property string|null $from_state
 * @property string $to_state
 * @property string|null $reason_code
 * @property string|null $narrative
 * @property string|null $vat_status_before
 * @property string|null $vat_status_after
 * @property int|null $version_before
 * @property int|null $version_after
 * @property string|null $actor_id
 * @property string|null $approver_id
 * @property string|null $approval_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property bool $reclose_required
 * @property Carbon $transitioned_at
 */
final class PeriodTransition extends Model
{
    protected $fillable = [
        'period_id', 'from_state', 'to_state', 'reason_code', 'narrative',
        'vat_status_before', 'vat_status_after', 'version_before', 'version_after',
        'actor_id', 'approver_id', 'approval_id', 'correlation_id', 'causation_id',
        'reclose_required', 'transitioned_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'transitioned_at' => 'immutable_datetime',
            'version_before' => 'integer',
            'version_after' => 'integer',
            'reclose_required' => 'boolean',
        ];
    }
}
