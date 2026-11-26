<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Pivot between {@see UserGroup} and {@see User}.
 *
 * The table is qiick/laravel-access' (`user_group_user`): no surrogate id, unique on
 * (group, user), with an optional membership `expires_at`.
 *
 * @property int $user_group_id
 * @property int $user_id
 * @property Carbon|null $expires_at
 */
class UserGroupUser extends Pivot
{
    protected $table = 'user_group_user';

    /**
     * @return BelongsTo<UserGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
