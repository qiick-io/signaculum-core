# Signaculum OpenID Connect profile

Wire contract for Signaculum as an OpenID Connect provider and for clients such as `qiick/laravel-oidc`. Update this page in every PR that changes discovery, tokens, claims, scopes or logout.

Issuer = `APP_URL` / `OIDC_ISSUER` without a trailing slash (`config/oidc.php`).

## Endpoints

| Path                                | Methods                 | Notes                                                                                                                                                                            |
| ----------------------------------- | ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `/.well-known/openid-configuration` | `GET`                   | Discovery (`DiscoveryController`)                                                                                                                                                |
| `/.well-known/jwks.json`            | `GET`                   | Public signing keys (`JwksController`), `Cache-Control: public, max-age=300`                                                                                                     |
| `/oauth/authorize`                  | `GET`, `POST`           | Authorization request (GET or form-encoded POST, OIDC Core §3.1.2.1) + consent approve (`POST` with `auth_token`) / deny (`DELETE`); UI `resources/js/pages/oauth/authorize.tsx` |
| `/oauth/device`                     | `GET`                   | RFC 8628 user-code entry (`resources/js/pages/oauth/device/user-code.tsx`)                                                                                                       |
| `/oauth/device/code`                | `POST`                  | RFC 8628 device authorization endpoint (`device_authorization_endpoint`)                                                                                                         |
| `/oauth/device/authorize`           | `GET`, `POST`, `DELETE` | Device consent UI (`resources/js/pages/oauth/device/authorize.tsx`)                                                                                                              |
| `/oauth/token`                      | `POST`                  | Passport token endpoint; OIDC grant adds `id_token`                                                                                                                              |
| `/oauth/userinfo`                   | `GET`, `POST`           | Bearer access token; requires `openid` scope                                                                                                                                     |
| `/oauth/introspect`                 | `POST`                  | RFC 7662 introspection; confidential clients only (`client_secret_basic` / `_post`)                                                                                              |
| `/oauth/revoke`                     | `POST`                  | RFC 7009 token revocation (`revocation_endpoint`)                                                                                                                                |
| `/oauth/par`                        | `POST`                  | RFC 9126 Pushed Authorization Requests (`pushed_authorization_request_endpoint`); same client auth as the token endpoint                                                         |
| `/oauth/end-session`                | `GET`, `POST`           | RP-initiated logout (`end_session_endpoint`); confirmation when hint missing                                                                                                     |
| `/oauth/end-session/confirm`        | `POST`                  | User confirmation after the logout interstitial                                                                                                                                  |

Known limitation (`/oauth/authorize` form POST): session cookies use `SameSite=Lax`, so a cross-site RP POST carries no session cookie and the user is asked to sign in again.

## Discovery fields

Returned by `DiscoveryDocumentService` (cached one hour at the HTTP layer):

- `issuer`, `authorization_endpoint`, `device_authorization_endpoint`, `token_endpoint`, `userinfo_endpoint`, `introspection_endpoint`, `revocation_endpoint`, `pushed_authorization_request_endpoint`, `end_session_endpoint`, `jwks_uri`
- `introspection_endpoint_auth_methods_supported`: `client_secret_basic`, `client_secret_post` (no `none`)
- `scopes_supported`: `openid`, `profile`, `email`, `qiick.orgs`
- `response_types_supported`: `code`
- `response_modes_supported`: `query`
- `grant_types_supported`: `authorization_code`, `refresh_token`, `client_credentials`, `urn:ietf:params:oauth:grant-type:device_code`
- `subject_types_supported`: `public`
- `id_token_signing_alg_values_supported`: `RS256`
- `token_endpoint_auth_methods_supported`: `client_secret_basic`, `client_secret_post`, `none`
- `revocation_endpoint_auth_methods_supported`: same as the token endpoint
- `code_challenge_methods_supported`: `S256`
- `claims_supported`: `iss`, `aud`, `exp`, `iat`, `auth_time`, `nonce`, `azp`, `sid`, `amr`, `acr`, plus user claims below
- `acr_values_supported`: `urn:qiick:loa:pwd`, `urn:qiick:loa:mfa`
- `claims_parameter_supported` / `request_parameter_supported` / `request_uri_parameter_supported`: `false` (OIDC Request Objects are not implemented; PAR uses its own `request_uri` values — see below)
- `require_pushed_authorization_requests`: `false` (the server does not require PAR for every client; an application can require it for itself, see below)
- `backchannel_logout_supported`: `true`
- `backchannel_logout_session_supported`: `true`
- `frontchannel_logout_supported`: `true` (OIDC Front-Channel Logout 1.0 §4)
- `frontchannel_logout_session_supported`: `true`
- `authorization_response_iss_parameter_supported`: `true` (RFC 9207)

## Pushed Authorization Requests (RFC 9126)

`POST /oauth/par` (`application/x-www-form-urlencoded`) accepts the full authorization request (PKCE S256, `nonce` for `openid`, exact `redirect_uri`, scopes) with the same client authentication as `/oauth/token`. Validation happens **before** storage. Success is HTTP 201 with `request_uri` (`urn:ietf:params:oauth:request_uri:…`) and short `expires_in` (`OIDC_PAR_EXPIRES_IN`, default 60s). The URI is single-use and bound to the pushing `client_id`.

`/oauth/authorize` then accepts `client_id` + that `request_uri`; other authorize parameters are ignored (RFC 9126 §4). Mismatched client, expired or reused URIs → HTTP 400 `invalid_request`. A non-PAR `request_uri` (for example a remote JAR URL) still yields `request_uri_not_supported` (#113). The OIDC `request` parameter stays `request_not_supported`. Rate limit: same named limiter as the token endpoint (`oidc-token`).

**Requiring PAR per application (RFC 9126 §6 `require_pushed_authorization_requests`).** Admin → Applications → “Require pushed authorization requests (PAR)” (column `oauth_clients.is_pushed_authorization_required`, default off). For such an application, `/oauth/authorize` without a PAR `request_uri` is refused with `error=invalid_request` on the registered `redirect_uri` (with `state` and `iss`); an unregistered `redirect_uri` gets HTTP 401 and no redirect. RFC 9126 defines no dedicated error code: `invalid_request` is RFC 6749 §4.1.2.1's “missing a required parameter” (`request_uri`). Public applications may require PAR too: it keeps the parameters off the front channel, but a public push is not client-authenticated.

**Login in the middle of a pushed request.** The `request_uri` is spent on its first use. When that use sends the browser to log in (no session, `prompt=login`, `max_age`), the expanded request is kept in that browser's session and the intended URL is `/oauth/authorize?client_id=…&request_uri=…`, so the pushed parameters never appear in the browser and only the session that spent the URI can finish it, also after `expires_in` has passed. `prompt` and `max_age` from the push are honoured like those of a plain request.

## Authorization response `iss` (RFC 9207)

Every redirect back to the client's `redirect_uri` — success (`code`) and error (`error`) — includes `iss` equal to discovery's `issuer` (no trailing slash). Internal redirects (for example guest → `/login`) and refusals that never leave Signaculum (unknown client, unregistered `redirect_uri` → HTTP 401) do not. Clients that read `authorization_response_iss_parameter_supported: true` must reject a callback without matching `iss` (mix-up defense).

## PKCE and nonce

- Interactive SSO uses the authorization code flow. Device authorization (RFC 8628) is available for opted-in clients (`/oauth/device`, `/oauth/device/code`).
- **PKCE S256 required for every client** (confidential included) when `OIDC_REQUIRE_PKCE` is true (default). `plain` is refused. Missing challenge → `invalid_request` redirect with `state`.
- **`nonce` required** on every `openid` authorization request when `OIDC_REQUIRE_NONCE` is true (default). Echoed in the `id_token`. Missing nonce → `invalid_request` redirect with `state`.
- Both flags may be relaxed only for the OpenID Foundation conformance suite, never in production. CI job and local steps: [`conformance/README.md`](../conformance/README.md); workflow `.github/workflows/oidc-conformance.yml` (own commit).

## Client credentials (machine clients)

OAuth 2.0 client credentials (`grant_type=client_credentials`) is for **machine-to-machine** callers — jobs, MCP agents, service-to-service APIs — that need an access token **without a browser or end-user**.

| Prefer…                     | When…                                                                                                                           |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Authorization code (+ PKCE) | A person signs in (SSO into Externa, Excubia, …). Issues `id_token` / refresh; userinfo and `orgs` apply.                       |
| Client credentials          | No human is present. Access token only; **no** `id_token`, **no** refresh token, **no** userinfo / `sid` / back-channel logout. |

Opt-in per confidential application (Admin → Applications → “Allow client credentials”). Public clients cannot enable it. Passport already registers the grant; Signaculum only adds `client_credentials` to that client's `grant_types`. Dynamic registration stays out of scope.

## Device authorization (RFC 8628)

Opt-in per application (Admin → Applications → “Allow device authorization”). The client calls `POST /oauth/device/code`, shows `user_code` / `verification_uri` to the person, who signs in at `/oauth/device` and approves. The client then polls `POST /oauth/token` with `grant_type=urn:ietf:params:oauth:grant-type:device_code`. Passport owns the grant and storage (`oauth_device_codes`); Signaculum supplies the Inertia verification UI, discovery fields, and an `id_token` when `openid` was requested.

Device `id_token` rules (OIDC Core §2 / §3.1.3.6; RFC 8628 has no authorization-request `nonce`):

- Emits `auth_time`, `amr` and `sid` from the **approving browser session** (same sources as the authorization-code grant). Never invents or reuses stale values from another flow.
- Omits `nonce` (none exists on the device authorization request).
- Token polling errors follow RFC 8628 §3.5: `authorization_pending`, `slow_down`, `expired_token`, `access_denied`.
- User codes are 8 letters from a 20-letter alphabet (about 34.6 bits, RFC 8628 §6.1). User-code entry (`/oauth/device`, `/oauth/device/authorize`) and `/oauth/device/code` share the `oidc-device` limiter, per IP and, when signed in, per account (§5.1).
- The token endpoint returns `slow_down` when a pending code is polled faster than `interval` (5 s), `expired_token` after the 10-minute lifetime, `access_denied` after a denial, and refuses a code presented by another client. A code yields tokens once; the first approval or denial is final.
- Device approval uses the same web gates as `/oauth/authorize` (deactivated user, organisation MFA enrollment). The organisation MFA gate runs again on the approving `POST`; a refused approval leaves the code pending and sends the user to enroll, then back to code entry. Denying is never gated.

## `prompt` and `max_age`

- **`prompt`**: `none` returns `login_required` / `consent_required` without interaction; `login` forces a fresh Signaculum credential check; `consent` always shows the consent screen. Space-separated values are accepted; `none` wins over the others.
- **`max_age`** (OIDC Core §3.1.2.1, `EnforceMaximumAuthenticationAgeMiddleware`): when the elapsed seconds since session `auth_time` exceed `max_age` (or `auth_time` is unknown), the user must re-authenticate. With `prompt=none` the client receives `login_required` (with `state` and `iss`) **without** ending the Signaculum session. Otherwise the browser is sent to login with the authorize URL as `url.intended`. When `max_age` was sent, the `id_token` always includes `auth_time`.
- **Re-authentication ≠ SSO logout.** `max_age` and `prompt=login` clear only the local web login (`oidc.reauthenticating`): no back-channel fan-out, same `sid`. A real Signaculum logout still ends SSO sessions.

## Claims per scope

User claims come from `OidcClaimsService` (same values in `id_token` and userinfo). Empty optional claims are omitted, never sent as empty strings.

| Scope        | Claims                                                                                                                                                        |
| ------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| _(always)_   | `sub` (opaque UUIDv7 on `users.subject`, not the DB id)                                                                                                       |
| `profile`    | `name`, `given_name` (`users.first_name`), `family_name` (when `users.last_name` is set), `locale` (BCP 47, when set), `updated_at` (Unix timestamp when set) |
| `email`      | `email`, `email_verified`                                                                                                                                     |
| `qiick.orgs` | `orgs`: `[{ id, slug, roles }]` (roles `owner` / `admin` / `member` / `billing`; empty list when the user has no memberships; sorted by `slug`)               |

**Id-token-only** (from `IdTokenService`, not scope-gated): `iss`, `aud`, `azp`, `iat`, `exp` (TTL `OIDC_ID_TOKEN_TTL`, default 600s), optional `nonce`, `auth_time` when known (and **always** when the authorization request carried `max_age`), **`sid`** (per-browser session id, stable within the Laravel session, regenerated at login), **`amr`** (always present; RFC 8176), optional **`acr`** (only when requested).

#### `amr` (RFC 8176)

Always emitted on the `id_token` from methods recorded at login (`AuthenticationContextService`, session key `oidc.authentication_methods`, copied onto the auth code):

| Value | When                                                                                                                 |
| ----- | -------------------------------------------------------------------------------------------------------------------- |
| `pwd` | Password authentication (also the fallback when a signed-in session has no recorded method)                          |
| `otp` | Fortify TOTP / recovery-code challenge, or passwordless email one-time code (instance setting; never alone as `mfa`) |
| `hwk` | Passkey whose credential transports include a hardware channel (`usb` / `nfc` / `ble` / `smart-card`)                |
| `swk` | Passkey without a hardware transport (platform / hybrid)                                                             |
| `mfa` | Two or more of the concrete factors above were used in the same authentication                                       |

A remember-me **recaller** login (session restored from the cookie, `Auth::viaRemember()`) does **not** record a fresh `pwd`: the user did not type a password on that request. Interactive password login with the "remember me" checkbox still records `pwd` as usual. When the recaller leaves the session without recorded methods, `amr` falls back to `pwd` as for any other unsigned method list.

#### Organisation MFA requirement

An organisation owner/admin may set **Members must use two-factor authentication** (`organizations.is_mfa_required`). Before `/oauth/authorize` or an RFC 8628 device approval can complete, a member of such an organisation must:

1. **Enrol** TOTP or a passkey (otherwise redirect to Settings → Security, intended URL preserved).
2. **Prove a second factor in this session's `amr`** (RFC 8176): `mfa`, or a passkey method (`hwk` / `swk`). Email-code-only (`otp`) or password-only (`pwd`) sessions are stepped up like `max_age` / `prompt=login` (local re-auth, same `sid`, no back-channel fan-out; login toast explains that a second factor is required), then resume authorization.

Both the consent screen and the approving request are checked, so a requirement switched on while a consent screen is open still applies. Denying is never gated.

With `prompt=none` (OIDC Core §3.1.2.1 / §3.1.2.6), an unsatisfied organisation MFA requirement returns `interaction_required` (with `state` and `iss`) **without** ending the Signaculum session or showing UI. The device flow has no `prompt` parameter, so a device approval always gets the interactive path: the code is left pending (the device keeps polling `authorization_pending`) and the user goes to enrolment or step-up and then back to `/oauth/device` code entry.

Passkey-only sign-in satisfies the org gate via `hwk`/`swk` without inventing `mfa` in `amr`. `acr` stays as decided elsewhere (`urn:qiick:loa:mfa` only when `amr` includes `mfa`). Instance-wide `two_factor_required` remains a separate setting.

#### `acr` (OIDC Core §2 / §5.1)

Emitted **only** when the authorization request includes a non-empty `acr_values` parameter or a `claims` JSON object that asks for `id_token.acr`. Discovery lists `acr_values_supported`. Values (Decision 2026-11-13):

| Value               | When                                               |
| ------------------- | -------------------------------------------------- |
| `urn:qiick:loa:pwd` | Password-only authentication (`amr` without `mfa`) |
| `urn:qiick:loa:mfa` | MFA satisfied (`amr` includes `mfa`)               |

Person names follow OIDC Core 5.1 (approved 2026-11-06). Clients with `first_name` / `last_name` columns use `given_name` / `family_name` when present (`PersonNameData` in `qiick/laravel-oidc`).

### Still planned (not on this branch)

- `preferred_username`, `picture`

## Token lifetimes

| Token           | Config / env                                        | Default          | Notes                                                                                             |
| --------------- | --------------------------------------------------- | ---------------- | ------------------------------------------------------------------------------------------------- |
| Access token    | `oidc.access_token_ttl` / `OIDC_ACCESS_TOKEN_TTL`   | `PT1H` (1 hour)  | ISO 8601 duration → Passport `tokensExpireIn`; token response `expires_in` matches (3600).        |
| Refresh token   | `oidc.refresh_token_ttl` / `OIDC_REFRESH_TOKEN_TTL` | `P30D` (30 days) | Sliding on each successful refresh; Passport rotates the refresh token (reuse → `invalid_grant`). |
| Personal access | Passport default                                    | unchanged        | Signaculum does not issue PATs; `personalAccessTokensExpireIn` is not set.                        |

RFC 9700 §2.2.1 / §2.2.2: short-lived access tokens, refresh rotation with reuse detection.

## Token response

Authorization code exchange (Passport + `IdTokenResponse`):

- Always: `token_type`, `expires_in` (seconds from `OIDC_ACCESS_TOKEN_TTL`, default 3600), `access_token`, `refresh_token` (when issued)
- `id_token` when the grant included `openid` (JWT, `alg=RS256`, `typ=JWT`, `kid` of the active signing key)
- Refresh-token grants do **not** mint a new `id_token`; they return a new refresh token (rotation) and the same access-token `expires_in`
- Redeeming a code records a `client_sessions` row for `(user, client, sid)` used later by back-channel logout

Client credentials exchange:

- Always: `token_type`, `expires_in`, `access_token`
- Never: `id_token`, `refresh_token`

## Token introspection (RFC 7662)

`POST /oauth/introspect` with `application/x-www-form-urlencoded`:

- **Caller:** confidential client only (`client_secret_basic` or `client_secret_post`). Public clients and bad secrets → HTTP 401 `invalid_client`
- **Body:** `token` (required), optional `token_type_hint` (`access_token` / `refresh_token`)
- **Active token:** JSON with `active: true` plus `scope`, `client_id`, `username` (email), `token_type` (`Bearer` or `refresh_token`), `exp`, `iat` (access tokens), `sub` (OIDC subject UUID), `aud` (access tokens), `iss` — only when the token was issued to the calling client (`client_id` match). Another client's token is treated as inactive (RFC 7662 §2.2 / §4).
- **Inactive / unknown / revoked / expired / foreign client:** `{ "active": false }` only (no reason leaked)
- Out of scope for this endpoint: JWT access-token migration, DPoP

## Token revocation (RFC 7009)

`POST /oauth/revoke` (`application/x-www-form-urlencoded`):

- Required: `token` (access or refresh token string)
- Optional: `token_type_hint` = `access_token` | `refresh_token`
- Client auth: `client_secret_basic`, `client_secret_post`, or `client_id` alone for public clients (`none`)
- Success and unknown/foreign tokens: HTTP 200 with an empty JSON array body (`[]`) and `Cache-Control: no-store` (no probing oracle)
- Invalid client credentials: HTTP 401 `invalid_client`
- Revoking either half of a grant also revokes the paired access and refresh tokens

## Logout

### Client registration fields

On `oauth_clients` (beside Passport's columns):

- `post_logout_redirect_uris` (JSON array, default `[]`): exact-match only
- `backchannel_logout_uri` (nullable string)
- `is_backchannel_logout_session_required` (boolean, default `true`): when true, the `logout_token` always carries `sid`
- `frontchannel_logout_uri` (nullable string): absolute https URL (http only for localhost); exact value; no fragment
- `is_frontchannel_logout_session_required` (boolean, default `false`): when true, the iframe URL includes `iss` and `sid` (Front-Channel Logout 1.0 §2–§3)

### RP-initiated logout

`GET|POST /oauth/end-session`:

- `id_token_hint`: signature + `iss` + `aud` validated against published signing keys; **expiry is ignored**
- `client_id`: optional when the hint has a single audience; required to validate `post_logout_redirect_uri`
- `post_logout_redirect_uri`: must match a registered URI exactly; otherwise HTTP 400 (no open redirect)
- `state`: echoed on the post-logout redirect when present
- Confirmation page (`oauth/logout`) when the hint is missing, forged, or belongs to another user than the signed-in one
- Ends the Signaculum web session and fans out back-channel logout
- When any client for the browser `sid` has `frontchannel_logout_uri`, renders `oauth/frontchannel-logout` with one hidden iframe per such client, then continues to the post-logout redirect (or login). CSP `frame-src` allows those RP origins on that page only.

### Front-channel logout

OIDC Front-Channel Logout 1.0: the OP logout interstitial loads each registered `frontchannel_logout_uri` in a hidden iframe. When `is_frontchannel_logout_session_required` is true, both `iss` and `sid` are appended (existing query retained). Triggers: RP-initiated end-session / confirm, and Signaculum web logout in the End-User browser. Admin deactivation of another user remains back-channel only (no User Agent of the target).

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

| Situation                                                                                                                                    | Behaviour                                                                                  |
| -------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| PKCE / nonce / consent deny / `prompt=none` without session / `max_age` exceeded with `prompt=none` / org MFA unsatisfied with `prompt=none` | Redirect to the registered `redirect_uri` with `error`, echoed `state`, and RFC 9207 `iss` |
| `request` parameter present (OIDC Core §6.1 / §3.1.2.6)                                                                                      | Redirect with `error=request_not_supported`, `state`, `iss`; never a `code`                |
| Non-PAR `request_uri` (OIDC Core §6.1 / §3.1.2.6)                                                                                            | Redirect with `error=request_uri_not_supported`, `state`, `iss`; never a `code`            |
| PAR `request_uri` expired, reused or bound to another `client_id` (RFC 9126 §4)                                                              | HTTP 400 `invalid_request` (no open redirect)                                              |
| PAR push with bad client auth / invalid authorization parameters (RFC 9126 §2.3)                                                             | JSON OAuth error (`invalid_client`, `invalid_request`, `invalid_scope`, …)                 |
| `max_age` exceeded (no `prompt=none`) or `prompt=login`                                                                                      | Redirect to Signaculum `/login` (then back to authorize)                                   |
| Unknown, revoked or unregistered `redirect_uri`                                                                                              | HTTP 401 (no redirect to an attacker URI)                                                  |
| Bad token request (verifier, client secret, …)                                                                                               | JSON OAuth error (`invalid_grant`, `invalid_request`, `invalid_client`, …)                 |
| Revocation with a wrong client secret                                                                                                        | HTTP 401 `invalid_client`                                                                  |
| Userinfo without / with invalid Bearer                                                                                                       | 401 `invalid_token` + `WWW-Authenticate`                                                   |
| Userinfo without `openid` scope                                                                                                              | 403 `insufficient_scope`                                                                   |
| Introspection without / with bad client auth                                                                                                 | 401 `invalid_client`                                                                       |
| Introspection missing `token`                                                                                                                | 400 `invalid_request`                                                                      |
| Unregistered `post_logout_redirect_uri`                                                                                                      | HTTP 400                                                                                   |
| Forged / foreign `id_token_hint` while signed in                                                                                             | Confirmation page, then logout of the local session on confirm                             |

Redirect URIs and post-logout redirect URIs match **exactly** (no prefix or wildcard matching).
