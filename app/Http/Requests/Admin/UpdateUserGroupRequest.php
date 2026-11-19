<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UserGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
