<?php

namespace App\Models\Ledger;

use App\Models\Identity\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $code
 * @property string $name
 * @property string $type
 * @property string $normal_balance
 * @property string $status
 * @property int $version
 */
final class LedgerAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'status',
        'bank_attributes',
        'parent_account_id',
        'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'bank_attributes' => 'array',
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

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function journalLines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }
}
