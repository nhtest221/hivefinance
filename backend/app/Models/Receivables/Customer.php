<?php

namespace App\Models\Receivables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $name
 * @property string $type
 * @property string|null $jurisdiction
 * @property string|null $tax_identifier
 * @property string $default_currency
 * @property string $payment_terms
 * @property array<string,mixed>|null $contact
 * @property array<string,mixed>|null $address
 * @property string $status
 * @property int $version
 */
final class Customer extends Model
{
    use HasUuids;

    protected $table = 'receivables_customers';

    protected $fillable = ['entity_id', 'name', 'normalized_name', 'type', 'jurisdiction', 'tax_identifier', 'normalized_tax_identifier', 'default_currency', 'payment_terms', 'contact', 'address', 'status', 'version', 'created_by'];

    #[Override]
    protected function casts(): array
    {
        return ['contact' => 'array', 'address' => 'array', 'version' => 'integer'];
    }
}
