<?php

declare(strict_types=1);

use App\Actions\DispatchBackChannelLogoutAction;
use App\Jobs\SendBackChannelLogoutJob;
use App\Models\User;
use App\Services\LogoutTokenService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->client->forceFill([
        'post_logout_redirect_uris' => ['https://client.example.test/logged-out'],
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();
    $this->pkce = pkcePair();
});

test('logout fans out a back-channel job per client session', function () {
    Queue::fake();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    $otherClient = createOidcClient();
    $otherClient->forceFill([
        'backchannel_logout_uri' => 'https://other.example.test/backchannel-logout',
    ])->save();
    $otherPkce = pkcePair();
    $code = approveAuthorization($otherClient, $otherPkce['challenge'])['code'];
    redeemCode($otherClient, $code, $otherPkce['verifier'])->assertOk();

    $this->post('/logout')->assertRedirect();

    Queue::assertPushed(SendBackChannelLogoutJob::class, 2);
});

test('the logout_token carries the required claims and typ', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $idToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token');
    $sid = verifiedIdToken($idToken)->claims()->get('sid');

    Http::fake([
        'client.example.test/*' => Http::response('ok', 200),
    ]);

    $this->post('/logout')->assertRedirect();

    Http::assertSent(function ($request) use ($sid): bool {
        if ($request->url() !== 'https://client.example.test/auth/oidc/backchannel-logout') {
            return false;
        }

        $logoutToken = $request['logout_token'] ?? null;
        expect($logoutToken)->toBeString();

        $token = (new Parser(new JoseEncoder))->parse($logoutToken);
        $claims = $token->claims();
        $rawPayload = json_decode(base64_decode(strtr(explode('.', $logoutToken)[1], '-_', '+/')), true);

        expect($token->headers()->get('typ'))->toBe(LogoutTokenService::TOKEN_TYPE)
            ->and($token->headers()->get('alg'))->toBe('RS256')
            ->and($claims->get('iss'))->toBe('https://id.example.test')
            ->and($claims->get('aud'))->toBe([$this->client->getKey()])
            ->and($claims->get('sub'))->toBe($this->user->subject)
            ->and($claims->get('sid'))->toBe($sid)
            ->and($claims->get('jti'))->toBeString()->not->toBeEmpty()
            ->and($rawPayload)->not->toHaveKey('nonce')
            ->and($rawPayload['events'])->toHaveKey(LogoutTokenService::LOGOUT_EVENT);

        return true;
    });
});

test('a failing back-channel client does not block the other jobs', function () {
    Queue::fake();

    $failing = createOidcClient();
    $failing->forceFill([
        'backchannel_logout_uri' => 'https://fail.example.test/backchannel-logout',
    ])->save();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    foreach ([$this->client, $failing] as $client) {
        $pkce = pkcePair();
        $code = approveAuthorization($client, $pkce['challenge'])['code'];
        redeemCode($client, $code, $pkce['verifier'])->assertOk();
    }

    $this->post('/logout')->assertRedirect();

    Queue::assertPushed(SendBackChannelLogoutJob::class, 2);

    $jobs = Queue::pushed(SendBackChannelLogoutJob::class);
    expect($jobs)->toHaveCount(2)
        ->and(collect($jobs)->pluck('clientId')->all())->toContain($this->client->getKey(), $failing->getKey());
});

test('remove access fans out only for that client', function () {
    Queue::fake();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    $this->delete('/settings/connected-applications/'.$this->client->getKey())->assertRedirect();

    Queue::assertPushed(SendBackChannelLogoutJob::class, fn (SendBackChannelLogoutJob $job): bool => $job->clientId === $this->client->getKey());
    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);
});

test('a session-not-required client still receives sid in the logout token job', function () {
    Queue::fake();

    $this->client->forceFill([
        'is_backchannel_logout_session_required' => false,
    ])->save();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $idToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token');
    $sid = verifiedIdToken($idToken)->claims()->get('sid');
    // Fan-out with a null filter (e.g. user-wide logout) must still send each row's sid.
    $this->flushSession();

    app(DispatchBackChannelLogoutAction::class)->execute($this->user);

    Queue::assertPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->clientId === $this->client->getKey()
            && $job->oidcSessionId === $sid
            && $job->subject === $this->user->subject,
    );
});

test('the same logout for one client and sid is queued once', function () {
    Queue::fake();
    $uri = 'https://client.example.test/auth/oidc/backchannel-logout';

    foreach (['sid-1', 'sid-1', 'sid-2', null, null] as $oidcSessionId) {
        SendBackChannelLogoutJob::dispatch($this->client->getKey(), $uri, $this->user->subject, $oidcSessionId);
    }

    SendBackChannelLogoutJob::dispatch($this->client->getKey(), $uri, 'another-subject', null);
    SendBackChannelLogoutJob::dispatch(createOidcClient()->getKey(), $uri, $this->user->subject, 'sid-1');

    Queue::assertPushed(SendBackChannelLogoutJob::class, 5);
});
