<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Absolute front-channel logout URI (OIDC Front-Channel Logout 1.0 §2–§3).
 *
 * https required except localhost / 127.0.0.1; no fragment. Stored and matched
 * as the exact registered string.
 */
final class FrontchannelLogoutUriRule implements ValidationRule
{
    /**
     * Returns a translated error message when the URI is invalid, otherwise null.
     */
    public static function failureMessageFor(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return __('The front-channel logout URI must be a valid URL.');
        }

        if (Str::length($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return __('The front-channel logout URI must be a valid URL.');
        }

        if (str_contains($value, '#')) {
            return __('The front-channel logout URI must not contain a fragment.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if ($scheme === 'https') {
            return null;
        }

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return __('The front-channel logout URI must use https, except http://localhost or http://127.0.0.1.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = self::failureMessageFor($value);

        if ($message !== null) {
            $fail($message);
        }
    }
}
