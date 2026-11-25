<?php

declare(strict_types=1);

return [
    'max_per_page' => 100,
    'default_per_page' => 25,
    /*
     * API key issuance, verification, rotation, rate limits and expiry notices are configured in
     * config/api-keys.php (qiick/laravel-api-keys). Only the lifetime ceiling stays here: it is
     * Signaculum's own rule, enforced by ApiKeyLifetimeService as the package's LifetimePolicy.
     */

    /*
     * The longest an Admin API key may live, in days, counted from its creation and again from
     * each rotation. Every key has an expiry within it; a request without one, or past it, is
     * refused (decision 2026-11-22 on the instance_service principal). It may only be lowered:
     * a value above 90, or below 1, stops the application at boot.
     */
    'key_max_lifetime_days' => (int) env('API_KEY_MAX_LIFETIME_DAYS', 90),
];
