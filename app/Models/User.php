<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrincipalTypeEnum;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Traits\LogsApplicationActivityTrait;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $subject Fleet public_id and OpenID Connect `sub`: opaque UUID, never reassigned (declared divergence: no second public_id column)
 * @property string $first_name
 * @property string|null $last_name
 * @property-read string $name first and last name
 * @property string $email
 * @property string|null $username optional, unique and stored lowercase (fleet baseline / #206)
 * @property string|null $ldap_guid converted objectGUID / entryUUID when synced from LDAP
 * @property string|null $ldap_dn directory distinguished name for bind-on-login
 * @property string|null $locale UI language, also the OIDC `locale` claim
 * @property string $timezone IANA zone for the account (fleet baseline, default UTC)
 * @property Carbon|null $email_verified_at
 * @property bool $is_active false refuses login (Fortify + EnsureUserIsActiveMiddleware)
 * @property int $failed_login_attempts consecutive wrong passwords since last success
 * @property Carbon|null $locked_until when set in the future, password login is refused
 * @property string|null $password null for LDAP-sourced users (directory bind only, #187)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['first_name', 'last_name', 'email', 'username', 'locale', 'timezone', 'password', 'ldap_guid', 'ldap_dn'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, LogsApplicationActivityTrait, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The full name travels with the user to the pages (avatar, menus).
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * Every user gets a fleet `user` principal (API-key owner) on create.
     */
    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if (Principal::query()->where('user_id', $user->getKey())->exists()) {
                return;
            }

            Principal::query()->create([
                'type' => PrincipalTypeEnum::User,
                'user_id' => $user->getKey(),
                'organisation_id' => null,
                'name' => $user->name !== '' ? $user->name : $user->email,
            ]);
        });
    }

    /**
     * Full display name from first and last name, as in externa-core (OIDC `name`).
     */
    public function getNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Fleet principal for this person (one per user).
     *
     * @return HasOne<Principal, $this>
     */
    public function principal(): HasOne
    {
        return $this->hasOne(Principal::class);
    }

    /**
     * Groups the user belongs to; their roles add to the user's own (EffectivePermissionResolverService).
     *
     * @return BelongsToMany<UserGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_user')->withTimestamps();
    }

    /**
     * Whether the user holds the super-admin role (direct or via a group).
     */
    public function isSuperAdmin(): bool
    {
        return app(EffectivePermissionResolverService::class)->isSuperAdmin($this);
    }

    /**
     * The one canonical form of a username, shared by the model and the request validators.
     */
    public static function normalizeUsername(?string $value): ?string
    {
        $normalized = mb_strtolower(trim($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Whether a value matches the fleet username rules (length + charset) after normalization.
     */
    public static function isValidUsername(?string $value): bool
    {
        $normalized = self::normalizeUsername($value);

        if ($normalized === null) {
            return false;
        }

        $length = mb_strlen($normalized);

        return $length >= 3
            && $length <= 32
            && preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $normalized) === 1;
    }

    /**
     * Canonicalize usernames on every write, including CLI and seeder writes that bypass the
     * request validators. The unique index then compares lowercase values on every database.
     */
    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = self::normalizeUsername($value);
    }

    /**
     * Preferred locale for queued mail (verification, password reset) and UI.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }

    /**
     * Linked to a directory entry by sync (`ldap_guid` set). Credentials live in the directory.
     */
    /**
     * Spatie roles always use the web guard. API-key auth must not look up roles on `api-key`.
     */
    protected function getDefaultGuardName(): string
    {
        return 'web';
    }

    public function isLdapSourced(): bool
    {
        return $this->ldap_guid !== null && $this->ldap_guid !== '';
    }

    /**
     * Refuse Fortify password-reset mail for directory-managed accounts (#187).
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        if ($this->isLdapSourced()) {
            throw ValidationException::withMessages([
                'email' => __('This account\'s password is managed by the directory. Contact your administrator.'),
            ]);
        }

        parent::sendPasswordResetNotification($token);
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
            'is_active' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
