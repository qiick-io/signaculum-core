<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Shared parsing and soft URL checks for admin application redirect URI textareas.
 */
trait ParsesApplicationRedirectUrisTrait
{
    /**
     * @return list<string>
     */
    protected function parsedRedirectUris(): array
    {
        $raw = $this->input('redirect_uris');

        if (is_array($raw)) {
            $lines = $raw;
        } else {
            $lines = preg_split('/\R+/', is_string($raw) ? $raw : '') ?: [];
        }

        $uris = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $uri = trim($line);

            if ($uri === '') {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    protected function validateParsedRedirectUris(Validator $validator): void
    {
        $uris = $this->parsedRedirectUris();

        if ($uris === []) {
            $validator->errors()->add('redirect_uris', __('Add at least one redirect URI.'));

            return;
        }

        foreach ($uris as $uri) {
            if (Str::length($uri) > 2048 || filter_var($uri, FILTER_VALIDATE_URL) === false) {
                $validator->errors()->add('redirect_uris', __('Each redirect URI must be a valid URL.'));

                return;
            }
        }
    }
}
