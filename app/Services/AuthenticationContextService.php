<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuthenticationMethodEnum;

/**
 * Per-browser session store of how the user authenticated (RFC 8176 `amr`).
 *
 * Cleared and rewritten at the start of each authentication path so a stolen
 * session cannot keep a previous factor set after re-authentication.
 */
final class AuthenticationContextService
{
    public const string SESSION_KEY = 'oidc.authentication_methods';

    /**
     * Replace the stored methods with a single factor (start of an auth path).
     */
    public function replace(AuthenticationMethodEnum $authenticationMethod): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put(self::SESSION_KEY, [$authenticationMethod->value]);
    }

    /**
     * Append a factor if missing, then ensure `mfa` when two primary factors are present.
     */
    public function record(AuthenticationMethodEnum $authenticationMethod): void
    {
        if (! request()->hasSession()) {
            return;
        }

        /** @var list<string> $authenticationMethods */
        $authenticationMethods = array_values(array_filter(
            (array) request()->session()->get(self::SESSION_KEY, []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        if (! in_array($authenticationMethod->value, $authenticationMethods, true)) {
            $authenticationMethods[] = $authenticationMethod->value;
        }

        $authenticationMethods = $this->withMultipleFactorsWhenNeeded($authenticationMethods);

        request()->session()->put(self::SESSION_KEY, $authenticationMethods);
    }

    /**
     * @return list<string>
     */
    public function current(): array
    {
        if (! request()->hasSession()) {
            return [];
        }

        /** @var list<string> $authenticationMethods */
        $authenticationMethods = array_values(array_filter(
            (array) request()->session()->get(self::SESSION_KEY, []),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        ));

        return $this->withMultipleFactorsWhenNeeded($authenticationMethods);
    }

    /**
     * @param  list<string>  $authenticationMethods
     * @return list<string>
     */
    private function withMultipleFactorsWhenNeeded(array $authenticationMethods): array
    {
        $primaryFactors = array_values(array_filter(
            $authenticationMethods,
            static fn (string $method): bool => $method !== AuthenticationMethodEnum::MultipleFactors->value,
        ));

        if (count($primaryFactors) >= 2 && ! in_array(AuthenticationMethodEnum::MultipleFactors->value, $authenticationMethods, true)) {
            $authenticationMethods[] = AuthenticationMethodEnum::MultipleFactors->value;
        }

        return $authenticationMethods;
    }
}
