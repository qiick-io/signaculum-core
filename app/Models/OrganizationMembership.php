<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRoleEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot row for organisation membership (`roles` is a JSON array of OrganizationRoleEnum values).
 *
 * @property int $id
 * @property string $organization_id
 * @property int $user_id
 * @property list<string> $roles
 */
class OrganizationMembership extends Pivot
{
    public $incrementing = true;

    protected $table = 'organization_user';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'user_id',
        'roles',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'roles' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasRole(OrganizationRoleEnum $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }
}
