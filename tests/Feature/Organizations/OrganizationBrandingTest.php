<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationBrandingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Per-organisation branding on login/consent (#67).
 */
function brandingOwner(): User
{
    return User::factory()->create();
}

function brandingOrganizationFor(User $owner): Organization
{
    $organization = Organization::factory()->create();
    $organization->users()->attach($owner->id, ['roles' => ['owner']]);

    return $organization;
}

test('an organisation owner can save brand colours and logos', function () {
    Storage::fake('local');

    $owner = brandingOwner();
    $organization = brandingOrganizationFor($owner);

    $this->actingAs($owner)
        ->put(route('organizations.branding.update', $organization), [
            'brand_color' => '#A24A7B',
            'brand_color_dark' => '#DC77AD',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 40),
        ])
        ->assertRedirect(route('organizations.settings', $organization));

    $organization->refresh();

    expect($organization->brand_color)->toBe('#A24A7B')
        ->and($organization->brand_color_dark)->toBe('#DC77AD')
        ->and($organization->logo_path)->not->toBeNull();

    Storage::disk('local')->assertExists($organization->logo_path);

    $this->get(route('organizations.branding.show', [
        'organization' => $organization->slug,
        'asset' => 'logo',
    ]))->assertOk();
});

test('a non member cannot update organisation branding', function () {
    $organization = Organization::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->put(route('organizations.branding.update', $organization), [
            'brand_color' => '#A24A7B',
        ])
        ->assertForbidden();
});

test('login with organization query uses that org branding and falls back to instance', function () {
    Storage::fake('local');

    $organization = Organization::factory()->create([
        'brand_color' => '#112233',
        'logo_path' => null,
    ]);

    $this->get(route('login', ['organization' => $organization->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->where('projectAppearance.organizationSlug', $organization->slug)
            ->where('projectAppearance.themeCss.brandPrimary', '#112233'));

    expect(session(OrganizationBrandingService::SESSION_SLUG_KEY))->toBe($organization->slug);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projectAppearance.organizationSlug', $organization->slug));
});

test('a non email login_hint captures organisation branding context', function () {
    $organization = Organization::factory()->create(['brand_color' => '#445566']);

    $this->get(route('login', ['login_hint' => $organization->slug]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projectAppearance.organizationSlug', $organization->slug)
            ->where('projectAppearance.themeCss.brandPrimary', '#445566'));
});

test('an email login_hint does not capture organisation branding', function () {
    Organization::factory()->create(['slug' => 'acme']);

    $this->get(route('login', ['login_hint' => 'user@example.com']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projectAppearance.organizationSlug', null));
});

test('unknown organisation slug leaves instance branding', function () {
    $this->get(route('login', ['organization' => 'missing-org']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('projectAppearance.organizationSlug', null));
});
