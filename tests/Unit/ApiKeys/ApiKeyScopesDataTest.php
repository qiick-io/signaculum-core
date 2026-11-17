<?php

declare(strict_types=1);

use App\Data\ApiKeyScopesData;
use App\Enums\ApiKeyScopeEnum;

/**
 * The shared permission vocabulary on plain values: `<resource>:(read|write)`, `write` implies
 * `read`, and anything that does not parse grants nothing.
 */
const KNOWN_SCOPES = ['posts:read', 'posts:write', 'audit-logs:read', 'audit-logs:write'];

test('parses a list of known scopes', function (): void {
    expect(ApiKeyScopesData::fromStored(['posts:read'], KNOWN_SCOPES)?->scopes)->toBe(['posts:read']);
});

test('refuses stored scopes that do not parse', function (mixed $storedScopes): void {
    expect(ApiKeyScopesData::fromStored($storedScopes, KNOWN_SCOPES))->toBeNull();
})->with([
    'null' => null,
    'a string' => 'posts:read',
    'an empty list' => [[]],
    'a map' => [['first' => 'posts:read']],
    'a non-string entry' => [['posts:read', 1]],
    'a boolean entry' => [[true]],
    'an action outside read and write' => [['posts:admin']],
    'a wildcard' => [['*']],
    'upper case' => [['Posts:read']],
    'a trailing newline' => [["posts:read\n"]],
    'well formed but unknown to the product' => [['users:read']],
    'one unknown among known ones' => [['posts:read', 'users:write']],
]);

test('write implies read on the same resource', function (): void {
    $scopes = ApiKeyScopesData::fromStored(['posts:write'], KNOWN_SCOPES);

    expect($scopes?->allows('posts:read'))->toBeTrue()
        ->and($scopes?->allows('posts:write'))->toBeTrue();
});

test('read never implies write, and no scope reaches another resource', function (): void {
    $scopes = ApiKeyScopesData::fromStored(['posts:read'], KNOWN_SCOPES);

    expect($scopes?->allows('posts:write'))->toBeFalse()
        ->and($scopes?->allows('audit-logs:read'))->toBeFalse();
});

test('a malformed requirement matches nothing', function (string $requiredScope): void {
    $scopes = ApiKeyScopesData::fromStored(['posts:write'], KNOWN_SCOPES);

    expect($scopes?->allows($requiredScope))->toBeFalse();
})->with(['', 'posts', 'posts:', 'posts:*', '*', 'posts:write ', "posts:read\n", 'posts:delete']);

test('keeps one scope per resource, the highest', function (): void {
    expect(ApiKeyScopesData::highestPerResource(['posts:read', 'posts:write', 'audit-logs:read', 'posts:read']))
        ->toBe(['posts:write', 'audit-logs:read']);
});

test('every scope this product defines is in the shared vocabulary', function (): void {
    foreach (ApiKeyScopeEnum::values() as $scope) {
        expect(ApiKeyScopesData::isWellFormed($scope))->toBeTrue();
    }
});

test('no scope lets a key manage api keys', function (): void {
    foreach (ApiKeyScopeEnum::values() as $scope) {
        expect($scope)->not->toMatch('/^(api-)?keys?:/');
    }
});

test('the vocabulary pattern rejects what a loose regex would let through', function (string $scope): void {
    expect(ApiKeyScopesData::isWellFormed($scope))->toBeFalse();
})->with(["posts:read\n", '1posts:read', '-posts:read', 'posts_x:read', 'posts:read:write', ' posts:read']);
