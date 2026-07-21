<?php

namespace App\Models\Settlement;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $allocation_id
 * @property string $document_type
 * @property string $document_id
 * @property string $document_number
 * @property string $document_party_id
 * @property string|null $credit_tranche_id
 * @property string $applied_amount
 * @property int $expected_version
 * @property string $open_balance_before
 * @property string $open_balance_after
 * @property int $version_before
 * @property int $version_after
 * @property string $status_before
 * @property string $status_after
 * @property string|null $document_rate_record_id
 * @property array<string,mixed>|null $realised_fx_result
 */
final class AllocationLink extends Model
{
    use HasUuids;

    protected $table = 'settlement_allocation_links';

    protected $fillable = ['entity_id', 'allocation_id', 'document_type', 'document_id', 'document_number', 'document_party_id', 'credit_tranche_id', 'applied_amount', 'expected_version', 'open_balance_before', 'open_balance_after', 'version_before', 'version_after', 'status_before', 'status_after', 'document_rate_record_id', 'realised_fx_result'];

    #[Override]
    protected function casts(): array
    {
        return ['applied_amount' => ExactDecimalCast::class, 'open_balance_before' => ExactDecimalCast::class, 'open_balance_after' => ExactDecimalCast::class, 'expected_version' => 'integer', 'version_before' => 'integer', 'version_after' => 'integer', 'realised_fx_result' => 'array'];
    }
}
