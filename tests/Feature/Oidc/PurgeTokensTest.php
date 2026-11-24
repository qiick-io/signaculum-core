<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Passport\Passport;

/**
 * `passport:purge` keeps every row a token family still needs to detect a replayed code
 * or a reused refresh token (RFC 9700 §4.5, §4.14.2), and deletes a family only once
 * none of its tokens can be used any more.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
    $this->actingAs($this->user);
    $this->code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $this->issuedTokens = redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertOk()->json();
    $this->refreshedTokens = refreshTokens($this, $this->issuedTokens['refresh_token'])->assertOk()->json();
});

/**
 * The family's newest access token is refused and its newest refresh token no longer refreshes.
 */
function assertFamilyRevoked(object $testCase): void
{
    app('auth')->forgetGuards();

    $testCase->withToken($testCase->refreshedTokens['access_token'])->getJson('/oauth/userinfo')->assertUnauthorized();
    refreshTokens($testCase, $testCase->refreshedTokens['refresh_token'])->assertStatus(400);
}

test('a code replayed after a purge of revoked rows still revokes its family', function (string $purgeOption) {
    $this->artisan('passport:purge', $purgeOption === '' ? [] : [$purgeOption => true])->assertSuccessful();

    redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertStatus(400);

    assertFamilyRevoked($this);
})->with(['--revoked' => '--revoked', 'without options' => '']);

test('a refresh token reused after a purge of revoked rows still revokes its family', function (string $purgeOption) {
    $this->artisan('passport:purge', $purgeOption === '' ? [] : [$purgeOption => true])->assertSuccessful();

    refreshTokens($this, $this->issuedTokens['refresh_token'])->assertStatus(400);

    assertFamilyRevoked($this);
})->with(['--revoked' => '--revoked', 'without options' => '']);

// The code (10 minutes) and the first access token (1 hour) expire long before the family's refresh token (30 days).
test('a refresh token reused after a purge of expired rows still revokes its family', function () {
    ageTokenRows(10);
    $this->refreshedTokens = refreshTokens($this, $this->refreshedTokens['refresh_token'])->assertOk()->json();

    $this->artisan('passport:purge', ['--expired' => true])->assertSuccessful();

    refreshTokens($this, $this->issuedTokens['refresh_token'])->assertStatus(400);

    assertFamilyRevoked($this);
});

test('a family with no usable token left is purged whole', function () {
    redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertStatus(400);

    $this->artisan('passport:purge', ['--revoked' => true])->assertSuccessful();

    expect(Passport::authCode()->newQuery()->count())->toBe(0)
        ->and(Passport::token()->newQuery()->count())->toBe(0)
        ->and(Passport::refreshToken()->newQuery()->count())->toBe(0);
});

// Past the 30 days of its refresh token and the 7 days of retention, no token of the family is usable.
test('a family whose tokens have all expired is purged whole', function (string $purgeOption) {
    ageTokenRows(30 + 8);

    $this->artisan('passport:purge', $purgeOption === '' ? [] : [$purgeOption => true])->assertSuccessful();

    expect(tokenRowCounts())->toBe([0, 0, 0]);
})->with(['--expired' => '--expired', '--revoked' => '--revoked', 'without options' => '']);

// A rotated refresh token that expired before the cutoff cannot be redeemed any more, so
// it is no longer needed to detect a reuse: an active family keeps a bounded number of rows.
test('the rotated tokens of a live family are purged once they expired', function () {
    foreach (range(1, 10) as $refreshCount) {
        $this->refreshedTokens = refreshTokens($this, $this->refreshedTokens['refresh_token'])->assertOk()->json();
    }

    ageTokenRows(30 + 8, isRotatedOnly: true);
    $recentlyRotatedRefreshToken = $this->refreshedTokens['refresh_token'];
    $this->refreshedTokens = refreshTokens($this, $recentlyRotatedRefreshToken)->assertOk()->json();

    $this->artisan('passport:purge')->assertSuccessful();

    // The code (the family lock), the recently rotated pair and the live pair.
    expect(tokenRowCounts())->toBe([1, 2, 2]);

    refreshTokens($this, $recentlyRotatedRefreshToken)->assertStatus(400);

    assertFamilyRevoked($this);
});

test('a negative or malformed retention is refused and purges nothing', function (string $hours) {
    $this->artisan('passport:purge', ['--hours' => $hours])->assertFailed();

    expect(tokenRowCounts())->toBe([1, 2, 2]);
})->with(['negative' => '-1', 'not a number' => 'a week']);

test('revoked tokens outside a code family are purged as before', function () {
    $standaloneAccessToken = Passport::token()->newQuery()->firstOrFail()->replicate()->fill([
        'id' => str_repeat('a', 80),
        'auth_code_id' => null,
        'revoked' => true,
    ]);
    $standaloneAccessToken->save();

    $this->artisan('passport:purge', ['--revoked' => true])->assertSuccessful();

    expect(Passport::token()->newQuery()->whereKey(str_repeat('a', 80))->exists())->toBeFalse()
        ->and(Passport::token()->newQuery()->count())->toBe(2);
});
