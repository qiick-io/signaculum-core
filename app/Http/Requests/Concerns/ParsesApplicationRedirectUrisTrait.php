<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Data\ApplicationData;
use App\Rules\RedirectUriRule;
use Illuminate\Validation\Validator;

/**
 * Shared parsing and URL checks for the admin application redirect URI and post-logout
 * redirect URI textareas.
 *
 * The parsing itself lives on {@see ApplicationData} so the admin form and the Admin
 * REST API read a submitted list the same way (#186).
 */
trait ParsesApplicationRedirectUrisTrait
{
    /**
     * @return list<string>
     */
    protected function parsedRedirectUris(): array
    {
        return ApplicationData::parseRedirectUris($this->input('redirect_uris'));
    }

    /**
     * Post-logout redirect URIs are optional, but each one has the shape of a redirect URI
     * ({@see RedirectUriRule}): the end-session endpoint matches them exactly, so a
     * wildcard or a fragment would never match, and plain http would leak the `state`.
     */
    protected function validateParsedPostLogoutRedirectUris(Validator $validator): void
    {
        foreach (ApplicationData::parseRedirectUris($this->input('post_logout_redirect_uris')) as $uri) {
            $message = RedirectUriRule::failureMessageFor($uri);

            if ($message !== null) {
                $validator->errors()->add('post_logout_redirect_uris', $message);

                return;
            }
        }
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
