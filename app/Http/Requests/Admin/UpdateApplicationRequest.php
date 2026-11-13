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
 * Validates an OIDC application update from the admin edit drawer.
 */
final class UpdateApplicationRequest extends FormRequest
{
    use ParsesApplicationRedirectUrisTrait;

    public function authorize(): bool
    {
        /** @var Client $application */
        $application = $this->route('application');

        return Gate::allows('update', $application);
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
            'is_client_credentials_enabled' => ['required', 'boolean'],
        ];
    }

    public function toData(): ApplicationData
    {
        return new ApplicationData(
            name: $this->string('name')->toString(),
            redirectUris: $this->parsedRedirectUris(),
            isConfidential: $this->boolean('is_confidential'),
            isFirstParty: $this->boolean('is_first_party'),
            isClientCredentialsEnabled: $this->boolean('is_client_credentials_enabled'),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateParsedRedirectUris($validator);

            if ($this->boolean('is_client_credentials_enabled') && ! $this->boolean('is_confidential')) {
                $validator->errors()->add(
                    'is_client_credentials_enabled',
                    __('Client credentials require a confidential client.'),
                );
            }
        });
    }
}
