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
 * @property list<array<string, mixed>> $buckets
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class AgeingBucketSetVersion extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'version_number', 'buckets', 'effective_from', 'effective_to'];

    #[Override]
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'buckets' => 'array', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
