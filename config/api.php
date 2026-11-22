<?php

declare(strict_types=1);

return [
    'max_per_page' => 100,
    'default_per_page' => 25,
    'default_rate_limit_per_minute' => 60,

    /*
     * Token format, shared by the qiick fleet: `<prefix>_<environment>_<id><secret>`, where the
     * 8-character id is public (stored as `key_prefix` with the prefix and environment) and the
     * 32-character secret is shown once and stored only as its SHA-256. Secret scanners match
     * `^(ext|exc|ins|sig)_(live|test)_[A-Za-z0-9]{40,}$`.
     */
    'key_prefix' => 'sig',
    'key_environment' => env('APP_ENV') === 'production' ? 'live' : 'test',

    /*
     * How long the secret replaced by a rotation keeps working, in hours, so an integration
     * can switch to the new secret without downtime (app-ui docs/api-keys.md, behaviour 2).
     * 0 ends the old secret at once.
     */
    'key_rotation_grace_hours' => 24,

    /*
     * How many days before `expires_at` the owner (and the issuer) are told a key expires.
     */
    'key_expiry_warning_days' => 7,

    /*
     * The longest an Admin API key may live, in days, counted from its creation and again from
     * each rotation. Every key has an expiry within it; a request without one, or past it, is
     * refused (decision 2026-11-22 on the instance_service principal). It may only be lowered:
     * a value above 90, or below 1, stops the application at boot.
     */
    'key_max_lifetime_days' => (int) env('API_KEY_MAX_LIFETIME_DAYS', 90),
];
