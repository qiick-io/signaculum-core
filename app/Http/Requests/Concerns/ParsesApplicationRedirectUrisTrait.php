<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Data\ApplicationData;
use App\Rules\RedirectUriRule;
use Illuminate\Validation\Validator;

/**
 * Shared parsing and soft URL checks for admin application redirect URI textareas.
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
