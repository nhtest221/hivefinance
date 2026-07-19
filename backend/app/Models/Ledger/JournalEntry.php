<?php

namespace App\Models\Ledger;

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
 * @property string $period_ref
 * @property string $entry_type
 * @property string $state
 * @property Carbon $entry_date
 * @property string|null $narration
 * @property string|null $reference
 * @property string|null $reversal_of_entry_id
 * @property Carbon|null $posted_at
 * @property string|null $posted_by
 * @property int $version
 * @property Collection<int, JournalLine> $lines
 */
final class JournalEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id',
        'period_id',
        'period_ref',
        'journal_number',
        'entry_type',
        'entry_date',
        'state',
        'narration',
        'reference',
        'source_document_id',
        'reversal_of_entry_id',
        'posted_at',
        'posted_by',
        'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    /** @return HasOne<JournalEntry, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_entry_id');
    }
}
