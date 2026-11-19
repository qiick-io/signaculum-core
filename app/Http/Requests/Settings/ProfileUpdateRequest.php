<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates profile name, email, locale and timezone updates for the signed-in user.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return [
            ...$this->profileRules($user->id),
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('i18n.available_locales', ['en' => 'English'])))],
            'timezone' => ['required', 'string', 'timezone:all'],
        ];
    }
}
