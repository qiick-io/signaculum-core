<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);
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
