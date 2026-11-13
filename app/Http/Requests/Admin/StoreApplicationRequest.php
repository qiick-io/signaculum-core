<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApplicationData;
use App\Http\Requests\Concerns\ParsesApplicationRedirectUrisTrait;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Validates a new OIDC application from the admin create drawer.
 *
 * Strict redirect-URI rules (https-only, no fragments) land in the sibling issue.
 */
final class StoreApplicationRequest extends FormRequest
{
    use ParsesApplicationRedirectUrisTrait;

    public function authorize(): bool
    {
        return Gate::allows('create', Client::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'string'],
            'is_confidential' => ['required', 'boolean'],
            'is_first_party' => ['required', 'boolean'],
        ];
    }

    public function toData(): ApplicationData
    {
        return new ApplicationData(
            name: $this->string('name')->toString(),
            redirectUris: $this->parsedRedirectUris(),
            isConfidential: $this->boolean('is_confidential'),
            isFirstParty: $this->boolean('is_first_party'),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateParsedRedirectUris($validator);
        });
    }
}
