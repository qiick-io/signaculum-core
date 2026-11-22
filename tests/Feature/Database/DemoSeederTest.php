<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Storage::fake('local');
});

it('seeds two fictional organisations with every role, two applications and activity', function (): void {
    $this->seed(DemoSeeder::class);

    $acme = Organization::query()->where('slug', 'acme')->firstOrFail();

    expect($acme->is_mfa_required)->toBeTrue()
        ->and($acme->brand_color)->toBe('#C2410C')
        ->and($acme->logo_path)->not->toBeNull()
        ->and(Organization::query()->where('slug', 'globex')->exists())->toBeTrue();

    $acmeRoles = $acme->memberships()->pluck('roles')->flatten()->unique()->sort()->values()->all();
    expect($acmeRoles)->toBe(['admin', 'billing', 'member', 'owner']);

    expect(Client::query()->pluck('name')->sort()->values()->all())->toBe(['Excubia App', 'Externa']);

    // Only reserved example domains, so screenshots never show a real address.
    User::query()->pluck('email')->each(
        fn (string $email) => expect($email)->toEndWith('.example'),
    );

    expect(Activity::query()->where('log_name', 'auth')->where('event', 'login')->count())->toBeGreaterThan(0)
        ->and(Activity::query()->where('event', 'secret_rotated')->exists())->toBeTrue();
});
