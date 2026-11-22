<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Traits\AuthorizesInstanceSettingsTrait;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates Settings → General: instance name, description and the bug report link.
 */
final class UpdateInstanceSettingsRequest extends FormRequest
{
    use AuthorizesInstanceSettingsTrait;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'report_bug_url' => ['nullable', 'url:http,https', 'max:2048'],
            'activity_retention_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }

    /**
     * Values to store: empty text becomes null.
     *
     * @return array{app_name: string|null, description: string|null, report_bug_url: string|null, activity_retention_days: int}
     */
    public function settings(): array
    {
        return [
            'app_name' => $this->string('app_name')->trim()->toString() ?: null,
            'description' => $this->string('description')->trim()->toString() ?: null,
            'report_bug_url' => $this->string('report_bug_url')->trim()->toString() ?: null,
            'activity_retention_days' => $this->integer('activity_retention_days'),
        ];
    }
}
