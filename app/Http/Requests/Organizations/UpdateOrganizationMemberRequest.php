<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Replace a member's roles (multi-role JSON).
 */
final class UpdateOrganizationMemberRequest extends FormRequest
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
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(OrganizationRoleEnum::values())],
        ];
    }

    /**
     * @return list<string>
     */
    public function roles(): array
    {
        /** @var list<string> $roles */
        $roles = array_values($this->validated('roles'));

        return $roles;
    }
}
