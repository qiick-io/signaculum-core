<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\EmailTemplateTypeEnum;
use App\Enums\OidcScopeEnum;
use App\Enums\WebhookEventEnum;
use App\Http\Controllers\Oidc\AuthorizationEndpointPostController;
use App\Http\Middleware\AddIssuerToAuthorizationResponseMiddleware;
use App\Http\Middleware\EnforceMaximumAuthenticationAgeMiddleware;
use App\Http\Middleware\RejectUnsupportedRequestObjectMiddleware;
use App\Http\Middleware\ResolvePushedAuthorizationRequestMiddleware;
use App\Models\Activity as ActivityModel;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\User;
use App\Oidc\IdTokenResponse;
use App\Oidc\OidcAuthCodeGrant;
use App\Oidc\OidcAuthCodeRepository;
use App\Oidc\OidcDeviceCodeGrant;
use App\Oidc\OidcDeviceCodeRepository;
use App\Policies\ActivityPolicy;
use App\Policies\ApplicationPolicy;
use App\Services\ActivityOrganisationResolverService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\EmailTemplateService;
use App\Services\InstanceAdminDemotionService;
use App\Services\PendingAuthorizationService;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookDispatcherService;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use League\OAuth2\Server\AuthorizationServer;
use Spatie\Activitylog\Facades\Activity;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Symfony\Component\HttpFoundation\Response;

/**
 * Boots Signaculum application defaults: Passport OIDC wiring, rate limits,
 * activity logging, transactional mail templates, and shared auth listeners.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Device authorization (RFC 8628) is enabled; Passport registers the grant and routes.
        Passport::$deviceCodeGrantEnabled = true;

        $this->app->bind(AuthCodeRepository::class, OidcAuthCodeRepository::class);
        $this->app->bind(DeviceCodeRepository::class, OidcDeviceCodeRepository::class);
        $this->app->singleton(SettingsRepositoryService::class);
        $this->app->singleton(EffectivePermissionResolverService::class);
        // One instance per process, so a tracked change nested in another is reported once.
        $this->app->singleton(InstanceAdminDemotionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureTrustedProxies();
        $this->configureTransactionalEmailTemplates();
        $this->configureRateLimiting();
        $this->configureOpenIdConnect();
        $this->configureActivityLogging();
        $this->configureApiKeyGuard();
        $this->registerAuthActivityListeners();
        $this->attachOidcRateLimitMiddleware();
        $this->attachAuthorizationResponseIssuerMiddleware();
        // Order matters: a PAR request_uri is expanded before max_age / prompt=login run.
        $this->attachUnsupportedRequestObjectMiddleware();
        $this->attachPushedAuthorizationRequestMiddleware();
        $this->attachMaximumAuthenticationAgeMiddleware();
        $this->configureAuthorizationEndpointPost();
    }

    /**
     * Super-admin bypass for permission-name abilities. Model policies decide for themselves
     * (they keep invariants that bind super-admins too), matching externa-core.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function (?User $user, string $ability, array $arguments): ?bool {
            if ($user === null) {
                return null;
            }

            if ($arguments !== [] && Gate::getPolicyFor($arguments[0]) !== null) {
                return null;
            }

            if (app(EffectivePermissionResolverService::class)->isSuperAdmin($user)) {
                return true;
            }

            return null;
        });

        $forgetEffectivePermissions = function (object $event): void {
            $model = $event->model ?? null;

            if ($model instanceof User) {
                app(EffectivePermissionResolverService::class)->forget($model);
            }
        };

        Event::listen(RoleAttachedEvent::class, $forgetEffectivePermissions);
        Event::listen(RoleDetachedEvent::class, $forgetEffectivePermissions);
        Event::listen(PermissionAttachedEvent::class, $forgetEffectivePermissions);
        Event::listen(PermissionDetachedEvent::class, $forgetEffectivePermissions);
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

        // RFC 8628 §5.1: slow user-code guessing. A user code carries about 34.6 bits
        // (8 of 20 letters), safe only while guesses are throttled. Codes are checked on a
        // signed-in route, so the budget is per account as well as per IP: spreading
        // guesses over many addresses does not buy one account more attempts.
        RateLimiter::for('oidc-device', function (Request $request) {
            $perMinute = (int) config('oidc.rate_limits.device_per_minute', 60);
            $limits = [Limit::perMinute($perMinute)->by('ip:'.$request->ip())];
            $userIdentifier = $request->user()?->getAuthIdentifier();

            if ($userIdentifier !== null) {
                $limits[] = Limit::perMinute($perMinute)->by('user:'.$userIdentifier);
            }

            return $limits;
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

        RateLimiter::for('api', function (Request $request) {
            $apiKey = $request->attributes->get('apiKey');
            $identifier = is_object($apiKey) && method_exists($apiKey, 'getKey')
                ? 'api-key:'.$apiKey->getKey()
                : (string) $request->ip();

            return Limit::perMinute((int) config('api.default_rate_limit_per_minute', 60))
                ->by($identifier);
        });

        RateLimiter::for('email-login-code', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email', '')).'|'.$request->ip());
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

            $deviceRouteNames = [
                'passport.device',
                'passport.device.code',
                'passport.device.authorizations.authorize',
                'passport.device.authorizations.approve',
                'passport.device.authorizations.deny',
            ];

            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if ($routeName === 'passport.token') {
                    $route->middleware('throttle:oidc-token');
                }

                if (is_string($routeName) && in_array($routeName, $authorizeRouteNames, true)) {
                    $route->middleware('throttle:oidc-authorize');
                }

                if (is_string($routeName) && in_array($routeName, $deviceRouteNames, true)) {
                    $route->middleware('throttle:oidc-device');
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
     * OIDC Core §3.1.2.1 `max_age` / `prompt=login` on authorize GET and form POST.
     *
     * Must run before Passport's AuthorizationController so re-authentication does not
     * rely on Passport's own logout (which would fan out back-channel logout).
     */
    protected function attachMaximumAuthenticationAgeMiddleware(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if (in_array($routeName, [
                    'passport.authorizations.authorize',
                    'passport.authorizations.approve',
                ], true)) {
                    $route->middleware(EnforceMaximumAuthenticationAgeMiddleware::class);
                }
            }
        });
    }

    /**
     * OIDC Core §6.1 / §3.1.2.6: reject `request` / `request_uri` when unsupported.
     *
     * Must run before Passport validates the authorization request so a Request Object
     * never produces a code that ignored `state` / `nonce` from the JWT. Also on the
     * approve route name: form POST authorization requests share that path.
     */
    protected function attachUnsupportedRequestObjectMiddleware(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if (in_array($routeName, [
                    'passport.authorizations.authorize',
                    'passport.authorizations.approve',
                ], true)) {
                    $route->middleware(RejectUnsupportedRequestObjectMiddleware::class);
                }
            }
        });
    }

    /**
     * RFC 9126 §4 / §6: expand PAR `request_uri` on the authorization endpoint and
     * refuse plain requests from clients that require PAR.
     *
     * {@see RejectUnsupportedRequestObjectMiddleware} lets PAR URIs through; this
     * middleware consumes them (or returns `invalid_request`) before Passport runs.
     */
    protected function attachPushedAuthorizationRequestMiddleware(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $routeName = $route->getName();

                if (in_array($routeName, [
                    'passport.authorizations.authorize',
                    'passport.authorizations.approve',
                ], true)) {
                    $route->middleware(ResolvePushedAuthorizationRequestMiddleware::class);
                }
            }
        });
    }

    /**
     * OIDC Core §3.1.2.1: accept form-serialized POST authorization requests.
     *
     * Passport maps POST `/oauth/authorize` to consent approval only (and wraps it in
     * `auth`). Replace the action with {@see AuthorizationEndpointPostController} and
     * drop the route-level `auth` middleware so guests can POST an authorization
     * request and be sent to login, matching GET. Consent still requires a session.
     */
    protected function configureAuthorizationEndpointPost(): void
    {
        $this->app->booted(function (): void {
            foreach (Route::getRoutes()->getRoutes() as $route) {
                if ($route->getName() !== 'passport.authorizations.approve') {
                    continue;
                }

                $route->action['uses'] = AuthorizationEndpointPostController::class.'@__invoke';
                $route->action['controller'] = AuthorizationEndpointPostController::class.'@__invoke';

                $middleware = $route->action['middleware'] ?? [];

                if (! is_array($middleware)) {
                    continue;
                }

                $route->action['middleware'] = array_values(array_filter(
                    $middleware,
                    function (mixed $name): bool {
                        if (! is_string($name)) {
                            return true;
                        }

                        return $name !== 'auth' && ! str_starts_with($name, 'auth:');
                    },
                ));
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
     * Honour TRUSTED_PROXIES / APP_URL behind a TLS-terminating proxy.
     *
     * `php artisan serve` only passes a short env whitelist into the PHP built-in
     * server worker, so bootstrap/app.php getenv('TRUSTED_PROXIES') is often empty
     * there. Config is loaded from `.env` after Dotenv, so this boot hook is the
     * reliable place for local Docker conformance and CI `artisan serve`.
     */
    protected function configureTrustedProxies(): void
    {
        $appUrl = (string) config('app.url');

        if (str_starts_with($appUrl, 'https://')) {
            URL::forceScheme('https');
        }

        $trustedProxies = config('app.trusted_proxies');

        if (! is_string($trustedProxies) || $trustedProxies === '') {
            return;
        }

        TrustProxies::at(
            $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map(
                    static fn (string $proxy): string => trim($proxy),
                    explode(',', $trustedProxies),
                ))),
        );
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * Turn Passport into an OpenID Connect provider: OIDC scopes, the id_token response
     * type, the authorization code grant with the qiick profile, and the consent page.
     */
    protected function configureOpenIdConnect(): void
    {
        Passport::useClientModel(Client::class);
        Gate::policy(Client::class, ApplicationPolicy::class);

        // Short-lived access tokens and sliding refresh (RFC 9700); env ISO 8601 durations (#163).
        Passport::tokensExpireIn(new DateInterval((string) config('oidc.access_token_ttl', 'PT1H')));
        Passport::refreshTokensExpireIn(new DateInterval((string) config('oidc.refresh_token_ttl', 'P30D')));

        Passport::tokensCan(OidcScopeEnum::descriptions());
        Passport::useAuthorizationServerResponseType(new IdTokenResponse);

        // Replaces Passport's own authorization_code and device_code grants (same identifiers).
        $this->app->extend(AuthorizationServer::class, function (AuthorizationServer $authorizationServer): AuthorizationServer {
            $authCodeGrant = new OidcAuthCodeGrant(
                $this->app->make(AuthCodeRepository::class),
                $this->app->make(RefreshTokenRepository::class),
                new DateInterval('PT10M'),
                $this->app->make(PendingAuthorizationService::class),
            );
            $authCodeGrant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());

            $authorizationServer->enableGrantType($authCodeGrant, Passport::tokensExpireIn());

            if (Passport::$deviceCodeGrantEnabled && Route::has('passport.device')) {
                $deviceCodeGrant = new OidcDeviceCodeGrant(
                    $this->app->make(DeviceCodeRepository::class),
                    $this->app->make(RefreshTokenRepository::class),
                    new DateInterval('PT10M'),
                    route('passport.device'),
                    5,
                );
                $deviceCodeGrant->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
                $deviceCodeGrant->setIncludeVerificationUriComplete(true);
                $deviceCodeGrant->setIntervalVisibility(true);

                $authorizationServer->enableGrantType($deviceCodeGrant, Passport::tokensExpireIn());
            }

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

        // RFC 8628 device verification UI (Passport device grant).
        Passport::deviceUserCodeView(function (array $parameters): Response {
            return Inertia::render('oauth/device/user-code', [
                'userCode' => old('user_code', $parameters['request']->query('user_code')),
                'errors' => $parameters['request']->session()->get('errors')?->getBagMessages() ?? [],
            ])->toResponse($parameters['request']);
        });

        Passport::deviceAuthorizationView(function (array $parameters): Response {
            /** @var list<Scope> $scopes */
            $scopes = $parameters['scopes'];

            return Inertia::render('oauth/device/authorize', [
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

        // An entry written during an Admin API request names the key that authorised it.
        // Organisation-scoped actions also stamp `organisation_id` (fleet baseline).
        Activity::beforeLogging(function (ActivityModel $activity): void {
            $apiKey = request()->attributes->get('apiKey');
            $properties = collect($activity->properties ?? []);
            $organisationId = app(ActivityOrganisationResolverService::class)->resolve(
                $activity->subject,
                $properties->all(),
            );

            if ($organisationId !== null) {
                $activity->organisation_id = $organisationId;
            }

            $activity->properties = $properties->merge([
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
                ...($apiKey instanceof ApiKey ? ['api_key_id' => $apiKey->uuid] : []),
            ]);
        });
    }

    /**
     * The `api-key` guard: the user is the owner of the key AuthenticateApiKeyMiddleware put on
     * the request, and nobody on any request it did not authenticate.
     */
    protected function configureApiKeyGuard(): void
    {
        Auth::viaRequest('api-key', static function (Request $request): ?User {
            $apiKey = $request->attributes->get('apiKey');

            return $apiKey instanceof ApiKey ? $apiKey->owner : null;
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

            app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserSignedIn, [
                'id' => $event->user->subject,
                'email' => $event->user->email,
            ]);
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
