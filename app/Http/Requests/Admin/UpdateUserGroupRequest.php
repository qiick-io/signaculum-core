<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UserGroup;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validate updating a user group with memberships and roles.
 */
class UpdateUserGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var UserGroup $group */
        $group = $this->route('group');

        return Gate::allows('update', $group);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var UserGroup $group */
        $group = $this->route('group');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(UserGroup::class, 'name')->ignore($group->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'user_ids' => ['sometimes', 'nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['sometimes', 'nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('is_assignable', true)],
        ];
    }

    /**
     * Block attaching the super-admin role unless the actor is a super-admin.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->exists('role_ids')) {
                return;
            }

            $roleIds = $this->input('role_ids', []);

            if (! is_array($roleIds)) {
                return;
            }

            $guard = app(SuperAdminGuardService::class);

            if ($guard->hasSuperAdminRole($roleIds) && ! $guard->canManageSuperAdmins($this->user())) {
                $validator->errors()->add('role_ids', __('Only a super-admin may attach the super-admin role to a group.'));
            }
        });
    }
}
