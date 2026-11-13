<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Data\OrganizationInvitationData;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a mail invitation for an organisation.
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
            'roles.*' => ['required', 'string', Rule::in(OrganizationRoleEnum::values())],
        ];
    }

    public function toData(): OrganizationInvitationData
    {
        /** @var list<string> $roles */
        $roles = array_values($this->validated('roles'));

        return new OrganizationInvitationData(
            email: (string) $this->validated('email'),
            roles: $roles,
        );
    }
}
