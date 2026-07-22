<?php

namespace App\Models\Reporting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $report_type
 * @property int $version_number
 * @property list<array<string, mixed>> $sections
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 */
final class ReportLayoutVersion extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'report_type', 'version_number', 'sections', 'effective_from', 'effective_to'];

    #[Override]
    protected function casts(): array
    {
        return ['version_number' => 'integer', 'sections' => 'array', 'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date'];
    }
}
