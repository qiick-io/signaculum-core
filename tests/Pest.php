<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\SigningKey;
use App\Models\User;
use App\Services\SigningKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
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
 * An instance admin and a key they issued, with its plain-text token.
 *
 * @param  list<string>  $scopes
 * @return array{admin: User, plain: string, apiKey: ApiKey}
 */
function adminApiKey(array $scopes = [ApiKeyScopeEnum::UsersWrite->value], ?ApiKeyData $apiKeyData = null): array
{
    $admin = User::factory()->create(['is_instance_admin' => true]);
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, $apiKeyData ?? new ApiKeyData('Test key', $scopes));

    return [
        'admin' => $admin,
        'plain' => $issuedApiKey->plainTextToken,
        'apiKey' => $issuedApiKey->apiKey,
    ];
}
