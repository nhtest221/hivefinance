<?php

namespace App\Models\Identity;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Role extends Model
{
    use HasUuids;

    protected $table = 'identity_roles';

    protected $fillable = [
        'entity_id',
        'name',
        'slug',
        'is_system',
        'rank',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'rank' => 'integer',
        ];
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\User::class, 'identity_role_user')
            ->withPivot('entity_id')
            ->withTimestamps();
    }
}
