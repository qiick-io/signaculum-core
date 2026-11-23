<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * Run the flow for the given scopes and return the access token.
 */
function accessTokenFor(object $testCase, string $scope): string
{
    $testCase->actingAs($testCase->user);

    $nonce = str_contains($scope, 'openid') ? 'nonce-456' : null;
    $code = approveAuthorization($testCase->client, $testCase->pkce['challenge'], ['scope' => $scope, 'nonce' => $nonce])['code'];

    return redeemCode($testCase->client, $code, $testCase->pkce['verifier'])->json('access_token');
}

test('userinfo returns the claims of the granted scopes', function () {
    $accessToken = accessTokenFor($this, 'openid profile email');
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson([
            'sub' => $this->user->subject,
            'name' => 'Ada Lovelace',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'updated_at' => $this->user->updated_at->getTimestamp(),
            'email' => 'ada@example.test',
            'email_verified' => true,
        ]);
});

test('the locale claim follows the user language', function () {
    $this->user->forceFill(['locale' => 'it'])->save();
    $accessToken = accessTokenFor($this, 'openid profile');
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('locale', 'it');
});

test('userinfo also accepts POST', function () {
    $accessToken = accessTokenFor($this, 'openid');
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->post('/oauth/userinfo')
        ->assertOk()
        ->assertExactJson(['sub' => $this->user->subject]);
});

// RFC 6750 §2.2 and OIDC Core §5.3.1: the access token may come in a form-encoded POST body.
test('userinfo accepts the access token in a form body', function () {
    $accessToken = accessTokenFor($this, 'openid');
    app('auth')->forgetGuards();

    $this->call('POST', '/oauth/userinfo', ['access_token' => $accessToken], server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])
        ->assertOk()
        ->assertExactJson(['sub' => $this->user->subject]);
});

// RFC 6750 §2 and §3.1: a token sent with more than one method is an invalid request.
test('userinfo refuses an access token in both the header and the body', function () {
    $accessToken = accessTokenFor($this, 'openid');
    app('auth')->forgetGuards();

    $this->call('POST', '/oauth/userinfo', ['access_token' => 'other-token'], server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_request"');
});

test('userinfo reads the form content type case-insensitively', function () {
    $accessToken = accessTokenFor($this, 'openid');
    app('auth')->forgetGuards();

    $this->call('POST', '/oauth/userinfo', ['access_token' => $accessToken], server: [
        'CONTENT_TYPE' => 'Application/X-WWW-Form-Urlencoded; charset=UTF-8',
    ])->assertOk();
});

test('userinfo ignores an access token in the query string', function () {
    $accessToken = accessTokenFor($this, 'openid');
    app('auth')->forgetGuards();

    $this->get('/oauth/userinfo?access_token='.$accessToken)->assertUnauthorized();
});

// OIDC Core §5.5: a claim asked for at userinfo is released even without its scope in `scope`.
test('userinfo releases an essential claim requested with the claims parameter', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid',
        'claims' => json_encode(['userinfo' => ['name' => ['essential' => true]]]),
    ])['code'];
    $accessToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('access_token');
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('name', 'Ada Lovelace')
        ->assertJsonMissingPath('email');
});

test('userinfo without a token is a bearer challenge', function () {
    $this->get('/oauth/userinfo')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
});

test('userinfo with an invalid token is a bearer challenge', function () {
    $this->withToken('not-a-token')
        ->get('/oauth/userinfo')
        ->assertUnauthorized();
});

test('userinfo needs the openid scope', function () {
    $accessToken = accessTokenFor($this, 'profile');
    app('auth')->forgetGuards();

    $this->withToken($accessToken)
        ->get('/oauth/userinfo')
        ->assertForbidden()
        ->assertJson(['error' => 'insufficient_scope']);
});

// RFC 6750 §3.1: a malformed body token is an invalid request, answered with the bearer challenge.
test('userinfo refuses a body access token that is not a single value', function () {
    $this->call('POST', '/oauth/userinfo', ['access_token' => ['first-token', 'second-token']], server: [
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request')
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_request"');
});
