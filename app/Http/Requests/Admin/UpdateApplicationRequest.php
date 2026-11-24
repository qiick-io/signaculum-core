<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApplicationData;
use App\Http\Requests\Concerns\ParsesApplicationRedirectUrisTrait;
use App\Http\Requests\Concerns\ValidatesFrontchannelLogoutUriTrait;
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
    use ValidatesFrontchannelLogoutUriTrait;

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
            'post_logout_redirect_uris' => ['sometimes', 'nullable', 'string'],
            'is_confidential' => ['required', 'boolean'],
            'is_first_party' => ['required', 'boolean'],
            'is_client_credentials_enabled' => ['required', 'boolean'],
            'is_device_authorization_enabled' => ['required', 'boolean'],
            'is_pushed_authorization_required' => ['required', 'boolean'],
            'frontchannel_logout_uri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_frontchannel_logout_session_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The admin form and the Admin REST API build the payload in one place (#186), so a
     * field cannot reach this door without reaching the other.
     */
    public function toData(): ApplicationData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ApplicationData::fromValidated($validated);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateParsedRedirectUris($validator);
            $this->validateParsedPostLogoutRedirectUris($validator);
            $this->validateFrontchannelLogoutUri($validator);

            if ($this->boolean('is_client_credentials_enabled') && ! $this->boolean('is_confidential')) {
                $validator->errors()->add(
                    'is_client_credentials_enabled',
                    __('Client credentials require a confidential client.'),
                );
            }
        });
    }
}
