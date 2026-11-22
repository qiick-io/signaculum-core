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
 * Validate creating a user group with memberships and roles.
 */
class StoreUserGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', UserGroup::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique(UserGroup::class, 'name')],
            'description' => ['nullable', 'string', 'max:2000'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('is_assignable', true)],
        ];
    }

    /**
     * Block attaching the super-admin role unless the actor is a super-admin.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $roleIds = $this->input('role_ids', []);

            if (! is_array($roleIds) || $roleIds === []) {
                return;
            }

            $guard = app(SuperAdminGuardService::class);

            if ($guard->hasSuperAdminRole($roleIds) && ! $guard->canManageSuperAdmins($this->user())) {
                $validator->errors()->add('role_ids', __('Only a super-admin may attach the super-admin role to a group.'));
            }
        });
    }
}
