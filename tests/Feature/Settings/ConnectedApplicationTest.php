<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * Sign the user in to the test client once, through the real authorization code flow.
 */
function connectApplication(object $testCase): void
{
    $testCase->actingAs($testCase->user);
    $code = approveAuthorization($testCase->client, $testCase->pkce['challenge'])['code'];
    redeemCode($testCase->client, $code, $testCase->pkce['verifier'])->assertOk();
}

test('connected apps lists the applications with live tokens', function () {
    connectApplication($this);

    $this->get(route('connected-applications.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/connected-applications')
            ->has('applications', 1)
            ->where('applications.0.clientId', $this->client->getKey())
            ->where('applications.0.name', 'Example app')
            ->where('applications.0.scopes', ['email', 'openid', 'profile']));
});

test('an application with only expired and revoked tokens is not listed', function () {
    connectApplication($this);

    DB::table('oauth_access_tokens')->update(['expires_at' => now()->subDay()]);
    DB::table('oauth_refresh_tokens')->update(['revoked' => true]);

    $this->get(route('connected-applications.index'))
        ->assertInertia(fn ($page) => $page->has('applications', 0));
});

test('removing access revokes the tokens and brings the consent screen back', function () {
    connectApplication($this);

    $this->delete(route('connected-applications.destroy', $this->client->getKey()))
        ->assertRedirect(route('connected-applications.index'));

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->exists())->toBeFalse()
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->exists())->toBeFalse();

    $this->get(authorizeUrl($this->client, pkcePair()['challenge']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

test('removing access leaves other users untouched', function () {
    connectApplication($this);
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->delete(route('connected-applications.destroy', $this->client->getKey()))
        ->assertRedirect();

    expect(DB::table('oauth_access_tokens')->where('user_id', $this->user->getKey())->where('revoked', false)->exists())->toBeTrue();
});
