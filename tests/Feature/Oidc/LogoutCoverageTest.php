<?php

declare(strict_types=1);

use App\Actions\DispatchBackChannelLogoutAction;
use App\Actions\EndOidcSessionAction;
use App\Data\LogoutTokenRequestData;
use App\Exceptions\InvalidIdTokenHintException;
use App\Jobs\SendBackChannelLogoutJob;
use App\Models\ClientSession;
use App\Models\OidcSession;
use App\Models\User;
use App\Services\IdTokenHintValidatorService;
use App\Services\LogoutTokenService;
use App\Services\SigningKeyService;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use LogicException;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->client->forceFill([
        'post_logout_redirect_uris' => ['https://client.example.test/logged-out'],
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();
});

/**
 * Mint an RS256 JWT with the active signing key and optional claim overrides.
 *
 * @param  array<string, mixed>  $claimOverrides
 */
function mintSignedHint(User $user, string $audience, array $claimOverrides = []): string
{
    $signingKey = app(SigningKeyService::class)->activeKey();
    $jwtConfiguration = Configuration::forAsymmetricSigner(
        new Sha256,
        InMemory::plainText($signingKey->private_key),
        InMemory::plainText($signingKey->public_key),
    );

    $issuedAt = new DateTimeImmutable('@'.time());
    $claims = array_merge([
        'iss' => 'https://id.example.test',
        'sub' => $user->subject,
        'aud' => $audience,
        'exp' => $issuedAt->modify('+120 seconds'),
        'iat' => $issuedAt,
    ], $claimOverrides);

    $tokenBuilder = $jwtConfiguration->builder(ChainedFormatter::withUnixTimestampDates())
        ->withHeader('kid', $signingKey->kid)
        ->issuedBy((string) $claims['iss'])
        ->issuedAt($claims['iat'] instanceof DateTimeImmutable ? $claims['iat'] : $issuedAt)
        ->expiresAt($claims['exp'] instanceof DateTimeImmutable ? $claims['exp'] : $issuedAt->modify('+120 seconds'));

    if (is_string($claims['sub']) && $claims['sub'] !== '') {
        $tokenBuilder = $tokenBuilder->relatedTo($claims['sub']);
    }

    $audienceClaim = $claims['aud'] ?? null;

    if (is_string($audienceClaim) && $audienceClaim !== '') {
        $tokenBuilder = $tokenBuilder->permittedFor($audienceClaim);
    } elseif (is_array($audienceClaim)) {
        foreach ($audienceClaim as $audienceValue) {
            if (is_string($audienceValue) && $audienceValue !== '') {
                $tokenBuilder = $tokenBuilder->permittedFor($audienceValue);
            }
        }
    } elseif ($audienceClaim !== null) {
        $tokenBuilder = $tokenBuilder->withClaim('aud', $audienceClaim);
    }

    if (array_key_exists('sid', $claims) && is_string($claims['sid'])) {
        $tokenBuilder = $tokenBuilder->withClaim('sid', $claims['sid']);
    }

    return $tokenBuilder
        ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey())
        ->toString();
}
test('send back-channel logout job retries on http failure and logs when it gives up', function () {
    Http::fake([
        'client.example.test/*' => Http::response('no', 500),
    ]);
    Log::spy();

    $job = new SendBackChannelLogoutJob(
        clientId: $this->client->getKey(),
        backchannelLogoutUri: 'https://client.example.test/auth/oidc/backchannel-logout',
        subject: $this->user->subject,
        oidcSessionId: 'sid-http-fail',
    );

    try {
        $job->handle(app(LogoutTokenService::class));
        expect(false)->toBeTrue('expected the back-channel POST to fail');
    } catch (RequestException $exception) {
        $job->failed($exception);
    }

    Log::shouldHaveReceived('warning')->once();
    Log::shouldHaveReceived('error')->once();
});
test('send back-channel logout job rethrows connection failures', function () {
    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    $job = new SendBackChannelLogoutJob(
        clientId: $this->client->getKey(),
        backchannelLogoutUri: 'https://client.example.test/auth/oidc/backchannel-logout',
        subject: $this->user->subject,
        oidcSessionId: 'sid-conn-fail',
    );

    expect(fn () => $job->handle(app(LogoutTokenService::class)))
        ->toThrow(ConnectionException::class);
});

test('id_token_hint validation refuses empty, forged, foreign and incomplete tokens', function () {
    $validator = app(IdTokenHintValidatorService::class);
    $clientId = $this->client->getKey();

    expect(fn () => $validator->validate(''))
        ->toThrow(InvalidIdTokenHintException::class, 'empty');

    expect(fn () => $validator->validate('not.a.jwt'))
        ->toThrow(InvalidIdTokenHintException::class, 'not a JWT');

    expect(fn () => $validator->validate(mintSignedHint($this->user, $clientId, [
        'iss' => 'https://evil.example.test',
    ])))->toThrow(InvalidIdTokenHintException::class, 'issuer');

    expect(fn () => $validator->validate(mintSignedHint($this->user, $clientId, [
        'aud' => null,
    ]), $clientId))->toThrow(InvalidIdTokenHintException::class, 'audience');

    expect(fn () => $validator->validate(mintSignedHint($this->user, 'other-client'), $clientId))
        ->toThrow(InvalidIdTokenHintException::class, 'audience');

    // Unknown kid → signature/key lookup fails closed.
    $parts = explode('.', mintSignedHint($this->user, $clientId));
    $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
    $header['kid'] = 'unknown-kid';
    $parts[0] = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
    expect(fn () => $validator->validate(implode('.', $parts)))
        ->toThrow(InvalidIdTokenHintException::class, 'signature');
    $validHint = mintSignedHint($this->user, $clientId, ['sid' => 'sid-hint']);
    $validated = $validator->validate($validHint, $clientId);

    expect($validated['subject'])->toBe($this->user->subject)
        ->and($validated['oidcSessionId'])->toBe('sid-hint')
        ->and($validator->userForSubject($this->user->subject)?->is($this->user))->toBeTrue()
        ->and($validator->isExpired($validated['raw']))->toBeFalse();

    $expiredHint = mintSignedHint($this->user, $clientId, [
        'iat' => new DateTimeImmutable('@'.(time() - 3600)),
        'exp' => new DateTimeImmutable('@'.(time() - 1800)),
    ]);
    $expiredValidated = $validator->validate($expiredHint, $clientId);

    expect($validator->isExpired($expiredValidated['raw']))->toBeTrue();
});

test('end oidc session without a web login still fans out and marks the sid ended', function () {
    Queue::fake();

    $oidcSessionId = 'sid-offline-'.bin2hex(random_bytes(8));

    OidcSession::query()->create([
        'user_id' => $this->user->getKey(),
        'laravel_session_id' => 'laravel-'.$oidcSessionId,
        'oidc_session_id' => $oidcSessionId,
    ]);

    ClientSession::query()->create([
        'user_id' => $this->user->getKey(),
        'client_id' => $this->client->getKey(),
        'oidc_session_id' => $oidcSessionId,
    ]);

    app(EndOidcSessionAction::class)->execute(
        user: $this->user,
        oidcSessionId: $oidcSessionId,
        shouldLogoutWeb: false,
    );

    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);

    expect(ClientSession::query()->where('oidc_session_id', $oidcSessionId)->value('ended_at'))
        ->not->toBeNull()
        ->and(OidcSession::query()->where('oidc_session_id', $oidcSessionId)->value('ended_at'))
        ->not->toBeNull();
});
test('dispatch skips clients without a back-channel uri and still ends their session row', function () {
    Queue::fake();

    $this->client->forceFill(['backchannel_logout_uri' => null])->save();

    $oidcSessionId = 'sid-no-uri-'.bin2hex(random_bytes(8));

    ClientSession::query()->create([
        'user_id' => $this->user->getKey(),
        'client_id' => $this->client->getKey(),
        'oidc_session_id' => $oidcSessionId,
    ]);

    app(DispatchBackChannelLogoutAction::class)->execute($this->user, $oidcSessionId);

    Queue::assertNothingPushed();
    expect(ClientSession::query()->where('oidc_session_id', $oidcSessionId)->value('ended_at'))
        ->not->toBeNull();
});

test('logout token issuance requires sub or sid and rejects empty strings', function () {
    $logoutTokenService = app(LogoutTokenService::class);

    expect(fn () => $logoutTokenService->issue(new LogoutTokenRequestData(
        clientId: $this->client->getKey(),
        subject: null,
        oidcSessionId: null,
    )))->toThrow(InvalidArgumentException::class);

    expect(fn () => $logoutTokenService->issue(new LogoutTokenRequestData(
        clientId: '',
        subject: $this->user->subject,
        oidcSessionId: null,
    )))->toThrow(LogicException::class);
});

test('guest end-session with a forged hint redirects to login when no post logout uri', function () {
    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => 'not.a.jwt',
    ]))->assertRedirect(route('login'));
});

test('confirming logout with an unregistered post logout uri is refused', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $this->post('/oauth/end-session/confirm', [
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => 'https://evil.example.test/logged-out',
    ])->assertStatus(400);
});
