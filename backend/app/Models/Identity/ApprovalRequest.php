<?php

namespace App\Models\Identity;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * @property string $id
 * @property string $entity_id
 * @property string $maker_id
 * @property string|null $approver_id
 * @property string $status
 * @property string $command_type
 * @property int $command_schema_version
 * @property string|null $resource_id
 * @property string $required_approval_capability
 * @property string $encrypted_payload
 * @property string $payload_hash
 * @property string $originating_idempotency_key
 * @property string $originating_operation
 * @property string $originating_request_hash
 * @property string $originating_correlation_id
 * @property string $causation_id
 * @property string|null $approval_requested_event_id
 * @property int|null $original_if_match
 * @property int|null $command_result_status
 * @property array<string, mixed>|null $command_result_body
 * @property int $version
 * @property Carbon $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $retained_until
 */
final class ApprovalRequest extends Model
{
    use HasUuids;

    protected $table = 'identity_approval_requests';

    protected $fillable = [
        'entity_id', 'maker_id', 'approver_id', 'status', 'command_type',
        'command_schema_version', 'resource_id', 'required_approval_capability',
        'encrypted_payload', 'payload_hash', 'originating_idempotency_key',
        'originating_operation', 'originating_request_hash', 'originating_correlation_id',
        'causation_id', 'approval_requested_event_id', 'original_if_match',
        'command_result_status', 'command_result_body', 'version', 'submitted_at',
        'approved_at', 'retained_until',
    ];

    protected $hidden = [
        'encrypted_payload', 'payload_hash', 'originating_idempotency_key',
        'originating_request_hash', 'originating_correlation_id', 'causation_id',
        'required_approval_capability', 'command_result_body',
    ];

    #[Override]
    protected static function booted(): void
    {
        self::updating(function (self $approval): void {
            $immutable = [
                'entity_id', 'maker_id', 'command_type', 'command_schema_version',
                'resource_id', 'required_approval_capability', 'encrypted_payload',
                'payload_hash', 'originating_idempotency_key', 'originating_operation',
                'originating_request_hash', 'originating_correlation_id', 'causation_id',
                'original_if_match', 'submitted_at', 'retained_until',
            ];
            if ($approval->isDirty($immutable)) {
                throw new \LogicException('Originating approval command data is immutable.');
            }
        });
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'command_schema_version' => 'integer',
            'original_if_match' => 'integer',
            'command_result_status' => 'integer',
            'command_result_body' => 'array',
            'version' => 'integer',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'retained_until' => 'immutable_datetime',
        ];
    }
}
