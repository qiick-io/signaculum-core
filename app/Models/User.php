<?php

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $subject OpenID Connect `sub`: opaque UUID, never reassigned
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $name first and last name
 * @property string $email
 * @property string|null $locale UI language, also the OIDC `locale` claim
 * @property Carbon|null $email_verified_at
 * @property bool $is_instance_admin may edit instance settings (SettingPolicy)
 * @property bool $is_active false refuses login (Fortify + EnsureUserIsActiveMiddleware)
 * @property int $failed_login_attempts consecutive wrong passwords since last success
 * @property Carbon|null $locked_until when set in the future, password login is refused
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['first_name', 'last_name', 'email', 'locale', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, LogsApplicationActivityTrait, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The full name travels with the user to the pages (avatar, menus).
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * Full display name from first and last name, as in externa-core (OIDC `name`).
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Preferred locale for queued mail (verification, password reset) and UI.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Never log secrets or session tokens on the user row.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token']);
    }

    /**
     * Only `subject` is a generated UUID; the primary key stays an auto-increment id.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['subject'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_instance_admin' => 'boolean',
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
