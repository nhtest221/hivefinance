<?php

namespace App\Models;

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $status
 * @property string|null $active_entity_id
 * @property Carbon|null $locked_until
 * @property bool $mfa_required
 * @property bool $mfa_enabled
 */
final class User extends Authenticatable implements CanResetPassword
{
    use CanResetPasswordTrait;
    use HasApiTokens;
    use HasUuids;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'active_entity_id',
        'failed_login_attempts',
        'locked_until',
        'mfa_required',
        'mfa_enabled',
        'mfa_config',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_config',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'mfa_required' => 'boolean',
            'mfa_enabled' => 'boolean',
            'mfa_config' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function activeEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'active_entity_id');
    }

    /**
     * @return BelongsToMany<Entity, $this>
     */
    public function entities(): BelongsToMany
    {
        return $this->belongsToMany(Entity::class, 'identity_entity_user')
            ->withPivot('status')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'identity_role_user')
            ->withPivot('entity_id')
            ->withTimestamps();
    }
}
