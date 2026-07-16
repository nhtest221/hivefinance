<?php

namespace App\Models\CurrencyFx;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $period_ref
 * @property string $status
 * @property array<int, mixed> $figures
 * @property array<int, string> $rate_record_ids
 * @property array<int, string> $journal_entry_ids
 * @property string $reversal_status
 * @property string|null $target_period_ref
 * @property string|null $reversal_run_id
 * @property array<int, string> $reversal_journal_entry_ids
 * @property Carbon|null $posted_at
 * @property Carbon|null $reversed_at
 * @property int $version
 */
final class RevaluationRun extends Model
{
    use HasUuids;

    protected $table = 'fx_revaluation_runs';

    protected $fillable = ['entity_id', 'period_ref', 'status', 'figures', 'rate_record_ids', 'journal_entry_ids', 'reversal_status', 'target_period_ref', 'reversal_run_id', 'reversal_journal_entry_ids', 'posted_at', 'reversed_at', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['figures' => 'array', 'rate_record_ids' => 'array', 'journal_entry_ids' => 'array', 'reversal_journal_entry_ids' => 'array', 'posted_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime', 'version' => 'integer'];
    }
}
