<?php

declare(strict_types=1);

use App\Enums\OidcScopeEnum;
use App\Models\User;
use App\Services\OidcClaimsService;

it('emits preferred_username when the username is set', function (): void {
    $user = User::factory()->create(['username' => 'ada.lovelace']);

    $claims = app(OidcClaimsService::class)->claimsFor($user, [OidcScopeEnum::Profile->value]);

    expect($claims)->toHaveKey('preferred_username', 'ada.lovelace');
});

it('omits preferred_username when the username is null', function (): void {
    $user = User::factory()->create(['username' => null]);

    $claims = app(OidcClaimsService::class)->claimsFor($user, [OidcScopeEnum::Profile->value]);

    expect($claims)->not->toHaveKey('preferred_username');
});
