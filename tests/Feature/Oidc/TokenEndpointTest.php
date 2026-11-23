<?php

declare(strict_types=1);

use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('the token response carries a signed id_token for openid requests', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    $response = redeemCode($this->client, $code, $this->pkce['verifier'])
        ->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token', 'id_token']);

    $idToken = verifiedIdToken($response->json('id_token'));

    expect($idToken->headers()->get('alg'))->toBe('RS256')
        ->and($idToken->headers()->get('typ'))->toBe('JWT');
});

test('the id_token carries the standard claims and echoes the nonce', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    // Freeze before redeem so exact iat/exp toBe assertions cannot race the wall clock (#170).
    $this->freezeTime();

    $idToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token');
    $claims = verifiedIdToken($idToken)->claims();
    $rawPayload = json_decode(base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);

    expect($rawPayload['aud'])->toBe($this->client->getKey())
        ->and($rawPayload['iat'])->toBeInt()
        ->and($claims->get('iss'))->toBe('https://id.example.test')
        ->and($claims->get('sub'))->toBe($this->user->subject)
        ->and($claims->get('aud'))->toBe([$this->client->getKey()])
        ->and($claims->get('azp'))->toBe($this->client->getKey())
        ->and($claims->get('nonce'))->toBe('nonce-456')
        ->and($claims->get('iat')->getTimestamp())->toBe(Date::now()->getTimestamp())
        ->and($claims->get('exp')->getTimestamp())->toBe(Date::now()->getTimestamp() + 600);
});

test('the subject is an opaque uuid, not the database id', function () {
    expect($this->user->subject)->toBeString()
        ->and(Str::isUuid($this->user->subject))->toBeTrue()
        ->and($this->user->subject)->not->toBe((string) $this->user->id);
});

// OIDC Core §5.4: with an access token, scope claims are released at userinfo, not in the id_token.
test('the id_token carries no profile or email claims unless the claims parameter asks', function () {
    $this->actingAs($this->user);

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->has('name'))->toBeFalse()
        ->and($claims->has('given_name'))->toBeFalse()
        ->and($claims->has('email'))->toBeFalse()
        ->and($claims->has('email_verified'))->toBeFalse();
});

// OIDC Core §5.5: claims asked for in the id_token member are released there.
test('the claims parameter puts the requested claims in the id_token', function () {
    $this->actingAs($this->user);

    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid',
        'claims' => json_encode(['id_token' => ['email' => ['essential' => true], 'name' => null]]),
    ])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('email'))->toBe('ada@example.test')
        ->and($claims->get('name'))->toBe('Ada Lovelace')
        ->and($claims->has('email_verified'))->toBeFalse()
        ->and($claims->has('given_name'))->toBeFalse();
});

test('unknown claims and value constraints in the claims parameter are ignored', function () {
    $this->actingAs($this->user);

    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid',
        'claims' => json_encode([
            'id_token' => ['shoe_size' => ['essential' => true], 'email' => ['value' => 'someone@else.test']],
            'userinfo' => ['favourite_colour' => null],
        ]),
    ])['code'];
    $response = redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();
    $claims = verifiedIdToken($response->json('id_token'))->claims();

    expect($claims->has('shoe_size'))->toBeFalse()
        ->and($claims->get('email'))->toBe('ada@example.test');
});

test('auth_time is the moment the user logged in', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:05:00');
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('auth_time'))->toBe(Date::parse('2026-11-05 10:00:00')->getTimestamp());
});

test('a wrong code verifier is refused', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    redeemCode($this->client, $code, pkcePair()['verifier'])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_grant']);
});

test('a missing code verifier is refused', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
        'redirect_uri' => TEST_REDIRECT_URI,
        'code' => $code,
    ])->assertStatus(400)->assertJson(['error' => 'invalid_request']);
});

test('a code can be redeemed only once', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertStatus(400);
});

test('a wrong client secret is refused', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    $this->client->plainSecret = 'wrong-secret';

    redeemCode($this->client, $code, $this->pkce['verifier'])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_client']);
});

test('a code issued to one client cannot be redeemed by another', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];

    redeemCode(createOidcClient(), $code, $this->pkce['verifier'])->assertStatus(400);
});

test('a public client redeems its code with PKCE alone', function () {
    $publicClient = createOidcClient(isConfidential: false);
    $this->actingAs($this->user);
    $code = approveAuthorization($publicClient, $this->pkce['challenge'])['code'];

    $response = redeemCode($publicClient, $code, $this->pkce['verifier'])->assertOk();

    expect(verifiedIdToken($response->json('id_token'))->claims()->get('aud'))->toBe([$publicClient->getKey()]);
});

test('requests without the openid scope get no id_token', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'], ['scope' => 'profile', 'nonce' => null])['code'];

    $response = redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    expect($response->json())->not->toHaveKey('id_token');
});

test('a refreshed token response carries no id_token', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $refreshToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('refresh_token');

    $response = $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
        'refresh_token' => $refreshToken,
    ])->assertOk();

    expect($response->json())->not->toHaveKey('id_token');
});

test('the nonce of one authorization is never reused for another client', function () {
    $otherClient = createOidcClient();
    $this->actingAs($this->user);

    // A pending authorization for another client leaves its nonce in the session.
    $this->get(authorizeUrl($otherClient, $this->pkce['challenge'], ['nonce' => 'other-nonce']))->assertOk();

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('nonce'))->toBe('nonce-456');
});

test('the id_token is signed with the newest key after a rotation', function () {
    $this->actingAs($this->user);
    $this->artisan('signaculum:rotate-signing-key');
    $this->artisan('signaculum:rotate-signing-key');

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $idToken = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'));

    expect($idToken->headers()->get('kid'))->toBe(SigningKey::query()->active()->sole()->kid);
});

// RFC 6749 §4.1.2 and RFC 9700 §4.5: a code redeemed twice has leaked, so the tokens it issued are revoked.
test('redeeming a code a second time revokes the tokens it issued', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $tokens = redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk()->json();

    redeemCode($this->client, $code, $this->pkce['verifier'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    app('auth')->forgetGuards();

    $this->withToken($tokens['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();

    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
        'refresh_token' => $tokens['refresh_token'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('a code redeemed once leaves its access token valid', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $accessToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('access_token');

    app('auth')->forgetGuards();

    $this->withToken($accessToken)->getJson('/oauth/userinfo')->assertOk();
});

/**
 * Refresh the given refresh token for the test client and return the response.
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

// RFC 9700 §4.5: refresh tokens rotate, so the revocation follows the whole token family of the code.
test('redeeming a code again revokes the tokens refreshed from it', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $issuedTokens = redeemCode($this->client, $code, $this->pkce['verifier'])->json();
    $refreshedTokens = refreshTokens($this, $issuedTokens['refresh_token'])->assertOk()->json();

    redeemCode($this->client, $code, $this->pkce['verifier'])->assertStatus(400);

    app('auth')->forgetGuards();

    $this->withToken($refreshedTokens['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();
    refreshTokens($this, $refreshedTokens['refresh_token'])->assertStatus(400);
});

test('a replayed code leaves the tokens of other codes valid', function () {
    $this->actingAs($this->user);
    $replayedCode = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $replayedCode, $this->pkce['verifier'])->assertOk();

    $otherPkce = pkcePair();
    $otherCode = approveAuthorization($this->client, $otherPkce['challenge'])['code'];
    $otherAccessToken = redeemCode($this->client, $otherCode, $otherPkce['verifier'])->json('access_token');

    redeemCode($this->client, $replayedCode, $this->pkce['verifier'])->assertStatus(400);

    app('auth')->forgetGuards();

    $this->withToken($otherAccessToken)->getJson('/oauth/userinfo')->assertOk();
});

test('a code replayed after it expired still revokes its tokens', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $accessToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('access_token');

    $this->travel(11)->minutes();

    redeemCode($this->client, $code, $this->pkce['verifier'])->assertStatus(400);

    app('auth')->forgetGuards();

    $this->withToken($accessToken)->getJson('/oauth/userinfo')->assertUnauthorized();
});

// Two parallel exchanges of one code: the second one validates before the first one finishes.
test('a code claimed by a parallel exchange issues no tokens', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $authCodeId = Passport::authCode()->newQuery()->sole()->getKey();
    $hasParallelExchangeWon = false;

    // Right after league's "is the code revoked?" check, a parallel exchange redeems the code.
    DB::listen(function (QueryExecuted $query) use ($authCodeId, &$hasParallelExchangeWon): void {
        if ($hasParallelExchangeWon || ! str_contains($query->sql, 'oauth_auth_codes') || ! str_starts_with($query->sql, 'select exists')) {
            return;
        }

        $hasParallelExchangeWon = true;
        DB::table('oauth_auth_codes')->where('id', $authCodeId)->update(['revoked' => true]);
    });

    redeemCode($this->client, $code, $this->pkce['verifier'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect($hasParallelExchangeWon)->toBeTrue()
        ->and(Passport::token()->newQuery()->count())->toBe(0);
});
