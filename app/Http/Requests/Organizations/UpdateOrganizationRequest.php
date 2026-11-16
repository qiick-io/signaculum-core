<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Data\OrganizationData;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update organisation name, slug and MFA requirement.
 */
final class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return $this->user()?->can('update', $organization) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('organizations', 'slug')->ignore($organization->getKey()),
            ],
            'is_mfa_required' => ['required', 'boolean'],
        ];
    }

    public function toData(): OrganizationData
    {
        return new OrganizationData(
            name: (string) $this->validated('name'),
            slug: (string) $this->validated('slug'),
            isMfaRequired: $this->boolean('is_mfa_required'),
        );
    }
}
