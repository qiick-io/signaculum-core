<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\UserGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

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
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
