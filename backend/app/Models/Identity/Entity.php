<?php

namespace App\Models\Identity;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $legal_name
 * @property string $functional_currency
 */
final class Entity extends Model
{
    use HasUuids;

    protected $table = 'identity_entities';

    protected $fillable = [
        'legal_name',
        'functional_currency',
        'fiscal_year_start_month',
        'fiscal_year_start_day',
        'approval_policy',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'approval_policy' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'identity_entity_user')
            ->withPivot('status')
            ->withTimestamps();
    }
}
