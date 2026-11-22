<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Grant or revoke the super-admin role from the admin users list.
 * Only a super-admin may authorize this (not a delegated admin with can-edit-users).
 */
final class UpdateInstanceAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        return app(SuperAdminGuardService::class)->canManageSuperAdmins($this->user())
            && $this->user()?->can('update', $target) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_instance_admin' => ['required', 'boolean'],
        ];
    }

    public function shouldBeInstanceAdmin(): bool
    {
        return $this->boolean('is_instance_admin');
    }
}
