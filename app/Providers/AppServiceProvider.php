<?php

namespace App\Providers;

use App\Enums\EmailTemplateTypeEnum;
use App\Enums\OidcScopeEnum;
use App\Http\Middleware\AddIssuerToAuthorizationResponseMiddleware;
use App\Http\Middleware\EnforceMaximumAuthenticationAgeMiddleware;
use App\Models\Client;
use App\Models\User;
use App\Oidc\IdTokenResponse;
use App\Oidc\OidcAuthCodeGrant;
use App\Oidc\OidcAuthCodeRepository;
use App\Policies\ActivityPolicy;
use App\Policies\ApplicationPolicy;
use App\Services\EmailTemplateService;
use App\Services\PendingAuthorizationService;
use App\Services\SettingsRepositoryService;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\AuthorizationServer;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;
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
        $this->configureTransactionalEmailTemplates();
        $this->configureRateLimiting();
        $this->configureOpenIdConnect();
        $this->configureActivityLogging();
        $this->registerAuthActivityListeners();
        $this->attachOidcRateLimitMiddleware();
        $this->attachAuthorizationResponseIssuerMiddleware();
        $this->attachMaximumAuthenticationAgeMiddleware();
    }

    /**
     * Verification and password-reset mail use instance-admin templates (#53), with
     * Signaculum-branded locale defaults when no override is saved.
     */
    protected function configureTransactionalEmailTemplates(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            return $this->transactionalMail(
                EmailTemplateTypeEnum::Verification,
                $notifiable,
                [
                    'url' => $url,
                ],
            );
        });

        ResetPassword::toMailUsing(function (object $notifiable, string $token): MailMessage {
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? $notifiable->getEmailForPasswordReset()
                : (string) data_get($notifiable, 'email', '');

            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $email,
            ], false));

            $expireMinutes = (int) config(
                'auth.passwords.'.config('auth.defaults.passwords').'.expire',
                60,
            );

            return $this->transactionalMail(
                EmailTemplateTypeEnum::Reset,
                $notifiable,
                [
                    'url' => $url,
                    'expire_minutes' => $expireMinutes,
                ],
            );
        });
    }

    /**
     * @param  array<string, string|int>  $extraPlaceholders
     */
    private function transactionalMail(
        EmailTemplateTypeEnum $type,
        object $notifiable,
        array $extraPlaceholders,
    ): MailMessage {
        $settings = app(SettingsRepositoryService::class);
        $locale = $notifiable instanceof User && is_string($notifiable->locale) && $notifiable->locale !== ''
            ? $notifiable->locale
            : $settings->defaultLanguage();

        $userName = $notifiable instanceof User
            ? $notifiable->name
            : (string) data_get($notifiable, 'name', '');

        return app(EmailTemplateService::class)->mailMessage(
            $type,
            $locale,
            [
                'app_name' => $settings->appName(),
                'user_name' => $userName,
                ...$extraPlaceholders,
            ],
        );
    }

    /**
     * Named limiters for OIDC and invite endpoints (S6). Limits read config at
     * request time so tests can lower them without re-registering.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('oidc-token', function (Request $request) {
            return Limit::perMinute((int) config('oidc.rate_limits.token_per_minute', 60))
                ->by((string) $request->ip());
        });

        RateLimiter::for('oidc-authorize', function (Request $request) {
            return Limit::perMinute((int) config('oidc.rate_limits.authorize_per_minute', 60))
                ->by((string) $request->ip());
        });

        RateLimiter::for('oidc-end-session', function (Request $request) {
            return Limit::perMinute((int) config('oidc.rate_limits.end_session_per_minute', 30))
                ->by((string) $request->ip());
        });

        RateLimiter::for('oidc-introspect', function (Request $request) {
            return Limit::perMinute((int) config('oidc.rate_limits.introspect_per_minute', 60))
                ->by((string) $request->ip());
        });

        // Reserved for organisation invitation POSTs (S5); registered now so routes can attach it.
        RateLimiter::for('organization-invitations', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('oidc.rate_limits.organization_invitations_per_minute', 10))
                ->by((string) $identifier);
        });

        RateLimiter::for('account-export', function (Request $request) {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(6)->by((string) $identifier);
        });
    }

    /**
     * Passport registers `/oauth/authorize` and `/oauth/token` itself; append the
     * named throttle middleware once the route collection is ready.
     */
    protected function attachOidcRateLimitMiddleware(): void
    {
        $this->app->booted(function (): void {
            $authorizeRouteNames = [
                'passport.authorizations.authorize',
                'passport.authorizations.approve',
                'passport.authorizations.deny',
            ];

            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if ($routeName === 'passport.token') {
                    $route->middleware('throttle:oidc-token');
                }

                if (is_string($routeName) && in_array($routeName, $authorizeRouteNames, true)) {
                    $route->middleware('throttle:oidc-authorize');
                }

                if ($routeName === 'password.email') {
                    $route->middleware('throttle:password-reset');
                }
            }
        });
    }

    /**
     * RFC 9207 `iss` only on Passport authorization responses (authorize / approve / deny).
     *
     * Must not run on the whole `web` group: an unrelated redirect whose query already
     * has `code` or `error` must stay untouched.
     */
    protected function attachAuthorizationResponseIssuerMiddleware(): void
    {
        $this->app->booted(function (): void {
            $authorizeRouteNames = [
                'passport.authorizations.authorize',
                'passport.authorizations.approve',
                'passport.authorizations.deny',
            ];

            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if (is_string($routeName) && in_array($routeName, $authorizeRouteNames, true)) {
                    $route->middleware(AddIssuerToAuthorizationResponseMiddleware::class);
                }
            }
        });
    }

    /**
     * OIDC Core §3.1.2.1 `max_age` / `prompt=login` only on the authorize endpoint.
     *
     * Must run before Passport's AuthorizationController so re-authentication does not
     * rely on Passport's own logout (which would fan out back-channel logout).
     */
    protected function attachMaximumAuthenticationAgeMiddleware(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->getName() === 'passport.authorizations.authorize') {
                    $route->middleware(EnforceMaximumAuthenticationAgeMiddleware::class);
                }
            }
        });
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

        // Lazy loading, silently discarded and missing attributes throw outside
        // production, so tests catch them (qiick standards, Automated checks).
        Model::shouldBeStrict(! app()->isProduction());

        // Settings → Authentication & security; `strong` in production until an admin saves.
        Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());
    }

    /**
     * Turn Passport into an OpenID Connect provider: OIDC scopes, the id_token response
     * type, the authorization code grant with the qiick profile, and the consent page.
     */
    protected function configureOpenIdConnect(): void
    {
        Passport::useClientModel(Client::class);
        Gate::policy(Client::class, ApplicationPolicy::class);

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

        // OIDC Login/Logout / amr listeners are auto-discovered from app/Listeners
        // (manual Event::listen would register them twice and break sid preservation
        // during max_age / prompt=login re-authentication).
    }

    /**
     * Every activity also records the request IP and user agent.
     */
    protected function configureActivityLogging(): void
    {
        Gate::policy(ActivityModel::class, ActivityPolicy::class);

        Activity::beforeLogging(function (ActivityModel $activity): void {
            $activity->properties = ($activity->properties ?? collect())->merge([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });
    }

    /**
     * Logs logins, logouts and failed login attempts in the `auth` log.
     */
    protected function registerAuthActivityListeners(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('login')
                ->log('User logged in');
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if (! $event->user instanceof User) {
                return;
            }

            activity()
                ->causedBy($event->user)
                ->useLog('auth')
                ->event('logout')
                ->log('User logged out');
        });

        Event::listen(Failed::class, function (Failed $event): void {
            $identifier = $event->credentials['email'] ?? null;

            activity()
                ->causedByAnonymous()
                ->useLog('auth')
                ->event('failed')
                ->withProperties([
                    'identifier' => is_string($identifier) ? $identifier : null,
                ])
                ->log('Failed login attempt');
        });
    }
}
