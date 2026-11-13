<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    protected $model = OrganizationInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'email' => fake()->unique()->safeEmail(),
            'roles' => [OrganizationRoleEnum::Member->value],
            'token_hash' => OrganizationInvitation::hashToken(Str::random(40)),
            'invited_by_user_id' => User::factory(),
            'expires_at' => now()->addHours((int) config('organizations.invitation_expires_hours', 72)),
            'accepted_at' => null,
        ];
    }
}
