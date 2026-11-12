<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\SettingsRepositoryService;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates Settings → Authentication & security: password policy, login attempts per minute
 * and required two-factor authentication.
 */
final class UpdateInstanceSecuritySettingsRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password_policy' => ['required', Rule::in(SettingsRepositoryService::PASSWORD_POLICIES)],
            'login_max_attempts' => ['required', 'integer', 'between:1,100'],
            'two_factor_required' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{password_policy: string, login_max_attempts: int, two_factor_required: bool}
     */
    public function settings(): array
    {
        return [
            'password_policy' => $this->string('password_policy')->toString(),
            'login_max_attempts' => $this->integer('login_max_attempts'),
            'two_factor_required' => $this->boolean('two_factor_required'),
        ];
    }
}
