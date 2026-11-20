<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot between {@see UserGroup} and {@see User}.
 *
 * @property int $id
 * @property int $user_group_id
 * @property int $user_id
 */
class UserGroupUser extends Pivot
{
    public $incrementing = true;

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
