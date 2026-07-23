<?php

namespace App\Models\Reconciliation;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $statement_line_id
 * @property int $rank
 * @property string $total_amount
 * @property string $currency
 * @property bool $reference_match
 * @property bool $superseded
 */
final class ReconciliationMatchSuggestion extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['statement_line_id', 'rank', 'total_amount', 'currency', 'reference_match', 'superseded'];

    #[Override]
    protected function casts(): array
    {
        return ['rank' => 'integer', 'total_amount' => ExactDecimalCast::class, 'reference_match' => 'boolean', 'superseded' => 'boolean'];
    }
}
