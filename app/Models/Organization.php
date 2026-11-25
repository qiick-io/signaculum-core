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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A Signaculum organisation (`id` / `slug` appear on the `qiick.orgs` claim).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property bool $is_mfa_required
 * @property string|null $logo_path
 * @property string|null $logo_dark_path
 * @property string|null $brand_color
 * @property string|null $brand_color_dark
 * @property Carbon|null $deleted_at
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    // Deleted organisations are offboarded and kept as evidence (DeleteOrganizationAction).
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'is_mfa_required',
        'logo_path',
        'logo_dark_path',
        'brand_color',
        'brand_color_dark',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_mfa_required' => 'boolean',
        ];
    }

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
