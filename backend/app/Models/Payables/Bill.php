<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $vendor_id
 * @property string|null $document_number
 * @property string|null $provisional_token
 * @property string|null $vendor_reference
 * @property string|null $notes
 * @property Carbon $bill_date
 * @property Carbon $due_date
 * @property string $currency
 * @property string|null $rate_record_id
 * @property array<string,mixed>|null $exchange_rate_reference
 * @property string|null $ait
 * @property string|null $vds
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $open_balance
 * @property string $status
 * @property string|null $journal_entry_id
 * @property int $version
 * @property string $created_by
 * @property Collection<int,BillLine> $lines
 * @property Collection<int,BillSbuAllocation> $sbuAllocations
 */
final class Bill extends Model
{
    use HasUuids;

    protected $table = 'payables_bills';

    protected $fillable = ['entity_id', 'document_number', 'provisional_token', 'vendor_id', 'vendor_reference', 'notes', 'bill_date', 'due_date', 'currency', 'rate_record_id', 'exchange_rate_reference', 'ait', 'vds', 'subtotal', 'tax_total', 'total', 'open_balance', 'status', 'journal_entry_id', 'version', 'created_by', 'approved_by', 'approved_at'];

    #[Override]
    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_date' => 'date', 'exchange_rate_reference' => 'array', 'ait' => 'decimal:4', 'vds' => 'decimal:4', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'open_balance' => 'decimal:4', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    /** @return HasMany<BillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class, 'bill_id')->orderBy('line_no');
    }

    /** @return HasMany<BillSbuAllocation, $this> */
    public function sbuAllocations(): HasMany
    {
        return $this->hasMany(BillSbuAllocation::class, 'bill_id')->orderBy('sbu_code');
    }
}
