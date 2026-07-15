<?php

namespace App\Models\Ledger;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $journal_entry_id
 * @property string $entity_id
 * @property string $account_id
 * @property int $line_no
 * @property string|null $description
 * @property string $debit
 * @property string $credit
 * @property string $currency
 * @property string|null $fx_amount
 * @property string|null $rate_record_id
 * @property string|null $sbu_tag
 * @property JournalEntry $journalEntry
 */
final class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'entity_id',
        'account_id',
        'line_no',
        'description',
        'debit',
        'credit',
        'currency',
        'fx_amount',
        'rate_record_id',
        'sbu_tag',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'fx_amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'account_id');
    }
}
