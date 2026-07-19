<?php

namespace App\Models\CurrencyFx;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $base_currency
 * @property string $quote_currency
 * @property string $rate
 * @property Carbon $effective_date
 * @property string $source
 * @property bool $is_override
 * @property string|null $override_reason
 * @property bool $referenced
 */
final class RateRecord extends Model
{
    use HasUuids;

    protected $table = 'fx_rate_records';

    protected $fillable = ['entity_id', 'base_currency', 'quote_currency', 'rate', 'effective_date', 'source', 'is_override', 'override_reason', 'referenced'];

    #[Override]
    protected function casts(): array
    {
        return ['rate' => 'decimal:8', 'effective_date' => 'immutable_date', 'is_override' => 'boolean', 'referenced' => 'boolean'];
    }
}
