<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\ApiKeyData;
use App\Data\ApiKeyScopesData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Admin → API keys: a new key with a name, at least one scope of this product's vocabulary and
 * the optional restrictions of the shared form (expiry, IP allowlist, rate limit).
 *
 * `ip_allowlist` arrives as the form's free text, addresses and CIDR ranges separated by commas
 * or new lines; every entry must parse, so a typo never turns into "every address".
 */
final class StoreApiKeyRequest extends FormRequest
{
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
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct', Rule::enum(ApiKeyScopeEnum::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'ip_allowlist' => ['nullable', 'string', 'max:2000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->ipAllowlistEntries() as $entry) {
                if (! $this->isIpAddressOrRange($entry)) {
                    $validator->errors()->add('ip_allowlist', __('":entry" is not an IP address or CIDR range.', ['entry' => $entry]));
                }
            }
        });
    }

    public function apiKeyData(): ApiKeyData
    {
        $expiresAt = $this->validated('expires_at');
        $rateLimitPerMinute = $this->validated('rate_limit_per_minute');
        $ipAllowlist = $this->ipAllowlistEntries();

        /** @var list<string> $scopes */
        $scopes = $this->validated('scopes');

        return new ApiKeyData(
            name: $this->string('name')->trim()->toString(),
            scopes: ApiKeyScopesData::highestPerResource($scopes),
            expiresAt: is_string($expiresAt) ? CarbonImmutable::parse($expiresAt) : null,
            ipAllowlist: $ipAllowlist === [] ? null : $ipAllowlist,
            rateLimitPerMinute: $rateLimitPerMinute === null ? null : (int) $rateLimitPerMinute,
        );
    }

    /**
     * @return list<string>
     */
    private function ipAllowlistEntries(): array
    {
        $entries = preg_split('/[\s,]+/', $this->string('ip_allowlist')->toString(), flags: PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($entries === false ? [] : $entries));
    }

    private function isIpAddressOrRange(string $entry): bool
    {
        [$address, $prefixLength] = array_pad(explode('/', $entry, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefixLength === null) {
            return true;
        }

        $maximumPrefixLength = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return filter_var($prefixLength, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximumPrefixLength]]) !== false;
    }
}
