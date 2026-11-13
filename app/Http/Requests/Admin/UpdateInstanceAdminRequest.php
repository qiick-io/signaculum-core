<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Grant or revoke instance-admin rights from the admin users list.
 */
final class UpdateInstanceAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $target */
        $target = $this->route('user');

        return Gate::allows('update', $target);
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
