<?php

declare(strict_types=1);

use App\Services\ApiKeySecretService;
use Carbon\CarbonImmutable;

/**
 * Token format, hashing, verification and rotation on plain values only: no model, no table,
 * so another qiick product can take these tests with the service unchanged.
 */
const FLEET_TOKEN_PATTERN = '/^(ext|exc|ins|sig)_(live|test)_[A-Za-z0-9]{40,}$/';

beforeEach(function (): void {
    config([
        'api.key_prefix' => 'sig',
        'api.key_environment' => 'test',
        'api.key_rotation_grace_hours' => 24,
    ]);
});

test('issues a fleet-format token whose public prefix is not part of the secret', function (): void {
    $secret = app(ApiKeySecretService::class)->issue();

    expect($secret->plainTextToken)->toMatch(FLEET_TOKEN_PATTERN)
        ->and($secret->keyPrefix)->toMatch('/^sig_test_[A-Za-z0-9]{8}$/')
        ->and($secret->plainTextToken)->toStartWith($secret->keyPrefix)
        ->and(strlen($secret->plainTextToken) - strlen($secret->keyPrefix))->toBe(ApiKeySecretService::SECRET_LENGTH);
});

test('marks tokens issued in production as live', function (): void {
    config(['api.key_environment' => 'live']);

    expect(app(ApiKeySecretService::class)->issue()->keyPrefix)->toStartWith('sig_live_');
});

test('stores the sha-256 of the full token, never the token', function (): void {
    $secret = app(ApiKeySecretService::class)->issue();

    expect($secret->keyHash)->toBe(hash('sha256', $secret->plainTextToken))
        ->and($secret->keyHash)->not->toContain($secret->plainTextToken);
});

test('issues a different secret and id every time', function (): void {
    $service = app(ApiKeySecretService::class);
    $first = $service->issue();
    $second = $service->issue();

    expect($first->keyPrefix)->not->toBe($second->keyPrefix)
        ->and($first->plainTextToken)->not->toBe($second->plainTextToken);
});

test('finds the public prefix only in a well-formed token', function (string $presentedToken): void {
    expect(app(ApiKeySecretService::class)->keyPrefixOf($presentedToken))->toBeNull();
})->with([
    'empty' => '',
    'old sk_ format' => 'sk_'.str_repeat('a', 40),
    'unknown environment' => 'sig_prod_AbCd1234'.str_repeat('a', 32),
    'short secret' => 'sig_test_AbCd1234'.str_repeat('a', 31),
    'url-unsafe characters' => 'sig_test_AbCd1234'.str_repeat('a', 31).'/',
    'trailing newline' => 'sig_test_AbCd1234'.str_repeat('a', 32)."\n",
]);

test('verifies the current token', function (): void {
    $service = app(ApiKeySecretService::class);
    $secret = $service->issue();

    expect($service->matches($secret->plainTextToken, $secret->keyHash, null, null, CarbonImmutable::now()))->toBeTrue()
        ->and($service->matches($secret->plainTextToken.'x', $secret->keyHash, null, null, CarbonImmutable::now()))->toBeFalse();
});

test('refuses a token whose environment or id was edited, even with the right secret half', function (): void {
    $service = app(ApiKeySecretService::class);
    $secret = $service->issue();
    $secretHalf = substr($secret->plainTextToken, strlen($secret->keyPrefix));

    $editedEnvironment = str_replace('sig_test_', 'sig_live_', $secret->keyPrefix).$secretHalf;
    $editedId = 'sig_test_ZZZZZZZZ'.$secretHalf;

    expect($service->matches($editedEnvironment, $secret->keyHash, null, null, CarbonImmutable::now()))->toBeFalse()
        ->and($service->matches($editedId, $secret->keyHash, null, null, CarbonImmutable::now()))->toBeFalse();
});

test('rotation keeps the public prefix and the old token valid until the grace window ends', function (): void {
    $service = app(ApiKeySecretService::class);
    $original = $service->issue();
    $rotatedAt = CarbonImmutable::parse('2026-11-17 12:00:00');

    $rotation = $service->rotate($original->keyPrefix, $original->keyHash, $rotatedAt);

    expect($rotation->secret->keyPrefix)->toBe($original->keyPrefix)
        ->and($rotation->secret->plainTextToken)->not->toBe($original->plainTextToken)
        ->and($rotation->previousKeyHash)->toBe($original->keyHash)
        ->and($rotation->previousKeyValidUntil?->equalTo($rotatedAt->addHours(24)))->toBeTrue();

    $isOldTokenValidAt = fn (CarbonImmutable $moment): bool => $service->matches(
        $original->plainTextToken,
        $rotation->secret->keyHash,
        $rotation->previousKeyHash,
        $rotation->previousKeyValidUntil,
        $moment,
    );

    expect($isOldTokenValidAt($rotatedAt->addHours(23)->addMinutes(59)))->toBeTrue()
        ->and($isOldTokenValidAt($rotatedAt->addHours(24)))->toBeFalse()
        ->and($isOldTokenValidAt($rotatedAt->addDays(2)))->toBeFalse()
        ->and($service->matches($rotation->secret->plainTextToken, $rotation->secret->keyHash, $rotation->previousKeyHash, $rotation->previousKeyValidUntil, $rotatedAt->addDays(2)))->toBeTrue();
});

test('a previous hash without an end of grace is never accepted', function (): void {
    $service = app(ApiKeySecretService::class);
    $original = $service->issue();
    $current = $service->issue();

    expect($service->matches($original->plainTextToken, $current->keyHash, $original->keyHash, null, CarbonImmutable::now()))->toBeFalse();
});

test('the grace window is configurable', function (): void {
    config(['api.key_rotation_grace_hours' => 2]);
    $service = app(ApiKeySecretService::class);
    $original = $service->issue();
    $rotatedAt = CarbonImmutable::now();

    $rotation = $service->rotate($original->keyPrefix, $original->keyHash, $rotatedAt);

    expect($rotation->previousKeyValidUntil?->equalTo($rotatedAt->addHours(2)))->toBeTrue();
});

test('a zero or unreadable grace window ends the old token at once, never later', function (mixed $configuredGraceHours): void {
    config(['api.key_rotation_grace_hours' => $configuredGraceHours]);
    $service = app(ApiKeySecretService::class);
    $original = $service->issue();

    $rotation = $service->rotate($original->keyPrefix, $original->keyHash, CarbonImmutable::now());

    expect($service->rotationGraceHours())->toBe(0)
        ->and($rotation->previousKeyHash)->toBeNull()
        ->and($rotation->previousKeyValidUntil)->toBeNull()
        ->and($service->matches($original->plainTextToken, $rotation->secret->keyHash, $rotation->previousKeyHash, $rotation->previousKeyValidUntil, CarbonImmutable::now()))->toBeFalse();
})->with([
    'zero' => 0,
    'negative' => -5,
    'not a number' => 'forever',
    'null' => null,
]);
