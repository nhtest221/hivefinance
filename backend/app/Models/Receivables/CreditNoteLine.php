<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $credit_note_id
 * @property string $entity_id
 * @property string $source_line_id
 * @property int $line_no
 * @property string|null $description
 * @property string $net_amount
 * @property array<string,mixed>|null $tax_snapshot
 * @property string $tax_amount
 * @property string $total_amount
 */
final class CreditNoteLine extends Model
{
    use HasUuids;

    protected $table = 'receivables_credit_note_lines';

    protected $fillable = ['credit_note_id', 'entity_id', 'source_line_id', 'line_no', 'description', 'net_amount', 'tax_snapshot', 'tax_amount', 'total_amount'];

    #[Override]
    protected function casts(): array
    {
        return ['net_amount' => 'decimal:4', 'tax_snapshot' => 'array', 'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4'];
    }
}
