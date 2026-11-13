<?php

declare(strict_types=1);

namespace App\Http\Requests\Oidc;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Trust-boundary validation for `POST /oauth/revoke` (RFC 7009 §2.1).
 *
 * Client authentication is resolved in the controller (Basic or body), not here.
 */
final class RevokeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'token_type_hint' => ['sometimes', 'nullable', 'string', Rule::in(['access_token', 'refresh_token'])],
            'client_id' => ['sometimes', 'nullable', 'string'],
            'client_secret' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
