<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Data\OrganizationData;
use App\Models\Organization;
use App\Rules\ReservedOrganizationSlugRule;
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
                new ReservedOrganizationSlugRule(currentSlug: $organization->slug),
            ],
            'is_mfa_required' => ['required', 'boolean'],
        ];
    }

    /**
     * The web form and the Admin REST API build the payload in one place (#186).
     */
    public function toData(): OrganizationData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return OrganizationData::fromValidated($validated);
    }
}
