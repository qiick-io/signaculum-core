<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates Settings → Localization: the default UI language.
 */
final class UpdateInstanceLocalizationSettingsRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_language' => ['required', 'string', Rule::in(array_keys((array) config('i18n.available_locales')))],
        ];
    }

    /**
     * @return array{default_language: string}
     */
    public function settings(): array
    {
        return ['default_language' => $this->string('default_language')->toString()];
    }
}
