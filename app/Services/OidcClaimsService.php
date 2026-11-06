<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OidcScopeEnum;
use App\Models\User;

/**
 * The user claims released for a set of granted scopes (OIDC Core §5.4). Shared by the
 * id_token and the userinfo endpoint, so both always return the same values.
 *
 * Claims the user has no value for (`picture`, an unset `locale`) are omitted, never sent empty.
 */
final class OidcClaimsService
{
    /**
     * Every user claim this provider can release, for discovery `claims_supported`.
     *
     * @var list<string>
     */
    public const array SUPPORTED_USER_CLAIMS = ['sub', 'name', 'locale', 'updated_at', 'email', 'email_verified'];

    /**
     * @param  list<string>  $scopes
     * @return array<string, string|int|bool>
     */
    public function claimsFor(User $user, array $scopes): array
    {
        $claims = ['sub' => $user->subject];

        if (in_array(OidcScopeEnum::Profile->value, $scopes, true)) {
            $claims['name'] = $user->name;

            if ($user->locale !== null) {
                $claims['locale'] = str_replace('_', '-', $user->locale);
            }

            if ($user->updated_at !== null) {
                $claims['updated_at'] = $user->updated_at->getTimestamp();
            }
        }

        if (in_array(OidcScopeEnum::Email->value, $scopes, true)) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        return $claims;
    }
}
