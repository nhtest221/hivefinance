<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $name
 * @property string|null $jurisdiction
 * @property string|null $tax_identifier
 * @property string $default_currency
 * @property string $payment_terms
 * @property array<string,mixed>|null $contact
 * @property array<string,mixed>|null $address
 * @property array<string,mixed>|null $bank_details
 * @property string $status
 * @property int $version
 */
final class Vendor extends Model
{
    use HasUuids;

    protected $table = 'payables_vendors';

    protected $fillable = ['entity_id', 'name', 'normalized_name', 'jurisdiction', 'tax_identifier', 'normalized_tax_identifier', 'default_currency', 'payment_terms', 'contact', 'address', 'bank_details', 'status', 'version', 'created_by'];

    #[Override]
    protected function casts(): array
    {
        return ['contact' => 'array', 'address' => 'array', 'bank_details' => 'encrypted:array', 'version' => 'integer'];
    }
}
