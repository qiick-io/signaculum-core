<?php

declare(strict_types=1);

use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Support\Facades\Date;

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

test('profile and email claims follow the granted scopes', function () {
    $this->actingAs($this->user);

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('name'))->toBe('Ada Lovelace')
        ->and($claims->get('given_name'))->toBe('Ada')
        ->and($claims->get('family_name'))->toBe('Lovelace')
        ->and($claims->get('email'))->toBe('ada@example.test')
        ->and($claims->get('email_verified'))->toBeTrue();

    $openIdOnlyPkce = pkcePair();
    $code = approveAuthorization($this->client, $openIdOnlyPkce['challenge'], ['scope' => 'openid'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $openIdOnlyPkce['verifier'])->json('id_token'))->claims();

    expect($claims->has('name'))->toBeFalse()
        ->and($claims->has('email'))->toBeFalse();
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
