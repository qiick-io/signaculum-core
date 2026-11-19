<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Route;

/**
 * A door that fails validation: Laravel builds the message through the translator, so the
 * response shows both the negotiated locale and the translated framework message.
 */
function registerLocaleTestDoor(): void
{
    $validateEmail = function (): void {
        request()->validate(['email' => 'required']);
    };

    Route::middleware('api')->get('/api/__test/locale/validation', $validateEmail);
    Route::middleware('api')->get('/mcp/__test-validation', $validateEmail);
}

beforeEach(function (): void {
    registerLocaleTestDoor();
});

it('negotiates an api language from Accept-Language and translates the message', function (): void {
    $response = $this->withHeader('Accept-Language', 'it')->getJson('/api/__test/locale/validation');

    $response->assertUnprocessable()
        ->assertHeader('Content-Language', 'it')
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonPath('errors.email.0', 'Il campo email è obbligatorio.');
});

it('respects Accept-Language quality values on api and mcp', function (string $uri): void {
    $response = $this->withHeader('Accept-Language', 'de;q=0.9, en;q=0.5')->get($uri);

    $response->assertHeader('Content-Language', 'de')
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonPath('errors.email.0', 'Das Feld email muss ausgefüllt werden.');
})->with([
    'api' => ['/api/__test/locale/validation'],
    'mcp' => ['/mcp/__test-validation'],
]);

it('falls through an unsupported Accept-Language to the instance default', function (): void {
    app(SettingsRepositoryService::class)->save(['default_language' => 'it']);

    $response = $this->withHeader('Accept-Language', 'fr')->getJson('/api/__test/locale/validation');

    $response->assertUnprocessable()
        ->assertHeader('Content-Language', 'it')
        ->assertJsonPath('error', 'validation_failed');
});

it('uses the authenticated api principal when no language was requested', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    User::query()->where('is_instance_admin', true)->update(['locale' => 'de']);

    // Symfony's test client injects `Accept-Language: en-us,en;q=0.5` when none is sent.
    $response = $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])
        ->withToken($plain)
        ->getJson('/api/v1/users');

    $response->assertOk()->assertHeader('Content-Language', 'de');
});

it('lets Accept-Language win over the authenticated api principal', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    User::query()->where('is_instance_admin', true)->update(['locale' => 'de']);

    $response = $this->withHeader('Accept-Language', 'it')->withToken($plain)->getJson('/api/v1/users');

    $response->assertOk()->assertHeader('Content-Language', 'it');
});

it('keeps the web order of user, cookie and instance default', function (): void {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->withUnencryptedCookie('locale', 'it')
        ->withHeader('Accept-Language', 'en')
        ->get(route('dashboard'))
        ->assertHeader('Content-Language', 'de');
});

it('sends Content-Language on successful and failed responses', function (): void {
    $this->get(route('login'))->assertOk()->assertHeader('Content-Language', 'en');

    $this->get('/api/__test/locale/validation')
        ->assertUnprocessable()
        ->assertHeader('Content-Language', 'en');
});
