<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $entity_id
 * @property Carbon $reversal_date
 * @property string $reason_code
 * @property string $narrative
 * @property string $impact_graph_hash
 * @property array<int,string>|null $journal_entry_ids
 * @property string $actor_id
 * @property Carbon $reversed_at
 */
final class CreditNoteReversal extends Model
{
    use HasUuids;

    protected $table = 'receivables_credit_note_reversals';

    protected $fillable = ['credit_note_id', 'entity_id', 'reversal_date', 'reason_code', 'narrative', 'impact_graph_hash', 'journal_entry_ids', 'actor_id', 'reversed_at'];

    #[Override]
    protected function casts(): array
    {
        return ['reversal_date' => 'date', 'journal_entry_ids' => 'array', 'reversed_at' => 'immutable_datetime'];
    }
}
