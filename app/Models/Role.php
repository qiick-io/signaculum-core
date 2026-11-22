<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie role with the externa-core flags used by the groups admin screens.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property bool $is_system
 * @property bool $is_assignable
 */
class Role extends SpatieRole
{
    /**
     * @return BelongsToMany<UserGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_role')->withTimestamps();
    }
}
