<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Data\OrganizationData;
use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create organisation: name and slug (slug from name when omitted).
 */
final class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Organization::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('organizations', 'slug')],
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
