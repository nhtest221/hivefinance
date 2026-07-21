<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $entity_id
 * @property string $party_type
 * @property string $party_id
 * @property string $currency
 * @property string $available_balance
 * @property string $functional_carrying_balance
 * @property int $version
 */
final class PartyCreditBalance extends Model
{
    use HasUuids;

    protected $table = 'settlement_party_credit_balances';

    protected $fillable = ['entity_id', 'party_type', 'party_id', 'currency', 'available_balance', 'functional_carrying_balance', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['available_balance' => ExactDecimalCast::class, 'functional_carrying_balance' => ExactDecimalCast::class, 'version' => 'integer'];
    }
}
