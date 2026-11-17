<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\FrontchannelLogoutUriRule;
use Illuminate\Validation\Validator;

/**
 * Optional front-channel logout URI on Admin → Applications (Front-Channel Logout 1.0 §2–§3).
 */
trait ValidatesFrontchannelLogoutUriTrait
{
    protected function normalizedFrontchannelLogoutUri(): ?string
    {
        $raw = $this->input('frontchannel_logout_uri');

        if (! is_string($raw)) {
            return null;
        }

        $uri = trim($raw);

        return $uri !== '' ? $uri : null;
    }

    protected function validateFrontchannelLogoutUri(Validator $validator): void
    {
        $uri = $this->normalizedFrontchannelLogoutUri();

        if ($uri === null) {
            return;
        }

        $message = FrontchannelLogoutUriRule::failureMessageFor($uri);

        if ($message !== null) {
            $validator->errors()->add('frontchannel_logout_uri', $message);
        }
    }
}
