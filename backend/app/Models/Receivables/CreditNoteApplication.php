<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $disposition_id
 * @property string $entity_id
 * @property string $target_document_id
 * @property string $amount
 * @property string $functional_amount
 * @property int $target_version_before
 * @property int $target_version_after
 * @property string|null $source_rate_record_id
 * @property string|null $comparison_rate_record_id
 * @property string|null $reversal_of_id
 */
final class CreditNoteApplication extends Model
{
    use HasUuids;

    protected $table = 'receivables_credit_note_applications';

    protected $fillable = [
        'credit_note_id', 'disposition_id', 'entity_id', 'target_document_id', 'amount', 'functional_amount',
        'target_version_before', 'target_version_after', 'source_rate_record_id', 'comparison_rate_record_id', 'reversal_of_id',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'functional_amount' => 'decimal:4',
            'target_version_before' => 'integer',
            'target_version_after' => 'integer',
        ];
    }
}
