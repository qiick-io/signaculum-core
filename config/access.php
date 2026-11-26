<?php

declare(strict_types=1);
use App\Enums\PermissionEnum;
use App\Models\Principal;

/*
 * Package defaults for qiick/laravel-access. Products publish this file and bind
 * the required contracts (RoleCatalog, TwoFactorPolicy, TwoFactorResponder,
 * AuthorizationResponder). See docs/reference-contracts.md.
 *
 * Spatie's `permission.register_permission_check_method` must be false; the
 * package provider fails at boot otherwise (design R4 / AC1).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | API guards
    |--------------------------------------------------------------------------
    |
    | Guard names that mark the current actor as an API actor when that guard
    | is the default. Either this signal or a bound ActorContext of kind
    | ApiKey / OAuthGrant / PublicApi wins (design §3.3). Leave empty when the
    | product never calls Auth::shouldUse() for keys (externa REST).
    |
    */

    'api_guards' => array_values(array_filter(array_map(
        static fn (string $guard): string => trim($guard),
        // Signaculum: `api` (Passport bearer tokens on userinfo) and `api-key` (Admin REST API keys).
        explode(',', (string) env('ACCESS_API_GUARDS', 'api,api-key')),
    ), static fn (string $guard): bool => $guard !== '')),

    /*
    |--------------------------------------------------------------------------
    | Web guards
    |--------------------------------------------------------------------------
    |
    | Guard names of interactive web sessions. Every guard in auth.guards must
    | be listed here or in api_guards, or boot fails: a token guard classified
    | as neither would otherwise take the web path and reach the super-admin
    | grant. EnsureUserHasPermission denies users of any other guard. Add a
    | product's own session guard here (for example Filament's panel guard),
    | comma-separated in ACCESS_WEB_GUARDS.
    |
    */

    'web_guards' => array_values(array_filter(array_map(
        static fn (string $guard): string => trim($guard),
        explode(',', (string) env('ACCESS_WEB_GUARDS', 'web')),
    ), static fn (string $guard): bool => $guard !== '')),

    /*
    |--------------------------------------------------------------------------
    | Trust API guard in console (testing only)
    |--------------------------------------------------------------------------
    |
    | Under PHPUnit, runningInConsole() is always true, so the API-guard signal
    | is ignored by default (jobs/commands stay unattended). Product feature
    | tests that need the guard path must enable this via
    | Qiick\Access\Testing\TrustApiGuardInConsole::enable() and disable() in
    | tearDown. ContainerActorResolver honours the flag only when
    | app()->environment('testing') is true — never a production lever.
    |
    */

    'trust_api_guard_in_console' => false,

    /*
    |--------------------------------------------------------------------------
    | Permission vocabulary
    |--------------------------------------------------------------------------
    |
    | Fully-qualified backed-enum class that lists every permission name.
    | SpatiePermissionVocabulary reads it when the product binds that default.
    |
    */

    'permission_enum' => env('ACCESS_PERMISSION_ENUM', PermissionEnum::class),

    /*
    |--------------------------------------------------------------------------
    | Package migrations
    |--------------------------------------------------------------------------
    |
    | Each switch loads one set of package migrations (default true):
    | - groups: the fixed group tables (user_groups / user_group_user /
    |   user_group_role). externa 1.x sets it to false and adopts the same
    |   table shape through its own additive migration (Q5);
    | - principals: the principals table (design §15).
    |
    */

    'migrations' => [
        'groups' => true,
        'principals' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Principals
    |--------------------------------------------------------------------------
    |
    | - model: the principal model; products may extend it and point here.
    | - types: every principal type the product uses; products append their
    |   own (and any instance types).
    |
    | organisation_required and instance_types are read when the principals
    | migration runs; changing them later changes nothing in the database
    | (design §15.3).
    | - organisation_required: true in products with organisations. Adds a
    |   CHECK: every principal needs an organisation, except the
    |   instance_types, which must have none (and no user where the engine
    |   allows that CHECK), and are singletons per type.
    | - instance_types: lower-case type names allowed at instance level, such
    |   as an instance service account. Each one needs a decision note.
    |
    */

    'principals' => [
        // Signaculum's subclass adds the API key relation.
        'model' => Principal::class,
        'types' => ['user', 'service'],
        // Every principal belongs to an organisation except the singleton Admin API owner
        // (decision 2026-11-22, Signaculum instance service principal). Read when the migration
        // runs: never change it after migrating.
        'organisation_required' => true,
        'instance_types' => ['instance_service'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Users table for group membership FK
    |--------------------------------------------------------------------------
    |
    | Optional override for user_group_user.user_id → users. When null, the
    | package migration reads the table from auth.providers.users.model
    | (Eloquent getTable()), falling back to "users".
    |
    */

    'users_table' => null,

    /*
    |--------------------------------------------------------------------------
    | Group member model
    |--------------------------------------------------------------------------
    |
    | The Authenticatable class whose ids user_group_user.user_id holds. Only
    | it (and its subclasses) gets group roles, so another guard's principal
    | with the same id never inherits them. Null uses
    | auth.providers.users.model.
    |
    */

    'group_member_model' => null,

    /*
    |--------------------------------------------------------------------------
    | Two-factor exemption routes
    |--------------------------------------------------------------------------
    |
    | Named routes skipped by EnsureTwoFactorIsEnabled. This is the only grace
    | (design R9). Products may append; they should not remove the defaults
    | without a dated decision.
    |
    */

    'two_factor' => [
        'exempt_route_names' => [
            'logout',
            'security.*',
            'two-factor.*',
            'passkey.*',
            'password.confirm*',
            // Signaculum: the password and profile pages a user may need before enrolling.
            'user-password.update',
            'profile.edit',
            'profile.update',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy Gate::before and shadow mode (externa only, removed in v2)
    |--------------------------------------------------------------------------
    |
    | Design §9.2 step 6 / §9.4. use_package_gate = false lets the product's
    | bound Qiick\Access\Contracts\LegacyGateBefore decide instead of
    | GateBefore; gate_shadow = true runs the other callback too and logs each
    | disagreement (ability, actor kind, credential UUID, argument types; no
    | secrets) on gate_shadow_log_channel (null: the default channel). Both
    | switches must be real booleans: "true" / "false" in .env are, "1" / "0"
    | are strings and fail at boot. Other products leave the defaults.
    |
    */

    'use_package_gate' => env('ACCESS_USE_PACKAGE_GATE', true),

    'gate_shadow' => env('ACCESS_GATE_SHADOW', false),

    'gate_shadow_log_channel' => env('ACCESS_GATE_SHADOW_LOG_CHANNEL'),

];
