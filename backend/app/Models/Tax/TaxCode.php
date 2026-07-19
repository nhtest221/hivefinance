<?php

namespace App\Models\Tax;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $jurisdiction
 * @property string $status
 * @property int $version
 */
final class TaxCode extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'code', 'name', 'jurisdiction', 'status', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** @return HasMany<TaxCodeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(TaxCodeVersion::class)->orderBy('effective_from')->orderBy('version_number');
    }
}
