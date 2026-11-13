#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Seeds the OpenID Foundation conformance user and static clients.
 *
 * Fixed UUIDs and secrets must match the workflow env and the rendered plan
 * configs. Relax PKCE/nonce only in that environment.
 */

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $application */
$application = require __DIR__.'/../bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$alias = getenv('CONFORMANCE_ALIAS') ?: 'signaculum';
$suiteBaseUrl = rtrim(getenv('CONFORMANCE_SUITE_PUBLIC_URL') ?: 'https://nginx:8443', '/');
$backchannelBaseUrl = rtrim(getenv('CONFORMANCE_BACKCHANNEL_BASE_URL') ?: 'https://localhost:8443', '/');
$email = getenv('OIDC_TEST_USER') ?: 'conformance@signaculum.test';
$password = getenv('OIDC_TEST_PASSWORD') ?: 'ConformanceTest-Password-1';

$user = User::query()->updateOrCreate(
    ['email' => $email],
    [
        'first_name' => 'Conformance',
        'last_name' => 'Tester',
        'password' => $password,
        'email_verified_at' => now(),
        'is_active' => true,
        'is_instance_admin' => false,
        'locale' => 'en',
        'failed_login_attempts' => 0,
        'locked_until' => null,
    ],
);

/**
 * @param  list<string>  $postLogoutRedirectUris
 */
function seedConformanceClient(
    string $clientId,
    string $plainSecret,
    string $name,
    string $redirectUri,
    array $postLogoutRedirectUris,
    ?string $backchannelLogoutUri,
): Client {
    $client = Client::query()->find($clientId) ?? new Client;
    $client->forceFill([
        'id' => $clientId,
        'name' => $name,
        'secret' => $plainSecret,
        'redirect_uris' => [$redirectUri],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'is_first_party' => false,
        'post_logout_redirect_uris' => $postLogoutRedirectUris,
        'backchannel_logout_uri' => $backchannelLogoutUri,
        'is_backchannel_logout_session_required' => $backchannelLogoutUri !== null,
    ])->save();

    return $client->refresh();
}

$basicCallback = "{$suiteBaseUrl}/test/a/{$alias}/callback";
$logoutAlias = "{$alias}-logout";
$backchannelAlias = "{$alias}-backchannel";

seedConformanceClient(
    getenv('OIDC_CLIENT_ID_1') ?: '11111111-1111-4111-8111-111111111111',
    getenv('OIDC_CLIENT_SECRET_1') ?: 'signaculum-conformance-basic-secret-00001',
    'OIDF Basic OP 1',
    $basicCallback,
    [],
    null,
);

seedConformanceClient(
    getenv('OIDC_CLIENT_ID_2') ?: '22222222-2222-4222-8222-222222222222',
    getenv('OIDC_CLIENT_SECRET_2') ?: 'signaculum-conformance-basic-secret-00002',
    'OIDF Basic OP 2',
    $basicCallback,
    [],
    null,
);

seedConformanceClient(
    getenv('OIDC_LOGOUT_CLIENT_ID') ?: '33333333-3333-4333-8333-333333333333',
    getenv('OIDC_LOGOUT_CLIENT_SECRET') ?: 'signaculum-conformance-logout-secret-00001',
    'OIDF RP-Initiated Logout',
    "{$suiteBaseUrl}/test/a/{$logoutAlias}/callback",
    ["{$suiteBaseUrl}/test/a/{$logoutAlias}/post_logout_redirect"],
    null,
);

seedConformanceClient(
    getenv('OIDC_BACKCHANNEL_CLIENT_ID') ?: '44444444-4444-4444-8444-444444444444',
    getenv('OIDC_BACKCHANNEL_CLIENT_SECRET') ?: 'signaculum-conformance-backchannel-secret-00001',
    'OIDF Back-Channel Logout',
    "{$suiteBaseUrl}/test/a/{$backchannelAlias}/callback",
    ["{$suiteBaseUrl}/test/a/{$backchannelAlias}/post_logout_redirect"],
    "{$backchannelBaseUrl}/test/a/{$backchannelAlias}/backchannel_logout",
);

fwrite(STDOUT, "Seeded conformance user {$user->email} and four OIDC clients for alias {$alias}.\n");
