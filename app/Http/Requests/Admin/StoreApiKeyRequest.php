<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApiKeyData;
use App\Data\ApiKeyScopesData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Requests\Concerns\ValidatesApiKeyExpiryTrait;
use App\Models\ApiKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            scopes: ApiKeyScopesData::highestPerResource($scopes),
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
     * An address, or an address and a prefix length that fits its family (0-32 for IPv4,
     * 0-128 for IPv6). Anything after the first `/` is the prefix, so `10.0.0.0/8/9` is refused.
     */
    private function isIpAddressOrRange(string $entry): bool
    {
        $address = Str::before($entry, '/');

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return true;
        }

        $maximumPrefixLength = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return filter_var(Str::after($entry, '/'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximumPrefixLength]]) !== false;
    }
}
