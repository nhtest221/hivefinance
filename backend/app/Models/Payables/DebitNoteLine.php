<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property string $debit_note_id
 * @property string $entity_id
 * @property string $source_line_id
 * @property string|null $expense_account_id
 * @property int $line_no
 * @property string|null $description
 * @property string $net_amount
 * @property array<string,mixed>|null $tax_snapshot
 * @property string $tax_amount
 * @property string $total_amount
 */
final class DebitNoteLine extends Model
{
    use HasUuids;

    protected $table = 'payables_debit_note_lines';

    protected $fillable = ['debit_note_id', 'entity_id', 'source_line_id', 'expense_account_id', 'line_no', 'description', 'net_amount', 'tax_snapshot', 'tax_amount', 'total_amount'];

    #[Override]
    protected function casts(): array
    {
        return ['net_amount' => 'decimal:4', 'tax_snapshot' => 'array', 'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4'];
    }

    /** @return BelongsTo<DebitNote, $this> */
    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
    }
}
