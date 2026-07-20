<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $customer_id
 * @property string|null $document_number
 * @property string|null $provisional_token
 * @property Carbon $invoice_date
 * @property Carbon $due_date
 * @property string $currency
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $payment_instructions_ref
 * @property string|null $rate_record_id
 * @property array<string,mixed>|null $exchange_rate_reference
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string $open_balance
 * @property string $status
 * @property string|null $journal_entry_id
 * @property int $version
 * @property Collection<int,InvoiceLine> $lines
 */
final class Invoice extends Model
{
    use HasUuids;

    protected $table = 'receivables_invoices';

    protected $fillable = ['entity_id', 'document_number', 'provisional_token', 'customer_id', 'invoice_date', 'due_date', 'currency', 'reference', 'notes', 'payment_instructions_ref', 'rate_record_id', 'exchange_rate_reference', 'subtotal', 'tax_total', 'total', 'open_balance', 'status', 'journal_entry_id', 'version', 'created_by'];

    #[Override]
    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'exchange_rate_reference' => 'array', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'open_balance' => 'decimal:4', 'version' => 'integer'];
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class, 'invoice_id')->orderBy('line_no');
    }
}
