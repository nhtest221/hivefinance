<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $entity_id
 * @property string $operation
 * @property string $amount
 * @property string $functional_amount
 * @property Carbon $occurred_on
 * @property string $actor_id
 * @property string|null $correlation_id
 * @property string|null $causation_id
 * @property string|null $settlement_allocation_id
 * @property array<int,string>|null $credit_tranche_ids
 * @property string|null $reverses_disposition_id
 * @property Carbon $created_at
 * @property Collection<int,CreditNoteApplication> $applications
 */
final class CreditNoteDisposition extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'receivables_credit_note_dispositions';

    protected $fillable = [
        'credit_note_id', 'entity_id', 'operation', 'amount', 'functional_amount', 'occurred_on',
        'actor_id', 'correlation_id', 'causation_id', 'settlement_allocation_id', 'credit_tranche_ids',
        'reverses_disposition_id', 'created_at',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'functional_amount' => 'decimal:4',
            'occurred_on' => 'date',
            'credit_tranche_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<CreditNoteApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(CreditNoteApplication::class, 'disposition_id');
    }
}
