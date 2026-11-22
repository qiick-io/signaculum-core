<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\ApiKeyLifetimeService;
use Carbon\CarbonImmutable;

/**
 * The mandatory `expires_at` of an Admin API key, shared by creation and rotation: required, in
 * the future and no later than the maximum lifetime counted from now ({@see ApiKeyLifetimeService}).
 * A failure answers 422 to a JSON request and returns the form errors to the console.
 */
trait ValidatesApiKeyExpiryTrait
{
    /**
     * @return list<string>
     */
    protected function apiKeyExpiryRules(): array
    {
        $latestExpiry = app(ApiKeyLifetimeService::class)->latestExpiryFor(CarbonImmutable::now());

        return ['required', 'date', 'after:now', 'before_or_equal:'.$latestExpiry->toDateTimeString()];
    }

    /**
     * @return array<string, string>
     */
    protected function apiKeyExpiryMessages(): array
    {
        return [
            'expires_at.required' => __('Every API key needs an expiry date.'),
            'expires_at.before_or_equal' => __('An API key may last at most :days days.', [
                'days' => app(ApiKeyLifetimeService::class)->maximumLifetimeDays(),
            ]),
        ];
    }

    protected function validatedExpiresAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('expires_at')->toString());
    }
}
