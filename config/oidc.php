<?php

declare(strict_types=1);

/*
 * OpenID Connect provider settings (protocol profile in the Signaculum design note).
 *
 * The strict defaults are the qiick profile: PKCE S256 and `nonce` on every request.
 * The OpenID Foundation conformance suite sends requests without them in some test
 * modules, so the conformance environment may relax the two flags. Never relax them
 * in production.
 */
return [

    /*
     * Issuer identifier (OIDC Core §2, Discovery §3): an https URL without a trailing
     * slash. It must stay stable: clients key their user identities on (iss, sub).
     */
    'issuer' => rtrim((string) env('OIDC_ISSUER', env('APP_URL', 'http://localhost')), '/'),

    /*
     * Lifetime of an id_token in seconds. Clients validate it once, at login.
     */
    'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', 600),

    /*
     * Require PKCE with S256 for every client, confidential ones included (RFC 9700 §2.1.1).
     */
    'require_pkce' => (bool) env('OIDC_REQUIRE_PKCE', true),

    /*
     * Require `nonce` on every `openid` authorization request.
     */
    'require_nonce' => (bool) env('OIDC_REQUIRE_NONCE', true),

    /*
     * How long a rotated signing key stays in the JWKS, in hours, so id_tokens signed
     * just before a rotation still validate.
     */
    'signing_key_overlap_hours' => (int) env('OIDC_SIGNING_KEY_OVERLAP_HOURS', 24),

    /*
     * RSA modulus size of new signing keys.
     */
    'signing_key_bits' => 3072,

    /*
     * Lifetime of a back-channel `logout_token` in seconds (OIDC Back-Channel Logout §2.4).
     */
    'logout_token_ttl' => (int) env('OIDC_LOGOUT_TOKEN_TTL', 120),

    /*
     * HTTP timeout when POSTing a logout_token to a client's back-channel URI.
     */
    'backchannel_logout_timeout' => (int) env('OIDC_BACKCHANNEL_LOGOUT_TIMEOUT', 5),

    /*
     * Queue attempts for each back-channel logout delivery.
     */
    'backchannel_logout_tries' => (int) env('OIDC_BACKCHANNEL_LOGOUT_TRIES', 3),

    /*
     * Named rate limiters (S6). Values are attempts per minute; tests may lower them.
     */
    'rate_limits' => [
        'token_per_minute' => (int) env('OIDC_RATE_LIMIT_TOKEN', 60),
        'authorize_per_minute' => (int) env('OIDC_RATE_LIMIT_AUTHORIZE', 60),
        'end_session_per_minute' => (int) env('OIDC_RATE_LIMIT_END_SESSION', 30),
        'introspect_per_minute' => (int) env('OIDC_RATE_LIMIT_INTROSPECT', 60),
        'organization_invitations_per_minute' => (int) env('OIDC_RATE_LIMIT_ORG_INVITATIONS', 10),
        'password_reset_per_minute' => (int) env('OIDC_RATE_LIMIT_PASSWORD_RESET', 5),
    ],

    /**
     * How long an organisation invitation email link stays valid.
     */
    'organization_invitation_ttl_days' => (int) env('OIDC_ORG_INVITATION_TTL_DAYS', 7),

];
