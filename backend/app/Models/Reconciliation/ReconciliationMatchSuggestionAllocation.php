<?php

namespace App\Models\Reconciliation;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $suggestion_id
 * @property string $allocation_id
 */
final class ReconciliationMatchSuggestionAllocation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['suggestion_id', 'allocation_id'];
}
