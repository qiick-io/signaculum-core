<?php

declare(strict_types=1);

use App\Models\ClientSession;
use App\Models\OidcSession;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);
    Queue::fake();

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('the id_token carries a sid stable for the browser session', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $firstSid = session(OidcSessionService::SESSION_KEY);
    expect($firstSid)->toBeString()->not->toBeEmpty();

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('sid'))->toBe($firstSid)
        ->and(OidcSession::query()->where('oidc_session_id', $firstSid)->whereNull('ended_at')->exists())->toBeTrue()
        ->and(ClientSession::query()->where([
            'user_id' => $this->user->id,
            'client_id' => $this->client->getKey(),
            'oidc_session_id' => $firstSid,
        ])->exists())->toBeTrue();

    $secondPkce = pkcePair();
    $code = approveAuthorization($this->client, $secondPkce['challenge'])['code'];
    $secondClaims = verifiedIdToken(redeemCode($this->client, $code, $secondPkce['verifier'])->json('id_token'))->claims();

    expect($secondClaims->get('sid'))->toBe($firstSid);
});

test('login regenerates the sid', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $firstSid = session(OidcSessionService::SESSION_KEY);

    $this->post('/logout')->assertRedirect();
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    expect(session(OidcSessionService::SESSION_KEY))->not->toBe($firstSid);
});

test('client logout URI fields persist on the client model', function () {
    $this->client->forceFill([
        'post_logout_redirect_uris' => ['https://client.example.test/logged-out'],
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();

    $this->client->refresh();

    expect($this->client->post_logout_redirect_uris)->toBe(['https://client.example.test/logged-out'])
        ->and($this->client->backchannel_logout_uri)->toBe('https://client.example.test/auth/oidc/backchannel-logout')
        ->and($this->client->is_backchannel_logout_session_required)->toBeTrue();
});
