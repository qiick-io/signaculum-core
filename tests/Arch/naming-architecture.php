<?php

declare(strict_types=1);

/**
 * This repo's settings for the shared `NamingArchitectureTest.php` (qiick standards).
 *
 * The sync writes this file once and never overwrites or checks it: it is the repo's own.
 *
 * - `namespace`: the root namespace the rules check (`App` for an app, `Qiick\Xyz` for a package).
 * - `ignoring`: per rule, the classes or namespaces it skips. Only framework or package
 *   scaffolding that must keep its upstream name, or a deferred clean-up with its issue.
 *   Give the reason in a comment on each entry. Rule keys: a sub-namespace of the suffix
 *   rules (`Http\Middleware`, `Actions`, `Services`, …), or `strict types`, `env`, `Data`,
 *   `Enums`, `Contracts`, `traits`.
 * - `suffixes`: extra sub-namespace => suffix pairs for roles of this repo (`Parsers` => `Parser`).
 *
 * OpenID Connect Passport extension points live under `App\Oidc` (not a single shared suffix):
 * - `OidcAuthCodeGrant` (`*Grant`): replaces Passport's authorization_code grant (PKCE S256, nonce).
 * - `OidcRefreshTokenGrant` (`*Grant`): replaces Passport's refresh_token grant (keeps the code's token family).
 * - `OidcAuthCodeRepository` (`*Repository`): persists `nonce` / `auth_time` / `sid` / `amr` with the auth code.
 * - `OidcDeviceCodeGrant` (`*Grant`): replaces Passport's device_code grant (RFC 8628 + id_token context).
 * - `OidcDeviceCodeRepository` (`*Repository`): persists `auth_time` / `sid` / `amr` with the device code.
 * - `IdTokenResponse` (`*Response`): adds the RS256 `id_token` to the token response.
 * They keep those role nouns because they extend league/Passport / oauth2-server types; the
 * shared suffix table does not cover `App\Oidc`, and renaming them would break the wiring in
 * `AppServiceProvider::configureOpenIdConnect()`.
 *
 * LDAP directory models live under `App\Ldap` (LdapRecord `Model` subclasses, e.g. `DirectoryUser`).
 * The shared suffix table does not cover that namespace; they are query models only and never
 * write back to the directory (#77).
 *
 * `App\Models\Client` extends Passport's Client (upstream noun, plus logout columns).
 */
return [
    'namespace' => 'App',

    'ignoring' => [
        'strict types' => [
            // Emptied: starter-kit files we own now declare strict_types (#115).
        ],
        'Http\Middleware' => [
            // Starter-kit middleware, never renamed.
            'App\Http\Middleware\HandleAppearance',
            'App\Http\Middleware\HandleInertiaRequests',
        ],
        'traits' => [
            // Starter-kit validation-rule traits, never renamed.
            'App\Concerns',
        ],
    ],

    'suffixes' => [],
];
