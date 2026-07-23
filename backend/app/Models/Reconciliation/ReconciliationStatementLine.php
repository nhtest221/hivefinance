<?php

namespace App\Models\Reconciliation;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $reconciliation_id
 * @property string $reconciliation_account_id
 * @property string $import_batch_id
 * @property string $source_line_identity
 * @property Carbon $transaction_date
 * @property string $narration
 * @property string $normalized_narration
 * @property string $amount
 * @property string $currency
 * @property string|null $external_bank_reference
 * @property string $status
 * @property list<string>|null $matched_allocation_ids
 * @property string|null $resolved_by_journal_entry_id
 * @property int $version
 */
final class ReconciliationStatementLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'reconciliation_id', 'reconciliation_account_id', 'import_batch_id', 'source_line_identity',
        'transaction_date', 'narration', 'normalized_narration', 'amount', 'currency', 'external_bank_reference',
        'status', 'matched_allocation_ids', 'resolved_by_journal_entry_id', 'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return ['transaction_date' => 'immutable_date', 'amount' => ExactDecimalCast::class, 'matched_allocation_ids' => 'array', 'version' => 'integer'];
    }
}
