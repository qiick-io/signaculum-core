<?php

declare(strict_types=1);

namespace App\Http\Requests\Oidc;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RP-initiated logout parameters (OIDC RP-Initiated Logout 1.0 §2).
 *
 * Validation here is shape-only; signature and exact URI match happen in the controller.
 */
final class EndSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'id_token_hint' => ['sometimes', 'nullable', 'string'],
            'client_id' => ['sometimes', 'nullable', 'string'],
            'post_logout_redirect_uri' => ['sometimes', 'nullable', 'string', 'url'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'id_token_hint' => $this->input('id_token_hint', $this->query('id_token_hint')),
            'client_id' => $this->input('client_id', $this->query('client_id')),
            'post_logout_redirect_uri' => $this->input('post_logout_redirect_uri', $this->query('post_logout_redirect_uri')),
            'state' => $this->input('state', $this->query('state')),
        ]);
    }
}
