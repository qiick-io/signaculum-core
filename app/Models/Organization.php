<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRoleEnum;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Signaculum organisation (`id` / `slug` appear on the `qiick.orgs` claim).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $logo_path
 * @property string|null $logo_dark_path
 * @property string|null $brand_color
 * @property string|null $brand_color_dark
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'logo_dark_path',
        'brand_color',
        'brand_color_dark',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this, OrganizationMembership>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(OrganizationMembership::class)
            ->withPivot(['roles'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function ownerCount(): int
    {
        return $this->memberships()
            ->get()
            ->filter(fn (OrganizationMembership $membership): bool => $membership->hasRole(OrganizationRoleEnum::Owner))
            ->count();
    }
}
