<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Requests\Concerns\ValidatesApiKeyExpiryTrait;
use App\Models\ApiKey;
use App\Services\ApiKeyScopeVocabularyService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Qiick\ApiKeys\IpAllowlist;
use Qiick\ApiKeys\ScopeSet;

/**
 * Admin → API keys: a new key with a name, at least one scope of this product's vocabulary, a
 * mandatory expiry within the maximum lifetime and the optional restrictions of the shared form
 * (IP allowlist, rate limit).
 *
 * `ip_allowlist` arrives as the form's free text, addresses and CIDR ranges separated by commas
 * or new lines; every entry must parse, so a typo never turns into "every address".
 */
final class StoreApiKeyRequest extends FormRequest
{
    use ValidatesApiKeyExpiryTrait;

    public function authorize(): bool
    {
        return Gate::allows('create', ApiKey::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // `required` already refuses an empty list.
            'scopes' => ['required', 'array'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::enum(ApiKeyScopeEnum::class)],
            'expires_at' => $this->apiKeyExpiryRules(),
            'ip_allowlist' => ['nullable', 'string', 'max:2000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->apiKeyExpiryMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A value that is not text has its error already, and cannot be split into entries.
            if ($validator->errors()->has('ip_allowlist')) {
                return;
            }

            foreach ($this->ipAllowlistEntries() as $entry) {
                if (! $this->isIpAddressOrRange($entry)) {
                    $validator->errors()->add('ip_allowlist', __('":entry" is not an IP address or CIDR range.', ['entry' => $entry]));
                }
            }
        });
    }

    public function apiKeyData(): ApiKeyData
    {
        $rateLimitPerMinute = $this->validated('rate_limit_per_minute');
        $ipAllowlist = $this->ipAllowlistEntries();

        /** @var list<string> $scopes */
        $scopes = $this->validated('scopes');

        return new ApiKeyData(
            name: $this->string('name')->trim()->toString(),
            // Highest per resource: `write` already implies `read` on the same resource.
            scopes: ScopeSet::parseStored(array_values(array_unique($scopes)), app(ApiKeyScopeVocabularyService::class))->values(),
            expiresAt: $this->validatedExpiresAt(),
            ipAllowlist: $ipAllowlist === [] ? null : $ipAllowlist,
            rateLimitPerMinute: $rateLimitPerMinute === null ? null : (int) $rateLimitPerMinute,
        );
    }

    /**
     * @return list<string>
     */
    private function ipAllowlistEntries(): array
    {
        return array_values($this->string('ip_allowlist')
            ->split('/[\s,]+/', flags: PREG_SPLIT_NO_EMPTY)
            ->unique()
            ->all());
    }

    /**
     * An address or a CIDR range by qiick/laravel-api-keys' own rules ({@see IpAllowlist::isValidEntry()}):
     * the verifier denies the whole list on one entry it cannot read, so the form refuses it first.
     */
    private function isIpAddressOrRange(string $entry): bool
    {
        return app(IpAllowlist::class)->isValidEntry($entry);
    }
}
