<?php

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $jurisdiction
 * @property string $name
 * @property array<int, string> $tax_code_ids
 * @property int $version
 */
final class TaxPack extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'jurisdiction', 'name', 'tax_code_ids', 'return_template', 'policy', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['tax_code_ids' => 'array', 'return_template' => 'array', 'policy' => 'array', 'version' => 'integer'];
    }
}
