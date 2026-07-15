<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $request_hash
 * @property int $response_status
 * @property array<string, mixed> $response_body
 */
final class IdempotencyRecord extends Model
{
    use HasUuids;

    protected $fillable = ['actor_id', 'entity_id', 'operation', 'idempotency_key', 'request_hash', 'response_status', 'response_body'];

    #[Override]
    protected function casts(): array
    {
        return ['response_status' => 'integer', 'response_body' => 'array'];
    }
}
