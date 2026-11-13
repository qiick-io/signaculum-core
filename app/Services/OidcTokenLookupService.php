<?php

declare(strict_types=1);

namespace App\Services;

use Defuse\Crypto\Crypto;
use Illuminate\Contracts\Encryption\Encrypter;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Throwable;

/**
 * Resolve an opaque OAuth token string to its Passport access or refresh token id.
 *
 * Access tokens are league JWTs (`jti`); refresh tokens are Defuse-encrypted JSON
 * (league `BearerTokenResponse`). Used by RFC 7009 revocation (and later RFC 7662).
 */
final class OidcTokenLookupService
{
    public function __construct(
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * @return array{type: 'access_token'|'refresh_token', id: string, access_token_id: string, client_id: string}|null
     */
    public function resolve(string $token, ?string $tokenTypeHint = null): ?array
    {
        $hints = match ($tokenTypeHint) {
            'refresh_token' => ['refresh_token', 'access_token'],
            'access_token' => ['access_token', 'refresh_token'],
            default => ['access_token', 'refresh_token'],
        };

        foreach ($hints as $hint) {
            $resolved = $hint === 'refresh_token'
                ? $this->resolveRefreshToken($token)
                : $this->resolveAccessToken($token);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array{type: 'access_token', id: string, access_token_id: string, client_id: string}|null
     */
    private function resolveAccessToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $parsed = (new Parser(new JoseEncoder))->parse($token);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed instanceof UnencryptedToken) {
            return null;
        }

        $tokenId = $parsed->claims()->get('jti');
        $audience = $parsed->claims()->get('aud');

        if (! is_string($tokenId) || $tokenId === '') {
            return null;
        }

        $clientId = is_array($audience) ? (string) ($audience[0] ?? '') : (string) $audience;

        if ($clientId === '') {
            return null;
        }

        $record = Passport::token()->newQuery()->whereKey($tokenId)->first(['id', 'client_id']);

        if ($record === null) {
            return null;
        }

        return [
            'type' => 'access_token',
            'id' => (string) $record->getKey(),
            'access_token_id' => (string) $record->getKey(),
            'client_id' => (string) $record->client_id,
        ];
    }

    /**
     * @return array{type: 'refresh_token', id: string, access_token_id: string, client_id: string}|null
     */
    private function resolveRefreshToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = Crypto::decryptWithPassword($token, Passport::tokenEncryptionKey($this->encrypter));
        } catch (Throwable) {
            return null;
        }

        /** @var array{refresh_token_id?: mixed, access_token_id?: mixed, client_id?: mixed}|null $decoded */
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $refreshTokenId = $decoded['refresh_token_id'] ?? null;
        $accessTokenId = $decoded['access_token_id'] ?? null;
        $clientId = $decoded['client_id'] ?? null;

        if (! is_string($refreshTokenId) || $refreshTokenId === '' || ! is_string($accessTokenId) || $accessTokenId === '' || ! is_string($clientId) || $clientId === '') {
            return null;
        }

        $record = Passport::refreshToken()->newQuery()->whereKey($refreshTokenId)->first(['id', 'access_token_id']);

        if ($record === null) {
            return null;
        }

        return [
            'type' => 'refresh_token',
            'id' => (string) $record->getKey(),
            'access_token_id' => (string) $record->access_token_id,
            'client_id' => $clientId,
        ];
    }
}
