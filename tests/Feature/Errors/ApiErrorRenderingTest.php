<?php

declare(strict_types=1);

use App\Enums\ApiErrorCodeEnum;
use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Throw the framework exceptions on temporary doors under `api/*` and `/mcp`: the whole
 * HTTP stack then renders them, so the test proves the response, not a mapping helper.
 */
function registerApiErrorDoors(): void
{
    Route::middleware('api')->group(function (): void {
        Route::get('/api/__test/api-error/validation', function (): void {
            request()->validate(['email' => 'required']);
        });
        Route::get('/api/__test/api-error/unauthenticated', fn () => throw new AuthenticationException);
        Route::get('/api/__test/api-error/forbidden', fn () => throw new AuthorizationException);
        Route::get('/api/__test/api-error/access-denied', fn () => throw new AccessDeniedHttpException);
        Route::get('/api/__test/api-error/not-found', fn () => throw new NotFoundHttpException);
        Route::get('/api/__test/api-error/model-not-found', fn () => User::findOrFail(999999));
        Route::get('/api/__test/api-error/method', fn () => 'ok');
        Route::get('/api/__test/api-error/bad-request', fn () => abort(400));
        Route::get('/api/__test/api-error/unavailable', fn () => abort(503));
        Route::get('/api/__test/api-error/throttled', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => '30',
            'X-RateLimit-Limit' => '2',
            'X-RateLimit-Remaining' => '0',
        ]));
        Route::get('/api/__test/api-error/rfc6750', fn () => throw new HttpResponseException(
            response()->json(['message' => 'Bearer token is invalid.', 'error' => 'invalid_token'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"'),
        ));
        Route::get('/api/__test/api-error/server', fn () => throw new RuntimeException('Secret detail'));
        Route::get('/mcp/__test-error', fn () => throw new AuthenticationException);
    });
}

beforeEach(function (): void {
    registerApiErrorDoors();
});

it('adds one stable code to every mapped framework error on the product api', function (string $method, string $uri, int $status, string $code): void {
    $response = $this->call($method, $uri);

    $response->assertStatus($status)
        ->assertJsonPath('error', $code);

    expect($response->headers->get('Content-Type'))->toContain('application/json');
})->with([
    'validation' => ['GET', '/api/__test/api-error/validation', 422, 'validation_failed'],
    'authentication' => ['GET', '/api/__test/api-error/unauthenticated', 401, 'unauthenticated'],
    'authorization' => ['GET', '/api/__test/api-error/forbidden', 403, 'forbidden'],
    'access denied' => ['GET', '/api/__test/api-error/access-denied', 403, 'forbidden'],
    'http not found' => ['GET', '/api/__test/api-error/not-found', 404, 'not_found'],
    'model not found' => ['GET', '/api/__test/api-error/model-not-found', 404, 'not_found'],
    'method not allowed' => ['POST', '/api/__test/api-error/method', 405, 'method_not_allowed'],
    'other 4xx by status' => ['GET', '/api/__test/api-error/bad-request', 400, 'bad_request'],
    'other 5xx by status' => ['GET', '/api/__test/api-error/unavailable', 503, 'server_error'],
]);

it('keeps laravels validation message and field errors beside the code', function (): void {
    $response = $this->getJson('/api/__test/api-error/validation');

    $response->assertUnprocessable()
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['message', 'errors' => ['email']]);

    expect($response->json('errors.email.0'))->toBe('The email field is required.')
        ->and($response->json('message'))->toBe('The email field is required.');
});

it('keeps the rate limit headers on a 429 with a code', function (): void {
    $response = $this->get('/api/__test/api-error/throttled');

    $response->assertStatus(429)
        ->assertJsonPath('error', 'too_many_requests')
        ->assertHeader('Retry-After', '30')
        ->assertHeader('X-RateLimit-Limit', '2')
        ->assertHeader('X-RateLimit-Remaining', '0');
});

it('never leaks a trace, file or exception in a 500 json response with debug off', function (): void {
    config(['app.debug' => false]);

    $response = $this->get('/api/__test/api-error/server');

    $response->assertStatus(500)
        ->assertJsonPath('error', 'server_error')
        ->assertJsonPath('message', 'Server Error');

    expect(array_intersect_key($response->json(), array_flip(['trace', 'file', 'line', 'exception'])))->toBe([])
        ->and($response->getContent())->not->toContain('Secret detail');
});

it('renders json on api and mcp doors without an Accept header', function (string $uri): void {
    $response = $this->get($uri);

    $response->assertUnauthorized()
        ->assertJsonPath('error', 'unauthenticated')
        ->assertHeader('Content-Type', 'application/json');

    expect($response->headers->get('Location'))->toBeNull();
})->with([
    'api' => ['/api/__test/api-error/unauthenticated'],
    'mcp' => ['/mcp/__test-error'],
]);

it('leaves an existing rfc 6750 error key and challenge untouched', function (): void {
    $this->get('/api/__test/api-error/rfc6750')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertExactJson([
            'message' => 'Bearer token is invalid.',
            'error' => 'invalid_token',
        ]);
});

it('keeps the api key rfc 6750 responses untouched', function (): void {
    $this->get('/api/v1/users')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertExactJson(['message' => 'Missing API key.', 'error' => 'invalid_token']);

    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/users', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Password1!Password1!',
        ])
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="users:write"')
        ->assertExactJson([
            'message' => 'API key is missing the required scope.',
            'error' => 'insufficient_scope',
            'scope' => 'users:write',
        ]);

    expect(ApiErrorCodeEnum::tryFrom('invalid_token'))->toBeNull()
        ->and(ApiErrorCodeEnum::tryFrom('insufficient_scope'))->toBeNull()
        ->and(ApiErrorCodeEnum::tryFrom('ip_not_allowed'))->toBeNull();
});

it('does not put a fleet code on oauth or oidc endpoints', function (string $method, string $uri, array $payload = []): void {
    $response = $this->call($method, $uri, $payload);
    $error = $response->json('error');

    expect(in_array($error, array_column(ApiErrorCodeEnum::cases(), 'value'), true))->toBeFalse();
})->with([
    'discovery' => ['GET', '/.well-known/openid-configuration'],
    'jwks' => ['GET', '/.well-known/jwks.json'],
    'userinfo' => ['GET', '/oauth/userinfo'],
    'token' => ['POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => 'bad']],
    'introspect' => ['POST', '/oauth/introspect', ['token' => 'bad']],
    'revoke' => ['POST', '/oauth/revoke', ['token' => 'bad']],
    'par' => ['POST', '/oauth/par', ['client_id' => 'bad']],
]);

it('keeps the userinfo bearer challenge and its rfc 6750 body', function (): void {
    $this->get('/oauth/userinfo')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertExactJson(['error' => 'invalid_token']);
});
