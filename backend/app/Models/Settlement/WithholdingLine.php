<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $withholding_code
 * @property string $amount
 * @property array<string,mixed>|null $tax_snapshot
 * @property array<string,mixed>|null $configuration_reference
 * @property string $account_id
 */
final class WithholdingLine extends Model
{
    use HasUuids;

    protected $table = 'settlement_withholding_lines';

    protected $fillable = ['entity_id', 'allocation_id', 'withholding_code', 'amount', 'tax_snapshot', 'configuration_reference', 'account_id'];

    #[Override]
    protected function casts(): array
    {
        return ['amount' => ExactDecimalCast::class, 'tax_snapshot' => 'array', 'configuration_reference' => 'array'];
    }
}
