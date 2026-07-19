<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $id
 * @property string $event_type
 * @property array<string, mixed>|null $metadata
 * @property array<string, mixed> $payload
 */
final class OutboxMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'event_type',
        'event_version',
        'aggregate_type',
        'aggregate_id',
        'entity_id',
        'payload',
        'metadata',
        'occurred_at',
        'available_at',
        'processed_at',
        'attempts',
        'last_error',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'event_version' => 'integer',
            'payload' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
