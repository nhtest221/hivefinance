<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string|null $document_number
 * @property string|null $provisional_token
 * @property string $customer_id
 * @property string $source_invoice_id
 * @property int $source_document_expected_version
 * @property Carbon $note_date
 * @property string $currency
 * @property string $reason_code
 * @property string|null $narrative
 * @property string|null $source_rate_record_id
 * @property array<string,mixed>|null $source_exchange_rate_reference
 * @property string|null $proposed_total
 * @property string $posted_amount
 * @property string $applied_amount
 * @property string $refunded_amount
 * @property string $held_remaining_amount
 * @property string $undisposed_amount
 * @property string $state
 * @property string|null $period_ref
 * @property array<int,string>|null $journal_entry_ids
 * @property int $version
 * @property string $created_by
 * @property Collection<int,CreditNoteLine> $lines
 * @property Collection<int,CreditNoteDisposition> $dispositions
 * @property CreditNoteReversal|null $reversal
 */
final class CreditNote extends Model
{
    use HasUuids;

    protected $table = 'receivables_credit_notes';

    protected $fillable = [
        'entity_id', 'document_number', 'provisional_token', 'customer_id', 'source_invoice_id',
        'source_document_expected_version', 'note_date', 'currency', 'reason_code', 'narrative',
        'source_rate_record_id', 'source_exchange_rate_reference', 'proposed_total',
        'posted_amount', 'applied_amount', 'refunded_amount', 'held_remaining_amount', 'undisposed_amount',
        'state', 'period_ref', 'journal_entry_ids', 'version', 'created_by',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'source_exchange_rate_reference' => 'array',
            'proposed_total' => 'decimal:4',
            'posted_amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'refunded_amount' => 'decimal:4',
            'held_remaining_amount' => 'decimal:4',
            'undisposed_amount' => 'decimal:4',
            'journal_entry_ids' => 'array',
            'version' => 'integer',
        ];
    }

    /** @return HasMany<CreditNoteLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class, 'credit_note_id')->orderBy('line_no');
    }

    /** @return HasMany<CreditNoteDisposition, $this> */
    public function dispositions(): HasMany
    {
        return $this->hasMany(CreditNoteDisposition::class, 'credit_note_id')->orderBy('created_at');
    }

    /** @return HasOne<CreditNoteReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(CreditNoteReversal::class, 'credit_note_id');
    }
}
