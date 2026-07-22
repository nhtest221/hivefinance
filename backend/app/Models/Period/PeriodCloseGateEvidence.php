<?php

namespace App\Models\Period;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $period_id
 * @property string $close_attempt_id
 * @property string $gate_type
 * @property string $status
 * @property string|null $source_context
 * @property string|null $source_reference
 * @property Carbon|null $produced_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property int|null $evidence_version
 * @property string|null $evidence_hash
 * @property string|null $accepted_set_hash
 * @property Carbon|null $accepted_at
 */
final class PeriodCloseGateEvidence extends Model
{
    use HasUuids;

    protected $table = 'period_close_gate_evidence';

    protected $fillable = [
        'entity_id', 'period_id', 'close_attempt_id', 'gate_type', 'status',
        'source_context', 'source_reference', 'produced_at', 'reviewed_by', 'reviewed_at',
        'evidence_version', 'evidence_hash', 'accepted_set_hash', 'accepted_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'produced_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'evidence_version' => 'integer',
        ];
    }
}
