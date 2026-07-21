<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $credit_tranche_id
 * @property string $allocation_id
 * @property string $operation
 * @property string $amount
 * @property string $functional_amount
 * @property string|null $source_rate_record_id
 * @property string|null $comparison_rate_record_id
 * @property string|null $document_id
 * @property string|null $reverses_consumption_id
 */
final class CreditConsumption extends Model
{
    use HasUuids;

    protected $table = 'settlement_credit_consumptions';

    protected $fillable = ['entity_id', 'credit_tranche_id', 'allocation_id', 'operation', 'amount', 'functional_amount', 'source_rate_record_id', 'comparison_rate_record_id', 'document_id', 'reverses_consumption_id', 'occurred_at'];

    #[Override]
    protected function casts(): array
    {
        return ['amount' => ExactDecimalCast::class, 'functional_amount' => ExactDecimalCast::class, 'occurred_at' => 'datetime'];
    }
}
