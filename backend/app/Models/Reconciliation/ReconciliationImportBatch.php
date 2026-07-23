<?php

namespace App\Models\Reconciliation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $reconciliation_id
 * @property string $reconciliation_account_id
 * @property string $file_hash
 * @property array<string, mixed>|null $column_mapping
 * @property string $imported_by
 * @property Carbon $imported_at
 * @property int $line_count
 */
final class ReconciliationImportBatch extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['reconciliation_id', 'reconciliation_account_id', 'file_hash', 'column_mapping', 'imported_by', 'imported_at', 'line_count'];

    #[Override]
    protected function casts(): array
    {
        return ['column_mapping' => 'array', 'imported_at' => 'immutable_datetime', 'line_count' => 'integer'];
    }
}
