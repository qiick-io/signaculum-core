<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\ValidatesApiKeyExpiryTrait;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Admin → API keys: rotate a key's secret. The rotation sets a new mandatory expiry, bounded by
 * the maximum lifetime counted from the rotation, so a rotation can never extend a key past it.
 */
final class UpdateApiKeySecretRequest extends FormRequest
{
    use ValidatesApiKeyExpiryTrait;

    public function authorize(): bool
    {
        return Gate::allows('update', $this->route('apiKey'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'expires_at' => $this->apiKeyExpiryRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->apiKeyExpiryMessages();
    }

    public function expiresAt(): CarbonImmutable
    {
        return $this->validatedExpiresAt();
    }
}
