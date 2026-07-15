<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

final class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'occurred_at',
        'actor_id',
        'entity_id',
        'module',
        'action',
        'record_type',
        'record_id',
        'before',
        'after',
        'metadata',
        'correlation_id',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
        ];
    }
}
