<?php

namespace App\Models\Identity;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

/**
 * @property string $id
 * @property string $slug
 */
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

    #[Override]
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'rank' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'identity_role_user')
            ->withPivot('entity_id')
            ->withTimestamps();
    }
}
