<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Instance admins may start a time-boxed impersonation of a non-admin user.
 */
final class StartUserImpersonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('impersonate', $this->route('user')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
