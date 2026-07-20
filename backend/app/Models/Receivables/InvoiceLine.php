<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $description
 * @property string $quantity
 * @property string $unit_price
 * @property string|null $tax_code_id
 * @property array<string,mixed>|null $tax_snapshot
 * @property string $line_amount
 * @property string $tax_amount
 * @property string $total_amount
 */
final class InvoiceLine extends Model
{
    use HasUuids;

    protected $table = 'receivables_invoice_lines';

    protected $fillable = ['invoice_id', 'entity_id', 'line_no', 'description', 'quantity', 'unit_price', 'tax_code_id', 'tax_snapshot', 'line_amount', 'tax_amount', 'total_amount'];

    #[Override]
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_snapshot' => 'array', 'line_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4'];
    }
}
