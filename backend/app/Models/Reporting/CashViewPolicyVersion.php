<?php

namespace App\Models\Reporting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property int $version_number
 * @property array<string, mixed> $policy
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class CashViewPolicyVersion extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'version_number', 'policy', 'effective_from', 'effective_to'];

    #[Override]
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'policy' => 'array', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
