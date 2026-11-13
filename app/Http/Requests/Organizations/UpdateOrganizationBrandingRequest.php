<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Organisation owners/admins may set logo and brand colours for login/consent.
 */
final class UpdateOrganizationBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('organization')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'brand_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_color_dark' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml'],
            'logo_dark' => ['nullable', 'file', 'max:2048', 'mimetypes:image/png,image/jpeg,image/webp,image/svg+xml'],
            'remove_files' => ['sometimes', 'array'],
            'remove_files.*' => ['string', Rule::in(['logo', 'logo_dark'])],
        ];
    }

    /**
     * @return array{brand_color: string|null, brand_color_dark: string|null}
     */
    public function colours(): array
    {
        return [
            'brand_color' => $this->string('brand_color')->toString() ?: null,
            'brand_color_dark' => $this->string('brand_color_dark')->toString() ?: null,
        ];
    }

    /**
     * @return array{logo?: UploadedFile, logo_dark?: UploadedFile}
     */
    public function uploads(): array
    {
        $uploads = [];

        foreach (['logo', 'logo_dark'] as $field) {
            $file = $this->file($field);

            if ($file instanceof UploadedFile) {
                $uploads[$field] = $file;
            }
        }

        return $uploads;
    }

    /**
     * @return list<'logo'|'logo_dark'>
     */
    public function removals(): array
    {
        /** @var list<'logo'|'logo_dark'> $removals */
        $removals = array_values(array_filter(
            $this->input('remove_files', []),
            static fn (mixed $value): bool => in_array($value, ['logo', 'logo_dark'], true),
        ));

        return $removals;
    }
}
