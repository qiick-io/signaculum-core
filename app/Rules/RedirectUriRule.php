<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * OIDC redirect URI shape for admin registration: absolute URL, https except
 * localhost / 127.0.0.1, no fragments. Exact string match is enforced at runtime
 * by Passport (OIDC Core 1.0 §3.1.2.1).
 */
final class RedirectUriRule implements ValidationRule
{
    /**
     * Returns a translated error message when the URI is invalid, otherwise null.
     */
    public static function failureMessageFor(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return __('Each redirect URI must be a valid URL.');
        }

        if (Str::length($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return __('Each redirect URI must be a valid URL.');
        }

        if (str_contains($value, '#')) {
            return __('Redirect URIs must not contain a fragment.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if ($scheme === 'https') {
            return null;
        }

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return __('Redirect URIs must use https, except http://localhost or http://127.0.0.1.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = self::failureMessageFor($value);

        if ($message !== null) {
            $fail($message);
        }
    }
}
