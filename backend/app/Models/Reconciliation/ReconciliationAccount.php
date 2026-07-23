<?php

namespace App\Models\Reconciliation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $ledger_account_id
 * @property string $currency
 * @property string $display_name
 * @property string|null $masked_bank_identifier
 * @property bool $reconciliation_enabled
 * @property array<string, mixed>|null $column_mapping
 * @property int $version
 */
final class ReconciliationAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id', 'ledger_account_id', 'currency', 'display_name', 'masked_bank_identifier',
        'reconciliation_enabled', 'column_mapping', 'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return ['reconciliation_enabled' => 'boolean', 'column_mapping' => 'array', 'version' => 'integer'];
    }
}
