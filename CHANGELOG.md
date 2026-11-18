# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `signaculum:install` checks for and generates the Passport keys at `Passport::keyPath()`, so a key directory set with `Passport::loadKeysFrom()` is honoured; its tests use their own key directory instead of deleting the shared pair, which made the parallel suite flaky (#144).
- Organisation MFA now requires the current session `amr` to prove a second factor (`mfa`, or passkey `hwk`/`swk`); enrolled users with email-code-only or password-only sessions are stepped up like `max_age` / `prompt=login` (#131).
- Organisation MFA with `prompt=none` returns `interaction_required` (keeps the session); interactive step-up flashes a second-factor toast on login (#131).
- Webhook `CURLOPT_RESOLVE` pins wrap IPv6 addresses in brackets (`host:443:[2001:db8::1]`) (#131).

### Security

- Organisation MFA gate no longer accepts enrolment alone; session `amr` must contain a second factor before `/oauth/authorize` completes (#131).
- Session `amr` is bound to the user who proved it, on write and on every read: a passkey verified for an account whose login is then refused no longer counts for the next account that signs in, and methods bound to another (or no) subject never reach the `id_token` or the organisation MFA gate (#131).
- Organisation MFA and the instance-wide two-factor requirement no longer switch off when Fortify's TOTP feature is disabled: passkeys still satisfy them, a factor counts only while its method is enabled (a TOTP-only user is sent to enrol a passkey instead of a step-up that cannot challenge TOTP), and with no second-factor method enabled the user gets a 403 page that says why (`access_denied` for `prompt=none`). Settings → Authentication & security warns the instance admin and lists the affected organisations. **Behaviour change:** instances that disabled TOTP now enforce these requirements (#169).
- Turning on an organisation's MFA requirement revokes its members' access tokens, refresh tokens and unredeemed authorization codes, and logs `organization_member_tokens_revoked`; the settings page says so next to the switch. Before, a refresh token issued without a second factor kept minting access tokens for a year (#163).

## [1.0.0-beta.1] - 2026-11-16

First tagged beta of Signaculum: self-hosted OpenID Connect IdP for the qiick suite (Passport 13 + in-house OIDC layer, admin UI, organisations, distribution).

### Added

- OpenID Foundation conformance suite in CI (Basic OP, RP-Initiated Logout, Back-Channel Logout): `conformance/` helpers and `.github/workflows/oidc-conformance.yml`; relaxes `OIDC_REQUIRE_PKCE` / `OIDC_REQUIRE_NONCE` only in that job (#107).
- Admin REST API under `/api/v1` with hashed Bearer API keys, scopes, hand-written OpenAPI at `/api/v1/openapi.json`, and the same `*Action` write path as the UI (#128).
- Outbound signed webhooks for domain events (Settings → Webhooks), queued delivery with retries, and SSRF validation of delivery URLs (#127).
- Optional passwordless sign-in with a six-digit email code (off by default): queued mail, editable template, hashed at rest, rate limited, lockout-aware; `amr` = `otp` only (#126).
- Per-organisation MFA requirement: owners/admins can require members to enroll TOTP or a passkey before finishing OAuth authorization; `amr` / `acr` stay truthful; audited in the activity log (#125).
- Granular instance password policy (minimum length, mixed case / numbers / symbols) and an optional Have I Been Pwned check via `Password::uncompromised()` — off by default; UI and `docs/password-policy.md` document the k-anonymity hash prefix (#124).
- Per-organisation branding (logo / colours) for login and consent screens (#109).
- Time-boxed user impersonation for instance admins, with audit events and an expire / stop banner (#108).
- Instance-admin editable email templates (verification, password reset, organisation invitation) with en/it/de defaults, preview and fallback to packaged copy; email-login code template ships with passwordless sign-in (#104, #126).
- RFC 7662 token introspection at `POST /oauth/introspect` (confidential clients), advertised as `introspection_endpoint` in discovery; inactive / revoked / expired tokens return `{ "active": false }` (#102).
- OAuth 2.0 `client_credentials` for confidential applications that opt in (Admin → Applications): machine access tokens without `id_token`; discovery `grant_types_supported` includes `client_credentials` (#103).
- RFC 7009 token revocation at `/oauth/revoke` (`revocation_endpoint`), with the same client auth methods as the token endpoint (#101).
- OIDC scope `qiick.orgs` and claim `orgs: [{ id, slug, roles }]` on `id_token` and userinfo (consent copy; discovery `scopes_supported` / `claims_supported`) (#100).
- `acr` on the `id_token` only when requested (`acr_values` or `claims`): `urn:qiick:loa:pwd` or `urn:qiick:loa:mfa`, with discovery `acr_values_supported` (#93).
- Account data export (JSON) from Settings → Profile: profile, organisations, sessions and connected apps (never passwords or 2FA secrets) (#98).
- Organisation invitations (email, hashed token, expiry, resend/revoke) and Members / Invitations / Settings view tabs.
- OIDC `max_age` / `prompt=login` on `/oauth/authorize`: re-authenticate when session `auth_time` is too old (or `prompt=login`), without back-channel fan-out and keeping the same `sid`; `prompt=none` + stale `max_age` returns `login_required` with `iss` and leaves the session intact; always emit `auth_time` in the `id_token` when `max_age` was sent (#86).
- Brute-force login lockout: configurable failure threshold and duration, activity log on lock, admin unlock on Users.
- Organisations and memberships (`organizations`, `organization_user`): multi-role JSON (`owner` / `admin` / `member` / `billing`), never zero owners, CRUD UI under Organisations.
- RFC 9207 `iss` on every authorization response (success and error) and discovery flag `authorization_response_iss_parameter_supported: true` (#84).
- `amr` on every `id_token` (RFC 8176): recorded at login as `pwd` / `otp` / `hwk` / `swk`, plus `mfa` when two factors were used, and carried with the authorization code (#85).
- RP-initiated logout (`end_session_endpoint`) and back-channel logout (`logout_token`, per-client fan-out); `sid` on the `id_token` (#36).
- Named rate limiters for `/oauth/token`, `/oauth/authorize`, end-session, introspection, password-reset email, email-login code, and organisation invitations (#90).
- Admin → Users: deactivate/reactivate (login refused, tokens revoked, back-channel logout), reset two-factor, grant/revoke instance admin (never the last one).
- Admin → Applications: list and drawer (redirect URIs, post-logout URIs, back-channel URI, confidential/public, first-party, client credentials opt-in); secret shown once; regenerate and delete with confirmation.
- Activity log (`spatie/laravel-activitylog`): admin page, auth/consent/revoke/settings events, and `activity_retention_days` with a daily prune.
- Instance setting `registration_enabled` (default off): self-registration and the login "Sign up" link stay closed until an instance admin turns them on (Settings → Authentication & security).
- Instance settings (general, localization, authentication, appearance), account area (sessions, connected apps), security headers and branded error pages.
- Passport 13 with discovery, JWKS, PKCE S256, nonce, RS256 `id_token` and userinfo (#2).
- `signaculum:install` and `signaculum:upgrade` for a self-hosted tree.
- `GET /health/live` and `GET /health/ready`.
- Multi-stage `Dockerfile`, `docker-bake.hcl` and a Compose quick start.
- Operator docs: install, upgrade, clients, organisations, key rotation and backup, admin API, webhooks, password policy, OIDC profile, competitive parity; README release readiness; required-checks list for branch protection (#35).

### Changed

- Standards cleanup: `declare(strict_types=1)`, English docblocks, UI token ratchet and related craftsmanship fixes across the tree (#117).
- PHPStan baseline for Settings controllers shrinks further (#118).
- Remaining hard-coded auth and security UI strings moved into en/it/de locale files (#130).

### Fixed

- Authorization endpoint accepts `application/x-www-form-urlencoded` POST (OIDC Core §3.1.2.1) in addition to GET (#114).
- Unsupported OIDC Request Object parameters `request` / `request_uri` are refused with `request_not_supported` / `request_uri_not_supported` (plus `state` and RFC 9207 `iss`); never a `code` (#113).
- Remember-me recaller login no longer records a spurious `pwd` authentication method for `amr` (only interactive password login does) (#96).
- RFC 9207 `iss` middleware scoped to authorize routes so unrelated redirects are untouched (#92).

### Security

- Optional “reject breached passwords” sends only a SHA-1 **prefix** to Have I Been Pwned’s k-anonymity API when enabled; off by default; no other credential telemetry (#124).
- Webhook delivery URLs are SSRF-validated (private / loopback / link-local refused unless explicitly allowed for local QA; cloud metadata hosts always blocked); DNS rebinding mitigated with `CURLOPT_RESOLVE` (#127).
- API keys are shown once, stored as SHA-256 hashes with a short UI prefix, and require an active instance-admin owner (#128).

### Upgrade notes

- **First tag.** There is no previous SemVer upgrade path. Fresh installs: `composer create-project qiick/signaculum-core` then `php artisan signaculum:install`.
- `composer create-project` does **not** migrate. Always finish with `signaculum:install` (or `php artisan migrate --force` on a tree that was already installed from `develop` before this layout).
- Later updates: put the new code in place, install dependencies (`composer install`, `npm ci`, `npm run build`), then `php artisan signaculum:upgrade`. Do **not** run `create-project` again. Optional: `signaculum:upgrade --sync-upstream=v1.0.0-beta.1` (try `--dry-run` first).
- New migrations: `email_login_codes`, `organizations.is_mfa_required`, `webhooks` / `webhook_deliveries`, `api_keys` (plus earlier OIDC / org / activity tables). Run `php artisan signaculum:upgrade` (or `migrate --force`).
- Enable email-code login under Settings → Authentication & security when needed.
- Authentication & security settings replace the `weak` / `medium` / `strong` password presets with granular fields. Re-save that page after upgrade if you relied on a stored preset; the former `strong` preset’s HIBP check is no longer implied — enable “Reject breached passwords” explicitly if you want it.
- New dependency: `spatie/laravel-activitylog`. Run `composer update` / `composer install` and migrate so the `activity_log` table exists.
- Run a **queue worker** for mail, outbound webhooks and back-channel logout.
- Back up `APP_KEY`, `storage/oauth-*.key` and the database (including `signing_keys`) before moving hosts — see [docs/key-rotation-and-backup.md](./docs/key-rotation-and-backup.md).
- Beta: wire contracts may still change before 1.0.0; pin clients to this tag and read [docs/oidc-profile.md](./docs/oidc-profile.md).

[Unreleased]: https://github.com/qiick-io/signaculum-core/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/qiick-io/signaculum-core/releases/tag/v1.0.0-beta.1
