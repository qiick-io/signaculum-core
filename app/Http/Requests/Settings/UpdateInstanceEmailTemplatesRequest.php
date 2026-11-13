<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\EmailTemplateTypeEnum;
use App\Services\EmailTemplateService;
use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates Settings → Email templates: subject, body and action label per type and locale.
 */
final class UpdateInstanceEmailTemplatesRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $locales = array_keys((array) config('i18n.available_locales', ['en' => 'English']));
        $rules = [
            'templates' => ['required', 'array'],
        ];

        foreach (EmailTemplateTypeEnum::values() as $type) {
            $rules['templates.'.$type] = ['required', 'array'];

            foreach ($locales as $locale) {
                $prefix = 'templates.'.$type.'.'.$locale;
                $rules[$prefix] = ['required', 'array'];
                $rules[$prefix.'.subject'] = ['nullable', 'string', 'max:200'];
                $rules[$prefix.'.body'] = ['nullable', 'string', 'max:10000'];
                $rules[$prefix.'.action_label'] = ['nullable', 'string', 'max:100'];
            }
        }

        return $rules;
    }

    /**
     * Stored setting payload: only non-default overrides.
     *
     * @return array{email_templates: array<string, array<string, array{subject: string, body: string, action_label: string}>>}
     */
    public function settings(): array
    {
        /** @var array<string, mixed> $templates */
        $templates = $this->validated('templates');

        return [
            'email_templates' => app(EmailTemplateService::class)->overridesFromForm($templates),
        ];
    }
}
