<?php

namespace App\Models\Period;

use App\Models\Identity\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $period_ref
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $state
 * @property string $vat_lock_status
 * @property int $version
 */
final class AccountingPeriod extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'period_ref',
        'starts_on',
        'ends_on',
        'state',
        'vat_lock_status',
        'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }
}
