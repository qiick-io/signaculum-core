<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Models\User;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\EnvironmentIsBrokenException;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * RFC 7662 token introspection for Passport access and refresh tokens.
 *
 * Only confidential clients may call the endpoint (RFC 7662 §4). A token is
 * reported active only when it was issued to the calling client (`client_id`
 * match); another client's token is treated as inactive so introspection never
 * leaks `sub`, scopes or peer client ids (RFC 7662 §2.2 / §4). Inactive,
 * unknown, expired or revoked tokens answer with `{ "active": false }` alone.
 */
final class TokenIntrospectionService
{
    public function __construct(
        private readonly Encrypter $encrypter,
    ) {}

    /**
     * Authenticate the confidential caller from Basic or form credentials.
     *
     * @return Client|null null when the caller is missing, public, or wrong secret
     */
    public function authenticateClient(Request $request): ?Client
    {
        [$clientId, $clientSecret] = $this->clientCredentialsFrom($request);

        if ($clientId === '') {
            return null;
        }

        $client = Client::query()->whereKey($clientId)->where('revoked', false)->first();

        if ($client === null || ! $client->confidential()) {
            return null;
        }

        $storedSecret = $client->secret;

        if (! is_string($storedSecret) || $storedSecret === '' || $clientSecret === '') {
            return null;
        }

        if (! Hash::check($clientSecret, $storedSecret)) {
            return null;
        }

        return $client;
    }

    /**
     * Build the introspection JSON for one token string (RFC 7662 §2.2).
     *
     * @return array{active: false}|array{
     *     active: true,
     *     scope: string,
     *     client_id: string,
     *     username?: string,
     *     token_type: string,
     *     exp: int,
     *     iat?: int,
     *     sub: string,
     *     aud?: string,
     *     iss: string
     * }
     */
    public function introspect(string $token, Client $callingClient, ?string $tokenTypeHint = null): array
    {
        $hint = $tokenTypeHint === 'refresh_token' ? 'refresh_token' : 'access_token';

        if ($hint === 'refresh_token') {
            $refreshPayload = $this->claimsForRefreshToken($token);

            if ($refreshPayload !== null) {
                return $this->activeOnlyForCallingClient($refreshPayload, $callingClient);
            }

            $accessPayload = $this->claimsForAccessToken($token);

            return $this->activeOnlyForCallingClient($accessPayload ?? ['active' => false], $callingClient);
        }

        $accessPayload = $this->claimsForAccessToken($token);

        if ($accessPayload !== null) {
            return $this->activeOnlyForCallingClient($accessPayload, $callingClient);
        }

        return $this->activeOnlyForCallingClient(
            $this->claimsForRefreshToken($token) ?? ['active' => false],
            $callingClient,
        );
    }

    /**
     * Collapse a foreign-client active payload to inactive (RFC 7662 §2.2 / §4).
     *
     * @param  array{active: false}|array{
     *     active: true,
     *     scope: string,
     *     client_id: string,
     *     username?: string,
     *     token_type: string,
     *     exp: int,
     *     iat?: int,
     *     sub: string,
     *     aud?: string,
     *     iss: string
     * }  $payload
     * @return array{active: false}|array{
     *     active: true,
     *     scope: string,
     *     client_id: string,
     *     username?: string,
     *     token_type: string,
     *     exp: int,
     *     iat?: int,
     *     sub: string,
     *     aud?: string,
     *     iss: string
     * }
     */
    private function activeOnlyForCallingClient(array $payload, Client $callingClient): array
    {
        if ($payload['active'] !== true) {
            return ['active' => false];
        }

        if ((string) $payload['client_id'] !== (string) $callingClient->getKey()) {
            return ['active' => false];
        }

        return $payload;
    }

    /**
     * @return array{0: string, 1: string} client_id, client_secret
     */
    private function clientCredentialsFrom(Request $request): array
    {
        $authorizationHeader = $request->header('Authorization');

        if (is_string($authorizationHeader) && str_starts_with($authorizationHeader, 'Basic ')) {
            $decoded = base64_decode(substr($authorizationHeader, 6), true);

            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);

                return [$clientId, $clientSecret];
            }
        }

        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        return [
            is_string($clientId) ? $clientId : '',
            is_string($clientSecret) ? $clientSecret : '',
        ];
    }

    /**
     * @return array{active: false}|array{active: true, scope: string, client_id: string, username?: string, token_type: string, exp: int, iat: int, sub: string, aud: string, iss: string}|null
     */
    private function claimsForAccessToken(string $tokenString): ?array
    {
        $accessToken = $this->findAccessToken($tokenString);

        if ($accessToken === null) {
            return null;
        }

        if ($accessToken->revoked || $accessToken->expires_at === null || $accessToken->expires_at->isPast()) {
            return ['active' => false];
        }

        $subject = $this->subjectForUserId($accessToken->user_id);
        $username = $this->usernameForUserId($accessToken->user_id);

        $claims = [
            'active' => true,
            'scope' => $this->scopeString($accessToken->scopes ?? []),
            'client_id' => (string) $accessToken->client_id,
            'token_type' => 'Bearer',
            'exp' => $accessToken->expires_at->getTimestamp(),
            'iat' => ($accessToken->created_at ?? Date::now())->getTimestamp(),
            'sub' => $subject ?? (string) $accessToken->user_id,
            'aud' => (string) $accessToken->client_id,
            'iss' => (string) config('oidc.issuer'),
        ];

        if ($username !== null) {
            $claims['username'] = $username;
        }

        return $claims;
    }

    /**
     * @return array{active: false}|array{active: true, scope: string, client_id: string, username?: string, token_type: string, exp: int, sub: string, iss: string}|null
     */
    private function claimsForRefreshToken(string $tokenString): ?array
    {
        $refreshPayload = $this->decryptRefreshToken($tokenString);

        if ($refreshPayload === null) {
            return null;
        }

        $refreshTokenId = $refreshPayload['refresh_token_id'] ?? null;

        if (! is_string($refreshTokenId) || $refreshTokenId === '') {
            return ['active' => false];
        }

        /** @var RefreshToken|null $refreshToken */
        $refreshToken = Passport::refreshToken()->newQuery()->find($refreshTokenId);

        if ($refreshToken === null || $refreshToken->revoked || $refreshToken->expires_at === null || $refreshToken->expires_at->isPast()) {
            return ['active' => false];
        }

        $clientId = is_string($refreshPayload['client_id'] ?? null) ? $refreshPayload['client_id'] : '';
        $userId = $refreshPayload['user_id'] ?? null;
        $scopes = $refreshPayload['scopes'] ?? [];
        $scopeList = is_array($scopes) ? array_values($scopes) : [];

        $subject = $this->subjectForUserId(is_scalar($userId) ? (string) $userId : null);
        $username = $this->usernameForUserId(is_scalar($userId) ? (string) $userId : null);

        $claims = [
            'active' => true,
            'scope' => $this->scopeString($scopeList),
            'client_id' => $clientId,
            'token_type' => 'refresh_token',
            'exp' => $refreshToken->expires_at->getTimestamp(),
            // ponytail: oauth_refresh_tokens has no created_at; upgrade: persist issued_at and emit iat
            'sub' => $subject ?? (is_scalar($userId) ? (string) $userId : ''),
            'iss' => (string) config('oidc.issuer'),
        ];

        if ($username !== null) {
            $claims['username'] = $username;
        }

        return $claims;
    }

    private function findAccessToken(string $tokenString): ?Token
    {
        $segments = explode('.', $tokenString);

        if (count($segments) !== 3) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($segments[1]);

        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            return null;
        }

        $tokenId = $payload['jti'] ?? null;

        if (! is_string($tokenId) || $tokenId === '') {
            return null;
        }

        /** @var Token|null $accessToken */
        $accessToken = Passport::token()->newQuery()->find($tokenId);

        return $accessToken;
    }

    /**
     * @return array{client_id?: string, refresh_token_id?: string, access_token_id?: string, scopes?: mixed, user_id?: mixed, expire_time?: int}|null
     */
    private function decryptRefreshToken(string $tokenString): ?array
    {
        try {
            $plaintext = Crypto::decryptWithPassword($tokenString, Passport::tokenEncryptionKey($this->encrypter));
        } catch (WrongKeyOrModifiedCiphertextException|EnvironmentIsBrokenException) {
            return null;
        }

        $payload = json_decode($plaintext, true);

        return is_array($payload) ? $payload : null;
    }

    private function subjectForUserId(int|string|null $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $subject = User::query()->whereKey($userId)->value('subject');

        return is_string($subject) ? $subject : null;
    }

    private function usernameForUserId(int|string|null $userId): ?string
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $email = User::query()->whereKey($userId)->value('email');

        return is_string($email) ? $email : null;
    }

    /**
     * @param  array<int, mixed>  $scopes
     */
    private function scopeString(array $scopes): string
    {
        $scopeValues = [];

        foreach ($scopes as $scope) {
            if ($scope instanceof ScopeEntityInterface) {
                $scopeValues[] = $scope->getIdentifier();
            } elseif (is_string($scope) && $scope !== '') {
                $scopeValues[] = $scope;
            }
        }

        return implode(' ', $scopeValues);
    }

    private function base64UrlDecode(string $value): ?string
    {
        $remainder = strlen($value) % 4;

        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
