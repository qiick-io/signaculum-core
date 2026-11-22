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
];
