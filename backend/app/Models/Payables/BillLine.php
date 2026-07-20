<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/** @property string $id @property string $description @property string $quantity @property string $unit_price @property string $expense_account_id @property string|null $tax_code_id @property array<string,mixed>|null $tax_snapshot @property string $line_amount @property string $tax_amount @property string $total_amount */
final class BillLine extends Model
{
    use HasUuids;

    protected $table = 'payables_bill_lines';

    protected $fillable = ['bill_id', 'entity_id', 'line_no', 'description', 'quantity', 'unit_price', 'expense_account_id', 'tax_code_id', 'tax_snapshot', 'line_amount', 'tax_amount', 'total_amount'];

    #[Override]
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'tax_snapshot' => 'array', 'line_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4'];
    }
}
