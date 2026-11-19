<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\LogoutResponse;
use App\Models\User;
use App\Services\LdapBindAuthenticationService;
use App\Services\LoginLockoutService;
use App\Services\SettingsRepositoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use RuntimeException;

/**
 * Fortify views, actions and rate limiters. Registration stays registered as a Fortify feature
 * so the routes exist, but the instance setting `registration_enabled` (off by default) gates
 * the register view, the CreateNewUser action and the login "Sign up" link.
 */
class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Refuse inactive users on every local login path (password, two-factor and passkey).
     *
     * LDAP-sourced users authenticate only by binding to the directory (#187); the local
     * password hash is never consulted for them. Directory unreachable fails closed with a
     * distinct message so local and break-glass admins keep working.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $loginLockoutService = app(LoginLockoutService::class);
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();

            if ($user === null) {
                return null;
            }

            if ($loginLockoutService->isLocked($user)) {
                throw ValidationException::withMessages([
                    Fortify::username() => __('This account is temporarily locked. Try again later or ask an administrator to unlock it.'),
                ]);
            }

            if (! $user->is_active) {
                return null;
            }

            if ($user->isLdapSourced()) {
                try {
                    $isBound = app(LdapBindAuthenticationService::class)
                        ->attempt($user, (string) $request->input('password'));
                } catch (RuntimeException) {
                    throw ValidationException::withMessages([
                        Fortify::username() => __('The identity directory is unreachable. Sign in with a local account or try again later.'),
                    ]);
                }

                if (! $isBound) {
                    $loginLockoutService->recordFailure($user);

                    return null;
                }

                $loginLockoutService->clear($user);

                return $user;
            }

            if ($user->password === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                $loginLockoutService->recordFailure($user);

                return null;
            }

            $loginLockoutService->clear($user);

            return $user;
        });

        Passkeys::authorizeLoginUsing(
            fn (Request $request, PasskeyUser $user): bool => $user instanceof User && $user->is_active,
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            $settings = app(SettingsRepositoryService::class);

            return Inertia::render('auth/login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => Features::enabled(Features::registration())
                    && $settings->isRegistrationEnabled(),
                'canEmailCodeLogin' => $settings->isEmailCodeLoginEnabled(),
                'status' => $request->session()->get('status'),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/verify-email', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(function () {
            if (! Features::enabled(Features::registration())
                || ! app(SettingsRepositoryService::class)->isRegistrationEnabled()) {
                abort(403);
            }

            return Inertia::render('auth/register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(app(SettingsRepositoryService::class)->loginMaxAttempts())->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute((int) config('oidc.rate_limits.password_reset_per_minute', 5))
                ->by((string) $request->ip());
        });
    }
}
