<?php

namespace App\Models\Period;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

final class FiscalCalendar extends Model
{
    use HasUuids;

    protected $fillable = ['entity_id', 'year_start', 'period_defs', 'version'];

    #[Override]
    protected function casts(): array
    {
        return ['year_start' => 'immutable_date', 'period_defs' => 'array', 'version' => 'integer'];
    }
}
