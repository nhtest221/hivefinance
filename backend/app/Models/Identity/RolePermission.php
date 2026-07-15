<?php

namespace App\Models\Identity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $permission
 */
final class RolePermission extends Model
{
    protected $table = 'identity_role_permissions';

    protected $fillable = [
        'role_id',
        'permission',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
