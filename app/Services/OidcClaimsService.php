<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OidcScopeEnum;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * The user claims released for a set of granted scopes (OIDC Core §5.4). Shared by the
 * id_token and the userinfo endpoint, so both always return the same values.
 *
 * Claims the user has no value for (`picture`, an unset `locale`) are omitted, never sent empty.
 * The `orgs` claim is always an array when `qiick.orgs` is granted (empty when the user has
 * no memberships), so clients that map roles can revoke them when membership disappears.
 */
final class OidcClaimsService
{
    /**
     * Every user claim this provider can release, for discovery `claims_supported`.
     *
     * @var list<string>
     */
    public const array SUPPORTED_USER_CLAIMS = [
        'sub',
        'name',
        'given_name',
        'family_name',
        'locale',
        'updated_at',
        'email',
        'email_verified',
        'orgs',
    ];

    /**
     * @param  list<string>  $scopes
     * @return array<string, string|int|bool|list<array{id: string, slug: string, roles: list<string>}>>
     */
    public function claimsFor(User $user, array $scopes): array
    {
        $claims = ['sub' => $user->subject];

        if (in_array(OidcScopeEnum::Profile->value, $scopes, true)) {
            $claims['name'] = $user->name;
            $claims['given_name'] = $user->first_name;

            if ($user->last_name !== null && $user->last_name !== '') {
                $claims['family_name'] = $user->last_name;
            }

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

        if (in_array(OidcScopeEnum::QiickOrgs->value, $scopes, true)) {
            $claims['orgs'] = $this->organisationClaimsFor($user);
        }

        return $claims;
    }

    /**
     * Wire shape for laravel-oidc role mapping: `[{ id, slug, roles }]`, sorted by slug.
     *
     * @return list<array{id: string, slug: string, roles: list<string>}>
     */
    private function organisationClaimsFor(User $user): array
    {
        $memberships = OrganizationMembership::query()
            ->with('organization:id,slug')
            ->where('user_id', $user->getKey())
            ->get();

        /** @var list<array{id: string, slug: string, roles: list<string>}> $organisations */
        $organisations = [];

        foreach ($memberships as $membership) {
            $organization = $membership->organization;

            if ($organization === null) {
                continue;
            }

            $organisations[] = [
                'id' => $organization->id,
                'slug' => $organization->slug,
                'roles' => $membership->roles ?? [],
            ];
        }

        usort(
            $organisations,
            fn (array $left, array $right): int => $left['slug'] <=> $right['slug'],
        );

        return $organisations;
    }
}
