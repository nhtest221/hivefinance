<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $party_type
 * @property string $party_id
 * @property string $currency
 * @property string $original_amount
 * @property string $remaining_amount
 * @property string $original_functional_amount
 * @property string $remaining_functional_amount
 * @property string|null $source_rate_record_id
 * @property array<string,mixed>|null $source_exchange_rate_reference
 * @property string $source_allocation_id
 * @property string|null $source_reference
 * @property int $version
 */
final class CreditTranche extends Model
{
    use HasUuids;

    protected $table = 'settlement_credit_tranches';

    protected $fillable = ['entity_id', 'party_type', 'party_id', 'currency', 'original_amount', 'remaining_amount', 'original_functional_amount', 'remaining_functional_amount', 'source_rate_record_id', 'source_exchange_rate_reference', 'source_allocation_id', 'source_reference', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['original_amount' => ExactDecimalCast::class, 'remaining_amount' => ExactDecimalCast::class, 'original_functional_amount' => ExactDecimalCast::class, 'remaining_functional_amount' => ExactDecimalCast::class, 'source_exchange_rate_reference' => 'array', 'version' => 'integer'];
    }
}
