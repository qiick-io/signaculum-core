# Signaculum OpenID Connect profile

Wire contract for Signaculum as an OpenID Connect provider and for clients such as `qiick/laravel-oidc`. Update this page in every PR that changes discovery, tokens, claims, scopes or logout.

Issuer = `APP_URL` / `OIDC_ISSUER` without a trailing slash (`config/oidc.php`).

## Endpoints

| Path                                | Methods       | Notes                                                                          |
| ----------------------------------- | ------------- | ------------------------------------------------------------------------------ |
| `/.well-known/openid-configuration` | `GET`         | Discovery (`DiscoveryController`)                                              |
| `/.well-known/jwks.json`            | `GET`         | Public signing keys (`JwksController`), `Cache-Control: public, max-age=300`   |
| `/oauth/authorize`                  | `GET`, `POST` | Passport authorization + consent UI (`resources/js/pages/oauth/authorize.tsx`) |
| `/oauth/token`                      | `POST`        | Passport token endpoint; OIDC grant adds `id_token`                            |
| `/oauth/userinfo`                   | `GET`, `POST` | Bearer access token; requires `openid` scope                                   |
| `/oauth/end-session`                | `GET`, `POST` | RP-initiated logout (`end_session_endpoint`); confirmation when hint missing   |
| `/oauth/end-session/confirm`        | `POST`        | User confirmation after the logout interstitial                                |

## Discovery fields

Returned by `DiscoveryDocumentService` (cached one hour at the HTTP layer):

- `issuer`, `authorization_endpoint`, `token_endpoint`, `userinfo_endpoint`, `end_session_endpoint`, `jwks_uri`
- `scopes_supported`: `openid`, `profile`, `email` (enum cases today; `qiick.orgs` is planned, S5)
- `response_types_supported`: `code`
- `response_modes_supported`: `query`
- `grant_types_supported`: `authorization_code`, `refresh_token`
- `subject_types_supported`: `public`
- `id_token_signing_alg_values_supported`: `RS256`
- `token_endpoint_auth_methods_supported`: `client_secret_basic`, `client_secret_post`, `none`
- `code_challenge_methods_supported`: `S256`
- `claims_supported`: `iss`, `aud`, `exp`, `iat`, `auth_time`, `nonce`, `azp`, `sid`, `amr`, plus user claims below
- `claims_parameter_supported` / `request_parameter_supported` / `request_uri_parameter_supported`: `false`
- `backchannel_logout_supported`: `true`
- `backchannel_logout_session_supported`: `true`
- No front-channel logout fields

## PKCE and nonce

- Authorization code flow only. Device code grant is disabled.
- **PKCE S256 required for every client** (confidential included) when `OIDC_REQUIRE_PKCE` is true (default). `plain` is refused. Missing challenge → `invalid_request` redirect with `state`.
- **`nonce` required** on every `openid` authorization request when `OIDC_REQUIRE_NONCE` is true (default). Echoed in the `id_token`. Missing nonce → `invalid_request` redirect with `state`.
- Both flags may be relaxed only for the OpenID Foundation conformance suite, never in production.

## Claims per scope

User claims come from `OidcClaimsService` (same values in `id_token` and userinfo). Empty optional claims are omitted, never sent as empty strings.

| Scope      | Claims                                                                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| _(always)_ | `sub` (opaque UUIDv7 on `users.subject`, not the DB id)                                                                                                       |
| `profile`  | `name`, `given_name` (`users.first_name`), `family_name` (when `users.last_name` is set), `locale` (BCP 47, when set), `updated_at` (Unix timestamp when set) |
| `email`    | `email`, `email_verified`                                                                                                                                     |

**Id-token-only** (from `IdTokenService`, not scope-gated): `iss`, `aud`, `azp`, `iat`, `exp` (TTL `OIDC_ID_TOKEN_TTL`, default 600s), optional `nonce`, optional `auth_time`, **`sid`** (per-browser session id, stable within the Laravel session, regenerated at login), **`amr`** (RFC 8176 array when authentication methods were recorded for the session).

**`amr` values** (always when known; recorded at login and stored with the auth code):

| Value | When |
| ----- | ---- |
| `pwd` | Password verified (password-only login, or before TOTP challenge) |
| `otp` | Fortify TOTP / recovery code challenge succeeded |
| `swk` | Passkey verified (software / platform distinction not yet mapped; `hwk` reserved) |
| `mfa` | Two or more primary factors in the same authentication (`pwd`+`otp`, …) |

Person names follow OIDC Core 5.1 (approved 2026-11-06). Clients with `first_name` / `last_name` columns use `given_name` / `family_name` when present (`PersonNameData` in `qiick/laravel-oidc`).

### Still planned (not on this branch)

- `preferred_username`, `picture`, `acr` (emit only when requested — #69)
- Scope `qiick.orgs` with `orgs`: `[{ id, slug, roles }]` and roles `owner` / `admin` / `member` / `billing` (S5)

## Token response

Authorization code exchange (Passport + `IdTokenResponse`):

- Always: `token_type`, `expires_in`, `access_token`, `refresh_token` (when issued)
- `id_token` when the grant included `openid` (JWT, `alg=RS256`, `typ=JWT`, `kid` of the active signing key)
- Refresh-token grants do **not** mint a new `id_token`
- Redeeming a code records a `client_sessions` row for `(user, client, sid)` used later by back-channel logout

## Logout

### Client registration fields

On `oauth_clients` (beside Passport's columns):

- `post_logout_redirect_uris` (JSON array, default `[]`): exact-match only
- `backchannel_logout_uri` (nullable string)
- `is_backchannel_logout_session_required` (boolean, default `true`): when true, the `logout_token` always carries `sid`

### RP-initiated logout

`GET|POST /oauth/end-session`:

- `id_token_hint`: signature + `iss` + `aud` validated against published signing keys; **expiry is ignored**
- `client_id`: optional when the hint has a single audience; required to validate `post_logout_redirect_uri`
- `post_logout_redirect_uri`: must match a registered URI exactly; otherwise HTTP 400 (no open redirect)
- `state`: echoed on the post-logout redirect when present
- Confirmation page (`oauth/logout`) when the hint is missing, forged, or belongs to another user than the signed-in one
- Ends the Signaculum web session and fans out back-channel logout

### Back-channel logout

Queued `SendBackChannelLogoutJob` (retries/backoff) POSTs `application/x-www-form-urlencoded` with `logout_token` to each client's URI.

`logout_token` (OIDC Back-Channel Logout 1.0 §2.4):

- Header: `alg=RS256`, `typ=logout+jwt`, `kid`
- Claims: `iss`, `aud` = client_id, `iat`, `exp` (`OIDC_LOGOUT_TOKEN_TTL`, default 120s), unique `jti`, `events` = `{"http://schemas.openid.net/event/backchannel-logout": {}}`, `sid` and/or `sub`, **never** `nonce`
- Triggers: Signaculum logout, end-session, sign out of other browsers, single-browser sign-out (that `sid` only), remove access for a client
- One failing client never blocks the others (separate jobs)

## Signing keys / JWKS

- RS256, RSA 3072-bit keys, `kid` = RFC 7638 JWK thumbprint
- Private key encrypted at rest (`signing_keys` table)
- Rotation: `php artisan signaculum:rotate-signing-key`; retired keys stay in JWKS for `OIDC_SIGNING_KEY_OVERLAP_HOURS` (default 24)

## Errors

| Situation                                                   | Behaviour                                                                  |
| ----------------------------------------------------------- | -------------------------------------------------------------------------- |
| PKCE / nonce / consent deny / `prompt=none` without session | Redirect to the registered `redirect_uri` with `error` and echoed `state`  |
| Unknown client or unregistered `redirect_uri`               | HTTP 401 (no redirect to an attacker URI)                                  |
| Bad token request (verifier, client secret, …)              | JSON OAuth error (`invalid_grant`, `invalid_request`, `invalid_client`, …) |
| Userinfo without / with invalid Bearer                      | 401 `invalid_token` + `WWW-Authenticate`                                   |
| Userinfo without `openid` scope                             | 403 `insufficient_scope`                                                   |
| Unregistered `post_logout_redirect_uri`                     | HTTP 400                                                                   |
| Forged / foreign `id_token_hint` while signed in            | Confirmation page, then logout of the local session on confirm             |

Redirect URIs and post-logout redirect URIs match **exactly** (no prefix or wildcard matching).
