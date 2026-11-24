<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * OIDC redirect URI shape for admin registration: absolute URL, https except
 * localhost / 127.0.0.1, no fragments, no user name or password. Exact string match is enforced at runtime
 * by Passport (OIDC Core 1.0 §3.1.2.1).
 *
 * Post-logout redirect URIs have the same shape, because the end-session endpoint
 * matches them exactly too (OIDC RP-Initiated Logout 1.0 §3); their messages name
 * the post-logout field so an operator knows which list to fix.
 */
final class RedirectUriRule implements ValidationRule
{
    public function __construct(
        private readonly bool $isPostLogoutRedirectUri = false,
    ) {}

    /**
     * Returns a translated error message when the URI is invalid, otherwise null.
     */
    public static function failureMessageFor(mixed $value, bool $isPostLogoutRedirectUri = false): ?string
    {
        if (! is_string($value) || $value === '') {
            return self::invalidUrlMessage($isPostLogoutRedirectUri);
        }

        if (Str::length($value) > 2048 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return self::invalidUrlMessage($isPostLogoutRedirectUri);
        }

        if (str_contains($value, '#')) {
            return $isPostLogoutRedirectUri
                ? __('Post-logout redirect URIs must not contain a fragment.')
                : __('Redirect URIs must not contain a fragment.');
        }

        // Userinfo puts credentials in clear wherever the URI is logged, and
        // `https://good.test@evil.test/` reads as one host while pointing at another.
        if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
            return $isPostLogoutRedirectUri
                ? __('Post-logout redirect URIs must not contain a user name or password.')
                : __('Redirect URIs must not contain a user name or password.');
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if ($scheme === 'https') {
            return null;
        }

        if ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        return $isPostLogoutRedirectUri
            ? __('Post-logout redirect URIs must use https, except http://localhost or http://127.0.0.1.')
            : __('Redirect URIs must use https, except http://localhost or http://127.0.0.1.');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = self::failureMessageFor($value, $this->isPostLogoutRedirectUri);

        if ($message !== null) {
            $fail($message);
        }
    }

    private static function invalidUrlMessage(bool $isPostLogoutRedirectUri): string
    {
        return $isPostLogoutRedirectUri
            ? __('Each post-logout redirect URI must be a valid URL.')
            : __('Each redirect URI must be a valid URL.');
    }
}
