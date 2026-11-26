<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserGroupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Instance-level named group of users that carries Spatie roles. Members inherit the group's
 * roles through {@see EffectivePermissionResolver}. The tables are qiick/laravel-access'
 * (`user_groups`, `user_group_user`, `user_group_role`); Signaculum's groups are all instance
 * groups (`is_instance`), the only ones whose super-admin role counts (C30).
 *
 * @property int $id
 * @property string $public_id
 * @property string|null $organisation_id
 * @property bool $is_instance
 * @property array<string, mixed>|null $metadata
 * @property string $name
 * @property string|null $description
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class UserGroup extends Model
{
    /** @use HasFactory<UserGroupFactory> */
    use HasFactory, HasUuids, LogsApplicationActivityTrait, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'description'];

    /**
     * Signaculum has no organisation groups: every group is an instance group.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['is_instance' => true];

    /**
     * The public UUID is generated; the bigint `id` stays the key.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_instance' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsToMany<User, $this, UserGroupUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_group_user')
            ->using(UserGroupUser::class)
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_group_role')->withTimestamps();
    }
}
