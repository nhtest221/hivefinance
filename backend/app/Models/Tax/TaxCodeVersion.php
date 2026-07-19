<?php

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property int $version_number
 * @property string $treatment
 * @property string $rate
 * @property bool $recoverable
 * @property string $calculation_method
 * @property array<string, mixed> $gl_mapping
 * @property array<string, mixed> $return_box_mapping
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property bool $referenced
 */
final class TaxCodeVersion extends Model
{
    use HasUuids;

    protected $fillable = ['tax_code_id', 'entity_id', 'version_number', 'treatment', 'rate', 'recoverable', 'calculation_method', 'gl_mapping', 'return_box_mapping', 'effective_from', 'effective_to', 'referenced'];

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $version): void {
            if ($version->getOriginal('referenced') === true || $version->getOriginal('referenced') === 1) {
                throw new \LogicException('Referenced tax code versions are immutable.');
            }
        });
        self::deleting(function (self $version): void {
            if ($version->referenced) {
                throw new \LogicException('Referenced tax code versions are immutable.');
            }
        });
    }

    #[Override]
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'rate' => 'decimal:8', 'recoverable' => 'boolean', 'gl_mapping' => 'array', 'return_box_mapping' => 'array', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date', 'referenced' => 'boolean'];
    }
}
