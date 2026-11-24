<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\UpdateLdapSettingsAction;
use App\Data\ApiKeyData;
use App\Data\LdapSettingsData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\LdapEncryptionEnum;
use App\Enums\OrganizationRoleEnum;
use App\Enums\RoleEnum;
use App\Enums\WebhookEventEnum;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SigningKey;
use App\Models\User;
use App\Models\Webhook;
use App\Services\SigningKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// Two-session races need committed rows that a second connection can see.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Concurrency');

/*
|--------------------------------------------------------------------------
| OpenID Connect helpers
|--------------------------------------------------------------------------
|
| A relying party in a few functions: register a client, build PKCE pairs, walk the
| authorization code flow through the consent screen and redeem the code.
|
*/

const TEST_REDIRECT_URI = 'https://client.example.test/auth/oidc/callback';

/**
 * Register an authorization code client (confidential by default).
 */
function createOidcClient(bool $isConfidential = true): Client
{
    return app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Example app',
        [TEST_REDIRECT_URI],
        $isConfidential,
    );
}

/**
 * A PKCE verifier and its S256 challenge (RFC 7636 §4.1, §4.2).
 *
 * @return array{verifier: string, challenge: string}
 */
function pkcePair(): array
{
    $verifier = Str::random(64);

    return [
        'verifier' => $verifier,
        'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    ];
}

/**
 * Query string of an authorization request with the qiick profile; override any value.
 *
 * @param  array<string, string|null>  $overrides  a null value removes the parameter
 */
function authorizeUrl(Client $client, string $codeChallenge, array $overrides = []): string
{
    $parameters = array_filter([
        'response_type' => 'code',
        'client_id' => $client->getKey(),
        'redirect_uri' => TEST_REDIRECT_URI,
        'scope' => 'openid profile email',
        'state' => 'state-123',
        'nonce' => 'nonce-456',
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        ...$overrides,
    ], fn (?string $value): bool => $value !== null);

    return '/oauth/authorize?'.http_build_query($parameters);
}

/**
 * Walk the authorization request and the consent screen as the current user, and
 * return the query parameters the client receives on its redirect URI. Passport skips
 * the consent screen when the user already granted these scopes to the client.
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, string>
 */
function approveAuthorization(Client $client, string $codeChallenge, array $overrides = []): array
{
    $authorization = test()->get(authorizeUrl($client, $codeChallenge, $overrides));

    if ($authorization->isRedirect()) {
        return redirectQuery($authorization->headers->get('Location'));
    }

    $authorization->assertOk();

    $approval = test()->post('/oauth/authorize', ['auth_token' => session('authToken')]);

    return redirectQuery($approval->headers->get('Location'));
}

/**
 * @return array<string, string>
 */
function redirectQuery(?string $location): array
{
    expect($location)->toStartWith(TEST_REDIRECT_URI);

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    /** @var array<string, string> $query */
    return $query;
}

/**
 * Redeem an authorization code at the token endpoint.
 */
function redeemCode(Client $client, string $code, string $codeVerifier): TestResponse
{
    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'redirect_uri' => TEST_REDIRECT_URI,
        'code' => $code,
        'code_verifier' => $codeVerifier,
    ]);
}

/**
 * Refresh the given refresh token for the test case's client and return the response.
 */
function refreshTokens(object $testCase, string $refreshToken): TestResponse
{
    return $testCase->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $testCase->client->getKey(),
        'client_secret' => $testCase->client->plainSecret,
        'refresh_token' => $refreshToken,
    ]);
}

/**
 * Move the stored expiries of the code and of every token `$days` into the past, or only
 * those of the revoked (rotated) tokens. League stamps expiries with the real clock
 * (`new DateTimeImmutable()`), which travel() does not move, so ageing the rows keeps
 * the grants and the purge on one clock.
 *
 * Only the rows age: the `expire_time` inside an issued (encrypted) token does not, so
 * league still accepts an aged token that has not really expired. Never expect league
 * to answer `expired` for a token aged here.
 */
function ageTokenRows(int $days, bool $isRotatedOnly = false): void
{
    foreach ([Passport::authCode(), Passport::token(), Passport::refreshToken()] as $tokenModel) {
        $tokenModel->newQuery()
            ->when($isRotatedOnly, fn ($query) => $query->where('revoked', true))
            ->get()
            ->each(fn (Model $tokenRow) => $tokenModel->newQuery()
                ->whereKey($tokenRow->getKey())
                ->update(['expires_at' => $tokenRow->getAttribute('expires_at')->subDays($days)]));
    }
}

/**
 * @return array{int, int, int} the code, access token and refresh token row counts
 */
function tokenRowCounts(): array
{
    return [
        Passport::authCode()->newQuery()->count(),
        Passport::token()->newQuery()->count(),
        Passport::refreshToken()->newQuery()->count(),
    ];
}

/*
| RFC 8628 device authorization grant: a public device client, its device code, the
| user's approval on the consent screen and the polling of the token endpoint.
*/

function deviceAuthorizationClient(): Client
{
    $client = app(ClientRepository::class)->createDeviceAuthorizationGrantClient(
        'CLI device',
        confidential: false,
    );

    return $client;
}

/**
 * Issue a device code for the given client and scopes.
 *
 * @return array{device_code: string, user_code: string, interval: int}
 */
function issueDeviceCode(Client $client, string $scope = 'openid profile'): array
{
    $response = test()->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => $scope,
    ])->assertOk();

    return [
        'device_code' => (string) $response->json('device_code'),
        'user_code' => (string) $response->json('user_code'),
        'interval' => (int) $response->json('interval'),
    ];
}

/**
 * Open the device consent screen and approve (or deny) as the current user.
 */
function approveDeviceAuthorization(string $userCode, bool $isApproved = true): void
{
    test()->get(route('passport.device.authorizations.authorize', ['user_code' => $userCode]))
        ->assertOk();

    $authToken = session('authToken');

    expect($authToken)->toBeString()->not->toBeEmpty();

    if ($isApproved) {
        test()->post(route('passport.device.authorizations.approve'), [
            'auth_token' => $authToken,
        ])->assertRedirect();

        return;
    }

    test()->delete(route('passport.device.authorizations.deny'), [
        'auth_token' => $authToken,
    ])->assertRedirect();
}

/**
 * Poll the token endpoint with a device code (RFC 8628 §3.4).
 */
function redeemDeviceCode(Client $client, string $deviceCode): TestResponse
{
    return test()->post('/oauth/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'client_id' => $client->getKey(),
        'device_code' => $deviceCode,
    ]);
}

/**
 * A user approves a device code for a new public device client, which redeems it and
 * refreshes once (the first refresh token rotates). Sets `client` on the test case for
 * refreshTokens().
 *
 * @return array{device_code: string, issued: array<string, mixed>, refreshed: array<string, mixed>}
 */
function deviceTokenFamily(object $testCase): array
{
    $testCase->client = deviceAuthorizationClient();
    $testCase->actingAs(User::factory()->create());

    $device = issueDeviceCode($testCase->client);
    approveDeviceAuthorization($device['user_code']);

    $issuedTokens = redeemDeviceCode($testCase->client, $device['device_code'])->assertOk()->json();
    $refreshedTokens = refreshTokens($testCase, $issuedTokens['refresh_token'])->assertOk()->json();

    return ['device_code' => $device['device_code'], 'issued' => $issuedTokens, 'refreshed' => $refreshedTokens];
}

/**
 * The family's newest access token is refused and its newest refresh token no longer refreshes.
 *
 * @param  array<string, mixed>  $refreshedTokens
 */
function assertDeviceFamilyRevoked(object $testCase, array $refreshedTokens): void
{
    app('auth')->forgetGuards();

    $testCase->withToken($refreshedTokens['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();
    refreshTokens($testCase, $refreshedTokens['refresh_token'])->assertStatus(400);
}

/**
 * Parse an id_token and check its RS256 signature with the stored key of its `kid`.
 */
function verifiedIdToken(string $idToken): UnencryptedToken
{
    $parser = new Parser(new JoseEncoder);
    $token = $parser->parse($idToken);

    expect($token)->toBeInstanceOf(UnencryptedToken::class);
    /** @var UnencryptedToken $token */
    $signingKey = SigningKey::query()->where('kid', $token->headers()->get('kid'))->firstOrFail();

    expect((new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText($signingKey->public_key))))->toBeTrue();

    return $token;
}

/**
 * Mint an RS256 JWT with the active signing key (for id_token_hint tests).
 *
 * @param  array<string, mixed>  $claimOverrides
 */
function mintSignedHint(User $user, string $audience, array $claimOverrides = []): string
{
    $signingKey = app(SigningKeyService::class)->activeKey();
    $jwtConfiguration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($signingKey->private_key),
        InMemory::plainText($signingKey->public_key),
    );

    $issuedAt = new DateTimeImmutable('@'.time());
    $claims = array_merge([
        'iss' => (string) config('oidc.issuer', 'https://id.example.test'),
        'sub' => $user->subject,
        'aud' => $audience,
        'exp' => $issuedAt->modify('+120 seconds'),
        'iat' => $issuedAt,
    ], $claimOverrides);

    $tokenBuilder = $jwtConfiguration->builder(ChainedFormatter::withUnixTimestampDates())
        ->withHeader('kid', $signingKey->kid)
        ->issuedBy((string) $claims['iss'])
        ->issuedAt($claims['iat'] instanceof DateTimeImmutable ? $claims['iat'] : $issuedAt)
        ->expiresAt($claims['exp'] instanceof DateTimeImmutable ? $claims['exp'] : $issuedAt->modify('+120 seconds'));

    if (is_string($claims['sub']) && $claims['sub'] !== '') {
        $tokenBuilder = $tokenBuilder->relatedTo($claims['sub']);
    }

    $audienceClaim = $claims['aud'] ?? null;

    if (is_string($audienceClaim) && $audienceClaim !== '') {
        $tokenBuilder = $tokenBuilder->permittedFor($audienceClaim);
    } elseif (is_array($audienceClaim)) {
        foreach ($audienceClaim as $audienceValue) {
            if (is_string($audienceValue) && $audienceValue !== '') {
                $tokenBuilder = $tokenBuilder->permittedFor($audienceValue);
            }
        }
    } elseif ($audienceClaim !== null) {
        $tokenBuilder = $tokenBuilder->withClaim('aud', $audienceClaim);
    }

    if (array_key_exists('sid', $claims) && is_string($claims['sid'])) {
        $tokenBuilder = $tokenBuilder->withClaim('sid', $claims['sid']);
    }

    return $tokenBuilder
        ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey())
        ->toString();
}

/**
 * An Admin API key expiry well inside the maximum lifetime: 30 days from now.
 */
function apiKeyExpiry(): CarbonImmutable
{
    return CarbonImmutable::now()->addDays(30);
}

/**
 * {@see apiKeyExpiry()} as the console form posts it.
 */
function apiKeyExpiryInput(): string
{
    return apiKeyExpiry()->toDateTimeString();
}

/**
 * An instance admin and a key they issued, with its plain-text token.
 *
 * @param  list<string>  $scopes
 * @return array{admin: User, plain: string, apiKey: ApiKey}
 */
function adminApiKey(array $scopes = [ApiKeyScopeEnum::UsersWrite->value], ?ApiKeyData $apiKeyData = null): array
{
    syncPermissions();
    $admin = User::factory()->instanceAdmin()->create();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, $apiKeyData ?? new ApiKeyData('Test key', $scopes, expiresAt: apiKeyExpiry()));

    return [
        'admin' => $admin,
        'plain' => $issuedApiKey->plainTextToken,
        'apiKey' => $issuedApiKey->apiKey,
    ];
}

/*
|--------------------------------------------------------------------------
| Shared fixtures
|--------------------------------------------------------------------------
|
| Helpers used by more than one test file live here: a parallel worker loads only the
| test files it runs, so a helper declared in another file is undefined there (#144).
|
*/

/**
 * Idempotent permission + built-in role sync for a fresh RefreshDatabase schema.
 */
function syncPermissions(): void
{
    test()->artisan('signaculum:sync-permissions')->assertSuccessful();
}

/**
 * A user who may manage the instance (super-admin role).
 */
function instanceAdmin(): User
{
    syncPermissions();

    return User::factory()->instanceAdmin()->create();
}

/**
 * A user with the admin role (every permission, no Gate::before bypass beyond grants).
 */
function permissionAdmin(): User
{
    syncPermissions();

    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Admin->value);

    return $user;
}

/**
 * A user with the reader role (show-only permissions).
 */
function permissionReader(): User
{
    syncPermissions();

    $user = User::factory()->create();
    $user->assignRole(RoleEnum::Reader->value);

    return $user;
}

/**
 * An outbound webhook endpoint with a stable URL and signing secret for settings tests.
 *
 * @param  array<string, mixed>  $attributes
 */
function existingWebhook(array $attributes = []): Webhook
{
    return Webhook::query()->create([
        'url' => 'https://hooks.example.com/existing',
        'secret' => 'existing-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
        ...$attributes,
    ]);
}

/**
 * An organisation with `$owner` as its only member, in the owner role.
 *
 * @param  array<string, mixed>  $attributes
 */
function createOrganizationWithOwner(User $owner, array $attributes = []): Organization
{
    $organization = Organization::factory()->create($attributes);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $owner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    return $organization;
}

/**
 * LDAP tests: point LdapRecord at an in-memory emulated directory.
 *
 * Mirrors the connection shape `LdapConnectionService` writes, so a test that never
 * calls the service still talks to the emulator instead of a socket.
 */
function fakeLdapDirectory(): void
{
    config([
        'ldap.default' => 'default',
        'ldap.connections.default' => [
            'hosts' => ['127.0.0.1'],
            'username' => 'cn=admin,dc=local,dc=com',
            'password' => 'secret',
            'port' => 636,
            'base_dn' => 'dc=local,dc=com',
            'timeout' => 5,
            'use_tls' => true,
            'use_starttls' => false,
            'use_sasl' => false,
        ],
        'ldap.logging.enabled' => false,
    ]);

    DirectoryEmulator::setup('default');
}

/**
 * Store LDAP connector settings, enabled over TLS by default.
 *
 * @param  array<string, mixed>  $overrides  any `LdapSettingsData` constructor argument
 */
function enableLdapSettings(array $overrides = []): void
{
    $defaults = [
        'isEnabled' => true,
        'hosts' => ['127.0.0.1'],
        'port' => 636,
        'encryption' => LdapEncryptionEnum::Tls,
        'bindDn' => 'cn=admin,dc=local,dc=com',
        'bindPassword' => 'bind-secret-value',
        'baseDn' => 'dc=local,dc=com',
        'userFilter' => '(objectClass=person)',
        'attributeMail' => 'mail',
        'attributeGivenName' => 'givenName',
        'attributeFamilyName' => 'sn',
        'attributeDisplayName' => 'displayName',
        'attributeUsername' => 'uid',
        'attributeGuid' => 'objectGUID',
        'syncIntervalMinutes' => 60,
    ];

    app(UpdateLdapSettingsAction::class)->execute(new LdapSettingsData(...array_merge($defaults, $overrides)));
}

/**
 * A valid Authentication & security settings form; override any field.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function securitySettingsPayload(array $overrides = []): array
{
    return [
        'password_min_length' => 8,
        'password_require_mixed_case' => '1',
        'password_require_numbers' => '1',
        'password_require_symbols' => '0',
        'password_reject_compromised' => '0',
        'login_max_attempts' => 3,
        'login_lockout_threshold' => 5,
        'login_lockout_minutes' => 15,
        'registration_enabled' => '0',
        'two_factor_required' => '0',
        'email_code_login_enabled' => '0',
        ...$overrides,
    ];
}
