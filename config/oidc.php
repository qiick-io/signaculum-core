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

];
