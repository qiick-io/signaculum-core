<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Laravel\Passport\Client;

/**
 * Self-deletion must tear down live credentials before soft-delete (#213 re-review).
 */
beforeEach(function (): void {
    config(['oidc.issuer' => 'https://id.example.test']);

    // Keep a second super-admin so the target can be deleted.
    User::factory()->instanceAdmin()->create();
});

/**
 * @return array{access_token: string, refresh_token: string}
 */
function issueRefreshableGrant(User $user, Client $client): array
{
    test()->actingAs($user);
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $response = redeemCode($client, $code, $pkce['verifier'])->assertOk();

    return [
        'access_token' => (string) $response->json('access_token'),
        'refresh_token' => (string) $response->json('refresh_token'),
    ];
}

test('self-deletion revokes refresh tokens, api keys and deactivates membership principals', function (): void {
    $user = User::factory()->instanceAdmin()->create(['password' => 'password']);
    $organization = Organization::factory()->create();
    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $user,
        [OrganizationRoleEnum::Member->value],
    );
    $client = createOidcClient();
    $tokens = issueRefreshableGrant($user, $client);

    $issued = app(CreateApiKeyAction::class)->execute(
        $user,
        new ApiKeyData('Delete-me key', [ApiKeyScopeEnum::UsersRead->value]),
    );

    $principal = Principal::query()
        ->where('user_id', $user->getKey())
        ->where('organisation_id', $organization->getKey())
        ->firstOrFail();

    test()->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect(route('home'));

    test()->assertGuest();
    test()->assertSoftDeleted($user);

    $deleted = User::withTrashed()->whereKey($user->getKey())->firstOrFail();

    expect(User::query()->whereKey($user->getKey())->exists())->toBeFalse()
        ->and($deleted->is_active)->toBeFalse()
        ->and($principal->refresh()->deactivated_at)->not->toBeNull()
        ->and(ApiKey::query()->whereKey($issued->apiKey->getKey())->value('revoked_at'))->not->toBeNull();

    test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'refresh_token' => $tokens['refresh_token'],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});
