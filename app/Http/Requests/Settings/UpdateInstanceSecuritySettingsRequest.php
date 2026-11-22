<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Services\SettingsRepositoryService;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates Settings → Authentication & security: granular password policy, rate limit,
 * lockout, self-registration and required two-factor authentication.
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
            'password_min_length' => [
                'required',
                'integer',
                'between:'.SettingsRepositoryService::PASSWORD_MIN_LENGTH_FLOOR.','.SettingsRepositoryService::PASSWORD_MIN_LENGTH_CEILING,
            ],
            'password_require_mixed_case' => ['required', 'boolean'],
            'password_require_numbers' => ['required', 'boolean'],
            'password_require_symbols' => ['required', 'boolean'],
            'password_reject_compromised' => ['required', 'boolean'],
            'login_max_attempts' => ['required', 'integer', 'between:1,100'],
            'login_lockout_threshold' => ['required', 'integer', 'between:1,100'],
            'login_lockout_minutes' => ['required', 'integer', 'between:1,1440'],
            'registration_enabled' => ['required', 'boolean'],
            'two_factor_required' => ['required', 'boolean'],
            'email_code_login_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array{
     *     password_min_length: int,
     *     password_require_mixed_case: bool,
     *     password_require_numbers: bool,
     *     password_require_symbols: bool,
     *     password_reject_compromised: bool,
     *     login_max_attempts: int,
     *     login_lockout_threshold: int,
     *     login_lockout_minutes: int,
     *     registration_enabled: bool,
     *     two_factor_required: bool,
     *     email_code_login_enabled: bool
     * }
     */
    public function settings(): array
    {
        return [
            'password_min_length' => $this->integer('password_min_length'),
            'password_require_mixed_case' => $this->boolean('password_require_mixed_case'),
            'password_require_numbers' => $this->boolean('password_require_numbers'),
            'password_require_symbols' => $this->boolean('password_require_symbols'),
            'password_reject_compromised' => $this->boolean('password_reject_compromised'),
            'login_max_attempts' => $this->integer('login_max_attempts'),
            'login_lockout_threshold' => $this->integer('login_lockout_threshold'),
            'login_lockout_minutes' => $this->integer('login_lockout_minutes'),
            'registration_enabled' => $this->boolean('registration_enabled'),
            'two_factor_required' => $this->boolean('two_factor_required'),
            'email_code_login_enabled' => $this->boolean('email_code_login_enabled'),
        ];
    }
}
