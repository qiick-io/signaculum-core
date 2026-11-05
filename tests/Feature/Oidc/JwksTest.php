<?php

declare(strict_types=1);

use App\Actions\RotateSigningKeyAction;
use App\Models\SigningKey;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

test('the jwks publishes the active RS256 key without private material', function () {
    $response = $this->get('/.well-known/jwks.json')->assertOk();

    $keys = $response->json('keys');
    $activeKey = SigningKey::query()->active()->sole();

    expect($keys)->toHaveCount(1)
        ->and($keys[0])->toMatchArray(['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $activeKey->kid])
        ->and($keys[0])->toHaveKeys(['n', 'e'])
        ->and($keys[0])->not->toHaveKeys(['d', 'p', 'q', 'dp', 'dq', 'qi']);

    expect($response->headers->get('Content-Type'))->toBe('application/jwk-set+json');
});

test('the jwk modulus and exponent match the stored public key', function () {
    $jwk = $this->get('/.well-known/jwks.json')->json('keys.0');
    $keyDetails = openssl_pkey_get_details(openssl_pkey_get_public(SigningKey::query()->sole()->public_key));

    $base64Url = fn (string $binary): string => rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');

    expect($jwk['n'])->toBe($base64Url($keyDetails['rsa']['n']))
        ->and($jwk['e'])->toBe($base64Url($keyDetails['rsa']['e']));
});

test('the kid is the RFC 7638 thumbprint of the key', function () {
    $jwk = $this->get('/.well-known/jwks.json')->json('keys.0');

    $canonicalJwk = sprintf('{"e":"%s","kty":"RSA","n":"%s"}', $jwk['e'], $jwk['n']);
    $thumbprint = rtrim(strtr(base64_encode(hash('sha256', $canonicalJwk, true)), '+/', '-_'), '=');

    expect($jwk['kid'])->toBe($thumbprint);
});

test('the private key is encrypted at rest', function () {
    $this->get('/.well-known/jwks.json');

    $storedPrivateKey = DB::table('signing_keys')->value('private_key');

    expect($storedPrivateKey)->not->toContain('PRIVATE KEY');
});

test('a rotated key stays published during the overlap and then disappears', function () {
    config(['oidc.signing_key_overlap_hours' => 24]);

    $oldKey = app(RotateSigningKeyAction::class)->execute();
    $newKey = app(RotateSigningKeyAction::class)->execute();

    expect($oldKey->refresh()->retired_at)->not->toBeNull()
        ->and(SigningKey::query()->active()->sole()->is($newKey))->toBeTrue();

    $publishedKids = fn (): array => collect($this->get('/.well-known/jwks.json')->json('keys'))->pluck('kid')->all();

    expect($publishedKids())->toBe([$newKey->kid, $oldKey->kid]);

    Date::setTestNow(Date::now()->addHours(25));

    expect($publishedKids())->toBe([$newKey->kid]);
});

test('the rotate command creates a new active key', function () {
    $this->artisan('signaculum:rotate-signing-key')->assertSuccessful();
    $this->artisan('signaculum:rotate-signing-key')->assertSuccessful();

    expect(SigningKey::query()->count())->toBe(2)
        ->and(SigningKey::query()->active()->count())->toBe(1);
});
