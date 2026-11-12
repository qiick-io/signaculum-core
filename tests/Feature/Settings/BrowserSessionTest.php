<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\BrowserSessionService;
use Illuminate\Support\Facades\DB;

/**
 * Insert a session row as the database session driver would.
 */
function insertSessionRow(User $user, string $sessionId, string $userAgent, int $lastActivity): void
{
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->getKey(),
        'ip_address' => '203.0.113.7',
        'user_agent' => $userAgent,
        'payload' => '',
        'last_activity' => $lastActivity,
    ]);
}

beforeEach(function () {
    config(['session.driver' => 'database']);

    $this->user = User::factory()->create();
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
});

test('the sessions page needs a recent password confirmation', function () {
    $this->flushSession();

    $this->get(route('sessions.index'))
        ->assertRedirect(route('password.confirm'));
});

test('the sessions page lists the user browsers without raw session ids', function () {
    insertSessionRow($this->user, 'other-session-id', 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36', time() - 60);
    insertSessionRow(User::factory()->create(), 'someone-else', 'Mozilla/5.0 (Macintosh) Firefox/130.0', time());

    $response = $this->get(route('sessions.index'))->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->component('settings/sessions')
        ->has('sessions', 1)
        ->where('sessions.0.browser', 'Chrome')
        ->where('sessions.0.platform', 'Windows')
        ->where('sessions.0.ipAddress', '203.0.113.7')
        ->where('sessions.0.publicId', hash('sha256', 'other-session-id')));

    expect($response->getContent())->not->toContain('other-session-id')
        ->and($response->getContent())->not->toContain('someone-else');
});

test('a user signs out one of their other browsers', function () {
    insertSessionRow($this->user, 'other-session-id', 'Firefox/130.0', time());

    $this->delete(route('sessions.destroy', hash('sha256', 'other-session-id')))
        ->assertRedirect(route('sessions.index'));

    expect(DB::table('sessions')->where('id', 'other-session-id')->exists())->toBeFalse();
});

test('a user cannot sign out a browser of another user', function () {
    $otherUser = User::factory()->create();
    insertSessionRow($otherUser, 'foreign-session-id', 'Firefox/130.0', time());

    $this->delete(route('sessions.destroy', hash('sha256', 'foreign-session-id')))
        ->assertNotFound();

    expect(DB::table('sessions')->where('id', 'foreign-session-id')->exists())->toBeTrue();
});

test('signing out all other browsers keeps the current one and cycles the remember token', function () {
    insertSessionRow($this->user, 'other-session-a', 'Firefox/130.0', time());
    insertSessionRow($this->user, 'other-session-b', 'Chrome/130.0', time());
    $this->user->forceFill(['remember_token' => 'old-remember-token'])->save();

    $this->delete(route('sessions.destroy-others'))->assertRedirect(route('sessions.index'));

    expect(DB::table('sessions')->where('user_id', $this->user->getKey())->whereIn('id', ['other-session-a', 'other-session-b'])->exists())->toBeFalse()
        ->and($this->user->refresh()->remember_token)->not->toBe('old-remember-token');
});

test('the user agent is reduced to browser and platform', function (string $userAgent, string $browser, string $platform) {
    insertSessionRow($this->user, 'agent-session', $userAgent, time());

    $session = app(BrowserSessionService::class)->sessionsFor($this->user, 'current')[0];

    expect($session->browser)->toBe($browser)
        ->and($session->platform)->toBe($platform);
})->with([
    'edge on windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0', 'Edge', 'Windows'],
    'safari on iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile Safari/604.1', 'Safari', 'iOS'],
    'firefox on linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'Firefox', 'Linux'],
    'unknown' => ['curl/8.0', 'Unknown', 'Unknown'],
]);
