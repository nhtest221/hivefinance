<?php

namespace App\Models\Reconciliation;

use App\Support\Documents\ExactDecimalCast;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $reconciliation_account_id
 * @property string $period_ref
 * @property string $opening_balance
 * @property string $closing_balance
 * @property string $state
 * @property Carbon|null $source_data_watermark
 * @property string|null $content_hash
 * @property string $opened_by
 * @property string|null $completed_by
 * @property Carbon|null $completed_at
 * @property string|null $reopened_by
 * @property Carbon|null $reopened_at
 * @property int $version
 */
final class BankReconciliation extends Model
{
    use HasUuids;

    protected $fillable = [
        'entity_id', 'reconciliation_account_id', 'period_ref', 'opening_balance', 'closing_balance', 'state',
        'source_data_watermark', 'content_hash', 'opened_by', 'completed_by', 'completed_at',
        'reopened_by', 'reopened_at', 'version',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'opening_balance' => ExactDecimalCast::class, 'closing_balance' => ExactDecimalCast::class,
            'source_data_watermark' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime', 'version' => 'integer',
        ];
    }
}
