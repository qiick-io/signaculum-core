<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates current and new password when changing password from Security settings.
 *
 * LDAP-sourced accounts cannot set a local password; the directory owns credentials (#187).
 */
class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = $this->user();

            if ($user instanceof User && $user->isLdapSourced()) {
                $validator->errors()->add(
                    'password',
                    __('This account\'s password is managed by the directory. Contact your administrator.'),
                );
            }
        });
    }
}
