# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-beta.1] - 2026-11-16

First tagged beta of Signaculum: self-hosted OpenID Connect IdP for the qiick suite (Passport 13 + in-house OIDC layer, admin UI, organisations, distribution).

### Added

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
- Known follow-up: organisation MFA checks enrolment only; session `amr` step-up is [#131](https://github.com/qiick-io/signaculum-core/issues/131).

[Unreleased]: https://github.com/qiick-io/signaculum-core/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/qiick-io/signaculum-core/releases/tag/v1.0.0-beta.1
