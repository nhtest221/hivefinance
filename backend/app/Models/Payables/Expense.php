<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property Carbon $expense_date
 * @property string $description
 * @property string|null $vendor_id
 * @property string $category_account_id
 * @property string $settlement_type
 * @property string|null $bank_account_id
 * @property string $currency
 * @property string $amount
 * @property string|null $tax_code_id
 * @property array<string,mixed>|null $tax_snapshot
 * @property string|null $ait
 * @property list<array<string,mixed>> $sbu_allocations
 * @property array<string,mixed>|null $exchange_rate_reference
 * @property string $journal_entry_id
 * @property string $status
 * @property int $version
 * @property Carbon $recorded_at
 */
final class Expense extends Model
{
    use HasUuids;

    protected $table = 'payables_expenses';

    protected $fillable = ['entity_id', 'expense_date', 'description', 'vendor_id', 'category_account_id', 'settlement_type', 'bank_account_id', 'currency', 'amount', 'tax_code_id', 'tax_snapshot', 'ait', 'sbu_allocations', 'rate_record_id', 'exchange_rate_reference', 'journal_entry_id', 'status', 'version', 'created_by', 'recorded_at'];

    #[Override]
    protected function casts(): array
    {
        return ['expense_date' => 'date', 'amount' => 'decimal:4', 'tax_snapshot' => 'array', 'ait' => 'decimal:4', 'sbu_allocations' => 'array', 'exchange_rate_reference' => 'array', 'recorded_at' => 'datetime', 'version' => 'integer'];
    }
}
