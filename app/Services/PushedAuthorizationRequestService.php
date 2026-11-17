<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\PushedAuthorizationRequestData;
use App\Enums\OidcScopeEnum;
use App\Models\Client;
use App\Models\PushedAuthorizationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * RFC 9126 Pushed Authorization Requests: validate, store, and consume.
 *
 * Client authentication mirrors the token endpoint (`client_secret_basic`,
 * `client_secret_post`, `none`). Authorization parameters are checked against
 * the qiick profile (PKCE S256, nonce for `openid`, exact redirect URI, scopes)
 * before a single-use `request_uri` is issued.
 */
final class PushedAuthorizationRequestService
{
    public const REQUEST_URI_PREFIX = 'urn:ietf:params:oauth:request_uri:';

    /**
     * Parameters that authenticate the client and must not be replayed at authorize.
     *
     * @var list<string>
     */
    private const CLIENT_CREDENTIAL_PARAMETERS = [
        'client_secret',
        'client_assertion',
        'client_assertion_type',
    ];

    public function __construct(
        private readonly ClientRepository $clientRepository,
    ) {}

    /**
     * Authenticate the caller exactly as the token / revoke endpoints do.
     *
     * @throws OAuthServerException when credentials are missing or wrong (RFC 9126 §2)
     */
    public function authenticateClient(Request $request): Client
    {
        $clientCredentials = $this->clientCredentialsFrom($request);
        $clientId = $clientCredentials['client_id'];

        if ($clientId === null) {
            throw OAuthServerException::invalidClient($this->emptyServerRequest());
        }

        $client = $this->clientRepository->findActive($clientId);

        if (! $client instanceof Client || ! $this->clientAuthenticates($client, $clientCredentials['client_secret'])) {
            throw OAuthServerException::invalidClient($this->emptyServerRequest());
        }

        return $client;
    }

    /**
     * Validate the pushed authorization request and store a single-use URI.
     *
     * @throws OAuthServerException JSON errors per RFC 9126 §2.3 / RFC 6749 §5.2
     */
    public function store(Client $authenticatedClient, Request $request): PushedAuthorizationRequestData
    {
        $parameters = $this->authorizationParametersFrom($request);

        $this->assertClientIdMatches($authenticatedClient, $parameters);
        $this->assertNoForbiddenParameters($parameters);
        $this->assertValidAuthorizationRequest($authenticatedClient, $parameters);

        $expiresIn = (int) config('oidc.par_expires_in', 60);
        $requestUri = self::REQUEST_URI_PREFIX.Str::random(48);

        PushedAuthorizationRequest::query()->create([
            'request_uri' => $requestUri,
            'client_id' => (string) $authenticatedClient->getKey(),
            'parameters' => $parameters,
            'expires_at' => Date::now()->addSeconds($expiresIn),
        ]);

        return new PushedAuthorizationRequestData(
            requestUri: $requestUri,
            expiresIn: $expiresIn,
        );
    }

    /**
     * Atomically consume a PAR URI for the given client (RFC 9126 §4).
     *
     * @return array<string, string>|null stored authorization parameters, or null when
     *                                    the URI is unknown, foreign, expired or spent
     */
    public function consume(string $requestUri, string $clientId): ?array
    {
        if (! self::isPushedAuthorizationRequestUri($requestUri)) {
            return null;
        }

        return DB::transaction(function () use ($requestUri, $clientId): ?array {
            $pushedAuthorizationRequest = PushedAuthorizationRequest::query()
                ->where('request_uri', $requestUri)
                ->lockForUpdate()
                ->first();

            if ($pushedAuthorizationRequest === null) {
                return null;
            }

            if ((string) $pushedAuthorizationRequest->client_id !== $clientId) {
                return null;
            }

            if ($pushedAuthorizationRequest->used_at !== null) {
                return null;
            }

            if ($pushedAuthorizationRequest->expires_at->isPast()) {
                return null;
            }

            $pushedAuthorizationRequest->forceFill([
                'used_at' => Date::now(),
            ])->save();

            /** @var array<string, string> $parameters */
            $parameters = $pushedAuthorizationRequest->parameters;

            return $parameters;
        });
    }

    public static function isPushedAuthorizationRequestUri(string $requestUri): bool
    {
        return str_starts_with($requestUri, self::REQUEST_URI_PREFIX);
    }

    /**
     * @return array{client_id: string|null, client_secret: string|null}
     */
    private function clientCredentialsFrom(Request $request): array
    {
        $authorization = $request->header('Authorization');

        if (is_string($authorization) && str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);

            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);

                return [
                    'client_id' => $clientId !== '' ? $clientId : null,
                    'client_secret' => $clientSecret !== '' ? $clientSecret : null,
                ];
            }
        }

        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        return [
            'client_id' => is_string($clientId) && $clientId !== '' ? $clientId : null,
            'client_secret' => is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null,
        ];
    }

    /**
     * Confidential clients must present a matching secret; public clients must not
     * send a secret (RFC 6749 §2.3; RFC 9126 §2 applies the same rules as the token endpoint).
     */
    private function clientAuthenticates(Client $client, ?string $clientSecret): bool
    {
        if ($client->confidential()) {
            return is_string($clientSecret)
                && $clientSecret !== ''
                && is_string($client->secret)
                && Hash::check($clientSecret, $client->secret);
        }

        return $clientSecret === null;
    }

    /**
     * @return array<string, string>
     */
    private function authorizationParametersFrom(Request $request): array
    {
        $parameters = [];

        foreach ($request->request->all() as $name => $value) {
            if (in_array($name, self::CLIENT_CREDENTIAL_PARAMETERS, true)) {
                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $parameters[(string) $name] = $value;
        }

        return $parameters;
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthServerException
     */
    private function assertClientIdMatches(Client $authenticatedClient, array $parameters): void
    {
        $bodyClientId = $parameters['client_id'] ?? null;

        if ($bodyClientId === null || $bodyClientId !== (string) $authenticatedClient->getKey()) {
            // RFC 9126 §2: the pushed client_id must identify the authenticated client.
            throw OAuthServerException::invalidClient($this->emptyServerRequest());
        }
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthServerException
     */
    private function assertNoForbiddenParameters(array $parameters): void
    {
        // RFC 9126 §2.1: `request_uri` MUST NOT be provided at the PAR endpoint.
        if (isset($parameters['request_uri'])) {
            throw OAuthServerException::invalidRequest('request_uri', 'The request_uri parameter must not be included in a pushed authorization request.');
        }

        // OIDC Request Objects remain unsupported (#113); refuse at PAR too.
        if (isset($parameters['request'])) {
            throw OAuthServerException::invalidRequest('request', 'Request Objects are not supported; omit the request parameter.');
        }
    }

    /**
     * Validate the full authorization request before storage (RFC 9126 §2).
     *
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthServerException
     */
    private function assertValidAuthorizationRequest(Client $client, array $parameters): void
    {
        if (($parameters['response_type'] ?? null) !== 'code') {
            throw OAuthServerException::invalidRequest('response_type', 'Only response_type=code is supported.');
        }

        $redirectUri = $parameters['redirect_uri'] ?? null;

        if (! is_string($redirectUri) || $redirectUri === '' || ! in_array($redirectUri, $client->redirect_uris, true)) {
            throw OAuthServerException::invalidRequest('redirect_uri', 'The redirect_uri must match a registered URI exactly.');
        }

        $this->assertValidScopes($parameters['scope'] ?? null);
        $this->assertValidPkce($parameters);
        $this->assertValidNonce($parameters);
    }

    /**
     * @throws OAuthServerException
     */
    private function assertValidScopes(?string $scopeParameter): void
    {
        if ($scopeParameter === null || trim($scopeParameter) === '') {
            throw OAuthServerException::invalidRequest('scope', 'At least one scope is required.');
        }

        $requestedScopes = preg_split('/\s+/', trim($scopeParameter)) ?: [];
        $supportedScopes = array_map(
            fn (OidcScopeEnum $scope): string => $scope->value,
            OidcScopeEnum::cases(),
        );

        foreach ($requestedScopes as $requestedScope) {
            if (! in_array($requestedScope, $supportedScopes, true)) {
                throw OAuthServerException::invalidScope($requestedScope);
            }
        }
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthServerException
     */
    private function assertValidPkce(array $parameters): void
    {
        $codeChallenge = $parameters['code_challenge'] ?? null;
        $codeChallengeMethod = $parameters['code_challenge_method'] ?? null;
        $hasCodeChallenge = is_string($codeChallenge) && $codeChallenge !== '';

        if ($hasCodeChallenge && $codeChallengeMethod !== 'S256') {
            throw OAuthServerException::invalidRequest('code_challenge_method', 'Code challenge method must be `S256`.');
        }

        if (! $hasCodeChallenge && (bool) config('oidc.require_pkce')) {
            throw OAuthServerException::invalidRequest('code_challenge', 'PKCE is required: send `code_challenge` with `code_challenge_method=S256`.');
        }
    }

    /**
     * @param  array<string, string>  $parameters
     *
     * @throws OAuthServerException
     */
    private function assertValidNonce(array $parameters): void
    {
        $scopeParameter = $parameters['scope'] ?? '';
        $requestedScopes = preg_split('/\s+/', trim($scopeParameter)) ?: [];
        $isOpenIdRequest = in_array(OidcScopeEnum::OpenId->value, $requestedScopes, true);
        $nonce = $parameters['nonce'] ?? null;

        if ($isOpenIdRequest && ($nonce === null || $nonce === '') && (bool) config('oidc.require_nonce')) {
            throw OAuthServerException::invalidRequest('nonce', 'A `nonce` is required for OpenID Connect requests.');
        }
    }

    /**
     * league's invalidClient factory requires a ServerRequestInterface; PAR never reads it.
     */
    private function emptyServerRequest(): ServerRequestInterface
    {
        return (new PsrHttpFactory)->createRequest(Request::create('/oauth/par', 'POST'));
    }
}
