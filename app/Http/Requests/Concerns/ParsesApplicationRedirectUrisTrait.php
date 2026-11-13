<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\RedirectUriRule;
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
            $message = RedirectUriRule::failureMessageFor($uri);

            if ($message !== null) {
                $validator->errors()->add('redirect_uris', $message);

                return;
            }
        }
    }
}
