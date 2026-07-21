<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string|null $allocation_number
 * @property string $operation
 * @property string $party_type
 * @property string $party_id
 * @property Carbon $settlement_date
 * @property string|null $bank_account_id
 * @property string $currency
 * @property string $gross_amount
 * @property string $bank_amount
 * @property string $withholding_amount
 * @property string $allocated_amount
 * @property string $unapplied_amount
 * @property string $functional_gross_amount
 * @property string|null $rate_record_id
 * @property array<string,mixed>|null $exchange_rate_reference
 * @property array<int,string> $journal_entry_ids
 * @property string $state
 * @property string|null $reversal_of_id
 * @property string|null $reversed_by_id
 * @property int $version
 * @property Carbon $posted_at
 * @property Collection<int,AllocationLink> $links
 * @property Collection<int,WithholdingLine> $withholdingLines
 */
final class Allocation extends Model
{
    use HasUuids;

    protected $table = 'settlement_allocations';

    protected $fillable = ['entity_id', 'allocation_number', 'operation', 'party_type', 'party_id', 'settlement_date', 'bank_account_id', 'currency', 'gross_amount', 'bank_amount', 'withholding_amount', 'allocated_amount', 'unapplied_amount', 'functional_gross_amount', 'rate_record_id', 'exchange_rate_reference', 'journal_entry_ids', 'state', 'reversal_of_id', 'reversed_by_id', 'version', 'created_by', 'posted_at'];

    #[Override]
    protected function casts(): array
    {
        return ['settlement_date' => 'date', 'gross_amount' => ExactDecimalCast::class, 'bank_amount' => ExactDecimalCast::class, 'withholding_amount' => ExactDecimalCast::class, 'allocated_amount' => ExactDecimalCast::class, 'unapplied_amount' => ExactDecimalCast::class, 'functional_gross_amount' => ExactDecimalCast::class, 'exchange_rate_reference' => 'array', 'journal_entry_ids' => 'array', 'version' => 'integer', 'posted_at' => 'datetime'];
    }

    /** @return HasMany<AllocationLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(AllocationLink::class, 'allocation_id');
    }

    /** @return HasMany<WithholdingLine, $this> */
    public function withholdingLines(): HasMany
    {
        return $this->hasMany(WithholdingLine::class, 'allocation_id');
    }
}
