<?php

declare(strict_types=1);

/*
 * qiick/laravel-outbound configuration.
 *
 * Defaults match the approved design (2026-11-20). Legacy WEBHOOKS_* env names
 * can be mapped by the product when adopting; this package reads OUTBOUND_* only.
 */
return [

    'migrations' => [

        /*
         * Load the package's mandatory migrations (the five webhook tables).
         * Turn off only after publishing them with
         * `vendor:publish --tag=outbound-migrations`, so they still run.
         */
        'load' => (bool) env('OUTBOUND_MIGRATIONS_LOAD', true),

    ],

    'ssrf' => [

        /*
         * Allow private, ULA, link-local and CGNAT destinations. Metadata,
         * NAT64, 6to4 and Teredo stay blocked either way. The ceiling: a
         * call's UrlGuardOptions or an endpoint's `allow_private_hosts` can
         * only narrow it, never allow what this refuses.
         */
        // Signaculum: open, and never from the environment. The instance setting "allow private
        // webhook URLs" (Settings → Webhooks) decides below it: every save passes it to the
        // guard and every endpoint carries it in `allow_private_hosts` (false refuses), and
        // webhooks are the only outbound calls the guard checks.
        'allow_private_hosts' => true,

        /*
         * Restrict server-side fetches to https. Browser-target checks may use
         * a different UrlGuardOptions per call.
         */
        'https_only' => (bool) env('OUTBOUND_HTTPS_ONLY', false),

    ],

    'http' => [

        'timeout_seconds' => (int) env('OUTBOUND_TIMEOUT_SECONDS', 5),

        'connect_timeout_seconds' => (int) env('OUTBOUND_CONNECT_TIMEOUT_SECONDS', 3),

        /*
         * Caps for per-call HttpRequestOptions. Values below 1s or above these
         * caps are rejected before any request is sent.
         */
        'max_timeout_seconds' => (int) env('OUTBOUND_MAX_TIMEOUT_SECONDS', 60),

        'max_connect_timeout_seconds' => (int) env('OUTBOUND_MAX_CONNECT_TIMEOUT_SECONDS', 30),

        /*
         * Wall-clock budget for the whole redirect chain (all hops).
         */
        'total_deadline_seconds' => (int) env('OUTBOUND_TOTAL_DEADLINE_SECONDS', 30),

        'max_response_bytes' => (int) env('OUTBOUND_MAX_RESPONSE_BYTES', 1_048_576),

        /*
         * Transport never auto-follows. When > 0, PinnedHttpClient re-authorises
         * and re-pins each hop up to this cap.
         */
        'max_redirects' => (int) env('OUTBOUND_MAX_REDIRECTS', 0),

        /*
         * A filtering forward proxy every outbound request goes through
         * (http:// or https://, optionally with credentials), as Standard
         * Webhooks recommends against SSRF. Null sends directly. Ambient
         * HTTP(S)_PROXY variables are never read. The guard still authorises
         * and pins each destination: the proxy only receives CONNECT to that
         * address. It is deployment config, never set per endpoint or call.
         */
        'egress_proxy' => env('OUTBOUND_EGRESS_PROXY'),

    ],

    'webhooks' => [

        'replay_tolerance_seconds' => (int) env('OUTBOUND_WEBHOOK_TOLERANCE_SECONDS', 300),

        'max_payload_bytes' => (int) env('OUTBOUND_WEBHOOK_MAX_PAYLOAD_BYTES', 1_048_576),

        'max_signatures' => (int) env('OUTBOUND_WEBHOOK_MAX_SIGNATURES', 8),

        'max_secrets' => (int) env('OUTBOUND_WEBHOOK_MAX_SECRETS', 4),

        /*
         * How long SecretRotator keeps the old primary signing after a
         * rotation, so consumers can switch keys without dropping messages.
         * A rotation may pass its own period; zero revokes the old key at once.
         */
        'secret_rotation_grace_seconds' => (int) env('OUTBOUND_WEBHOOK_SECRET_ROTATION_GRACE_SECONDS', 86_400),

        /*
         * RetrySchedule preset: "standard" (Standard Webhooks example) or
         * "short" ([10, 30, 60], migration window only).
         */
        'retry_preset' => env('OUTBOUND_WEBHOOK_RETRY_PRESET', 'standard'),

        'retry_jitter_seconds' => (int) env('OUTBOUND_WEBHOOK_RETRY_JITTER', 30),

        /*
         * Timeout of one webhook POST. Standard Webhooks "Request timeouts"
         * suggests 15-30 s; other outbound calls keep `http.timeout_seconds`
         * (5 s). An endpoint may override it, never above
         * `http.max_timeout_seconds`, and the chain deadline still applies.
         * The product's scheduler or job timeout must be longer.
         */
        'timeout_seconds' => (int) env('OUTBOUND_WEBHOOK_TIMEOUT_SECONDS', 15),

        /*
         * Honour Retry-After on 429, 502, 503 and 504: the retry never comes
         * sooner. A Retry-After above the cap stops automatic retries
         * (`exhausted`, `retry_after_exceeds_cap`) instead of being shortened.
         */
        'respect_retry_after' => (bool) env('OUTBOUND_WEBHOOK_RESPECT_RETRY_AFTER', true),

        // Effective ceiling 30 days: longer Retry-After values are read as 30 days.
        'retry_after_cap_seconds' => (int) env('OUTBOUND_WEBHOOK_RETRY_AFTER_CAP_SECONDS', 86_400),

        /*
         * Attempts in flight at once per endpoint, unless the endpoint sets
         * `max_concurrency`.
         */
        'default_max_concurrency' => (int) env('OUTBOUND_WEBHOOK_DEFAULT_MAX_CONCURRENCY', 2),

        /*
         * Days the encrypted body of a delivery is kept after dispatch, for
         * retries and manual redelivery (the maintainer's decision Q3 of
         * 2026-11-22: 30); never less than the delivery's longest possible
         * retry plan. `outbound:webhooks:prune` then clears it and keeps its
         * hash and length.
         */
        'payload_retention_days' => (int) env('OUTBOUND_WEBHOOK_PAYLOAD_RETENTION_DAYS', 30),

        /*
         * Days a settled delivery row and its attempts are kept before
         * `outbound:webhooks:prune` deletes them. At least the payload
         * retention. Pending deliveries, and rows whose body has not
         * expired yet, are never deleted.
         */
        'delivery_retention_days' => (int) env('OUTBOUND_WEBHOOK_DELIVERY_RETENTION_DAYS', 90),

        /*
         * Disable an endpoint at once on 410 Gone, and after
         * `no_success_days` without a success with at least
         * `min_consecutive_failures` failed attempts in a row. On by default
         * (standards review R8); an endpoint's `auto_disable` overrides it.
         */
        'auto_disable' => [
            'enabled' => (bool) env('OUTBOUND_WEBHOOK_AUTO_DISABLE', true),
            'no_success_days' => (int) env('OUTBOUND_WEBHOOK_AUTO_DISABLE_NO_SUCCESS_DAYS', 5),
            'min_consecutive_failures' => (int) env('OUTBOUND_WEBHOOK_AUTO_DISABLE_MIN_CONSECUTIVE_FAILURES', 10),
            // Email the endpoint's recipients when it is disabled automatically. Signaculum binds
            // WebhookEndpointNoticeRecipientsService: the creator while still an instance admin,
            // otherwise every active one.
            'notify_owner' => (bool) env('OUTBOUND_WEBHOOK_AUTO_DISABLE_NOTIFY_OWNER', true),
            // The email's only link: the Webhooks settings page lists every endpoint. From
            // APP_URL, never from the request that triggered the disable.
            'endpoint_url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/settings/instance/webhooks',
        ],

        /*
         * Allow WebhookSecret::parseAllowingLegacyRaw to accept non-whsec_
         * secrets. Off by default; products turn it on only during migration.
         */
        'allow_raw_legacy_secret' => (bool) env('OUTBOUND_WEBHOOK_ALLOW_RAW_LEGACY_SECRET', false),

        /*
         * Legacy formats by the name an endpoint's `legacy_profile` holds:
         * each value is a container binding of a LegacyWebhookProfileContract, for
         * example `['externa-1' => ExternaWebhookProfile::class]`. An endpoint
         * naming a profile that is not here is refused at every attempt.
         */
        'legacy_profiles' => [],

        /*
         * The product's own scope column on `webhook_endpoints` (for example
         * `project_id`), added by the product's migration. Null means one
         * instance-wide scope. Table names are fixed by the package.
         */
        'scope_column' => env('OUTBOUND_WEBHOOK_SCOPE_COLUMN'),

        /*
         * Table that `created_by` and `triggered_by` reference, with
         * null-on-delete. Null or empty keeps the columns without a foreign key.
         */
        'users_table' => env('OUTBOUND_WEBHOOK_USERS_TABLE', 'users'),

    ],

];
