<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

test('a signed-in user downloads a JSON account export without secrets', function () {
    $user = User::factory()->create([
        'email' => 'export@example.test',
        'first_name' => 'Export',
        'last_name' => 'User',
    ]);

    $organization = Organization::factory()->create([
        'name' => 'Export Org',
        'slug' => 'export-org',
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    $response = $this->actingAs($user)->get(route('profile.export'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');

    $payload = $response->streamedContent();
    $decoded = json_decode($payload, true);

    expect($decoded)->toBeArray()
        ->and($decoded['profile']['email'])->toBe('export@example.test')
        ->and($decoded['profile']['first_name'])->toBe('Export')
        ->and($decoded['organisations'])->toHaveCount(1)
        ->and($decoded['organisations'][0]['slug'])->toBe('export-org')
        ->and($decoded)->not->toHaveKey('password')
        ->and(json_encode($decoded))->not->toContain('two_factor');
});

test('guests cannot export account data', function () {
    $this->get(route('profile.export'))->assertRedirect(route('login'));
});
