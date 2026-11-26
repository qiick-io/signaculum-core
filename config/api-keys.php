<?php

declare(strict_types=1);
use App\Notifications\ApiKeyExpiringNotification;
use Qiick\ApiKeys\Notifications\ApiKeyLegacyFormatWarningNotification;

/*
 * qiick/laravel-api-keys configuration.
 *
 * Products own models, migrations, scope enums and HTTP middleware. This file
 * only tunes the contract-only mechanics (codec, grace, usage, rate brakes,
 * expiry notices).
 */
return [

    /*
     * Three-letter product prefix embedded in issued tokens (e.g. "exc", "sig").
     * Required before issue() can run; leave null until the app sets it.
     */
    'product_prefix' => env('API_KEYS_PRODUCT_PREFIX', 'sig'),

    /*
     * Force live/test for issuance. Null → EnvironmentResolver (app environment).
     */
    'environment' => env('API_KEYS_ENVIRONMENT'),

    /*
     * Default issuance token version: 2 (checksum). Version 1 stays accepted.
     */
    'token_version' => (int) env('API_KEYS_TOKEN_VERSION', 2),

    /*
     * Hours the previous secret stays valid after rotation. Invalid, negative,
     * or above rotation_max_grace_hours → 0 (no grace).
     */
    'rotation_grace_hours' => (int) env('API_KEYS_ROTATION_GRACE_HOURS', 24),

    /*
     * Ceiling for rotation_grace_hours (default one week). Larger values are
     * treated as invalid → 0 by RotationService::normalizeGraceHours.
     */
    'rotation_max_grace_hours' => (int) env('API_KEYS_ROTATION_MAX_GRACE_HOURS', 168),

    'usage_throttle_seconds' => (int) env('API_KEYS_USAGE_THROTTLE_SECONDS', 60),

    'usage_bypass_throttle_on_ip_change' => (bool) env('API_KEYS_USAGE_BYPASS_ON_IP_CHANGE', true),

    /*
     * Default per-key rate limit (attempts per minute). 0 disables.
     */
    'default_rate_limit_per_minute' => (int) env('API_KEYS_DEFAULT_RATE_LIMIT_PER_MINUTE', 60),

    /*
     * Pre-auth per-IP brake for unknown/invalid tokens. 0 disables.
     * Recipes should leave this on (20/min). Not cast: anything but digits
     * fails at boot, so a typo cannot silently switch the brake off.
     */
    'failed_attempts_per_minute' => env('API_KEYS_FAILED_ATTEMPTS_PER_MINUTE', 20),

    /*
     * Optional organisation max lifetime in days, enforced on rotation by the
     * default ConfigLifetimePolicy (a product binding of LifetimePolicy
     * replaces it). Unset disables it; any other value must be a whole number
     * >= 1, or the app fails at boot. Not cast here, so a typo cannot
     * silently become another number.
     */
    'max_lifetime_days' => env('API_KEYS_MAX_LIFETIME_DAYS'),

    'expiry' => [
        /*
         * Notify when expires_at is within this many days and expiry_notified_at is null.
         */
        'notice_days' => (int) env('API_KEYS_EXPIRY_NOTICE_DAYS', 7),

        /*
         * Claim lease for api-keys:notify-expiring (app/worker clock, not DB
         * NOW()). A worker that dies after claimExpiryNotice but before send
         * is reclaimable once this many seconds have passed
         * (expiry_notice_claimed_at). Keep this above worst-case send latency:
         * an in-flight send longer than the lease can be reclaimed by another
         * worker and produce a second notice. Workers must share NTP-synced
         * UTC; skew can reclaim a live claim early. Digits only, >= 1, or the
         * app fails at boot.
         */
        'claim_lease_seconds' => env('API_KEYS_EXPIRY_CLAIM_LEASE_SECONDS', 900),

        /*
         * Roles resolved by ExpiryRecipientResolver: owner, issuer, organisation.
         *
         * @var list<string>
         */
        'recipients' => ['owner', 'issuer'],
        /*
         * Notification class (products may replace).
         *
         * @var class-string
         */
        // Signaculum's localised mail (lang/<locale>/api_keys.php); the command builds it with ($record, $expiresAt).
        'notification' => ApiKeyExpiringNotification::class,
    ],

    /*
     * Legacy matchers (class names). Boot fails when non-empty without accept_until.
     *
     * @var list<class-string>
     */
    'legacy_matchers' => [],

    'legacy' => [
        /*
         * Absolute ISO-8601 with offset (DATE_ATOM) or UTC Z, e.g. 2027-01-01T00:00:00Z
         * or 2027-01-01T00:00:00+00:00. Required when legacy_matchers is non-empty.
         * Relative strings and date-only values are rejected.
         */
        'accept_until' => env('API_KEYS_LEGACY_ACCEPT_UNTIL'),

        /*
         * Warn this many days before accept_until (legacy format EOL). 0 disables.
         */
        'warn_days_before' => env('API_KEYS_LEGACY_WARN_DAYS_BEFORE', 90),

        /*
         * Notification class for the T−90 legacy-format warning (N2).
         *
         * @var class-string
         */
        'warning_notification' => ApiKeyLegacyFormatWarningNotification::class,
    ],

];
