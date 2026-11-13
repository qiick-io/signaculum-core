<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Invite someone by email to an organisation with one or more roles.
 */
final class StoreOrganizationInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $this->user()?->can('manageMembers', $organization) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(OrganizationRoleEnum::values())],
        ];
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values(array_unique($this->validated('roles')));

        return $roles;
    }
}
