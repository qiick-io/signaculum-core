<?php

namespace App\Providers;

use App\Enums\OidcScopeEnum;
use App\Listeners\RecordAuthenticationTimeListener;
use App\Oidc\IdTokenResponse;
use App\Oidc\OidcAuthCodeGrant;
use App\Oidc\OidcAuthCodeRepository;
use App\Services\PendingAuthorizationService;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\AuthorizationServer;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Device authorization is not part of the Signaculum profile.
        Passport::$deviceCodeGrantEnabled = false;

        $this->app->bind(AuthCodeRepository::class, OidcAuthCodeRepository::class);
        $this->app->singleton(SettingsRepositoryService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureOpenIdConnect();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Settings → Authentication & security; `strong` in production until an admin saves.
        Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());
    }

    /**
     * Turn Passport into an OpenID Connect provider: OIDC scopes, the id_token response
     * type, the authorization code grant with the qiick profile, and the consent page.
     */
    protected function configureOpenIdConnect(): void
    {
        Passport::tokensCan(OidcScopeEnum::descriptions());
        Passport::useAuthorizationServerResponseType(new IdTokenResponse);

        // Replaces Passport's own authorization_code grant (same identifier).
        $this->app->extend(AuthorizationServer::class, function (AuthorizationServer $authorizationServer): AuthorizationServer {
            $authCodeGrant = new OidcAuthCodeGrant(
                $this->app->make(AuthCodeRepository::class),
                $this->app->make(RefreshTokenRepository::class),
                new DateInterval('PT10M'),
                $this->app->make(PendingAuthorizationService::class),
            );
            $authCodeGrant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

            $authorizationServer->enableGrantType($authCodeGrant, Passport::tokensExpireIn());

            return $authorizationServer;
        });

        Passport::authorizationView(function (array $parameters): Response {
            /** @var list<Scope> $scopes */
            $scopes = $parameters['scopes'];

            return Inertia::render('oauth/authorize', [
                'clientName' => $parameters['client']->name,
                'scopes' => array_map(fn (Scope $scope): array => [
                    'id' => $scope->id,
                    'description' => $scope->description,
                ], $scopes),
                'authToken' => $parameters['authToken'],
                'csrfToken' => csrf_token(),
            ])->toResponse(request());
        });

        Event::listen(Login::class, RecordAuthenticationTimeListener::class);
    }
}
