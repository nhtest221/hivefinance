<?php

namespace App\Models\Payables;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/** @property string $sbu_code @property string $weight */
final class BillSbuAllocation extends Model
{
    use HasUuids;

    protected $table = 'payables_bill_sbu_allocations';

    protected $fillable = ['bill_id', 'entity_id', 'sbu_code', 'weight'];

    #[Override]
    protected function casts(): array
    {
        return ['weight' => 'decimal:4'];
    }
}
