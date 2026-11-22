# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Renamed the PAR storage table from `oauth_pushed_authorization_requests` to `signaculum_pushed_authorization_requests` so the standard name stays free for `qiick/laravel-mcp-oauth` (#238). **Recreate beta.1 databases with `php artisan migrate:fresh --seed`** (the `create_` migration was edited in place). Spent and expired PAR rows are pruned daily via `model:prune`.
- Spatie `permission.column_names.team_foreign_key` is `organisation_id` while `teams` stays `false` (decision 2026-11-22). With teams off the Spatie team column is not created yet; recreate beta.1 databases with `php artisan migrate:fresh --seed` so config (and any atypical `permission.testing` schema) stays aligned.
- Principals are one per organisation membership (`organisation_id` required) with a CHECK that allows `NULL` only for the singleton `instance_service` Admin API owner (#229). **Recreate beta.1 databases with `php artisan migrate:fresh --seed`**. Admin API keys belong to that service principal; they stay valid while any active instance admin remains.

## [1.0.0-beta.1] - 2026-11-22

This is a **beta** of Signaculum, the self-hosted OpenID Connect IdP for the qiick suite (Passport 13 + in-house OIDC layer, admin UI, organisations, distribution). Wire contracts may still change before 1.0.0; pin clients to this tag and read [docs/oidc-profile.md](./docs/oidc-profile.md).

**Upgrading from any earlier development build requires recreating the database.** Pre-stable schema changes are folded into the `create_` migrations (`knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release`); there is no forward upgrade path. Run `php artisan migrate:fresh --seed` (or reinstall). The migrations freeze applies only at the first stable `v1.0.0` — not at this beta.

### Upgrade notes

- **Recreate the database for this beta.** Pre-stable schema changes are folded into the `create_` migrations (decision 2026-11-20); there is no forward upgrade path from an earlier beta database. Run `php artisan migrate:fresh --seed` (or reinstall). After the first stable `v1.0.0`, migrations stay forward-only.
- **Access token `expires_in` is now 3600 (1 hour), not ~31536000 (1 year).** Relying parties that assumed Passport's previous one-year default must refresh more often. Override with `OIDC_ACCESS_TOKEN_TTL` / `OIDC_REFRESH_TOKEN_TTL` (ISO 8601 durations, defaults `PT1H` / `P30D`). Refresh tokens rotate on use; replaying a used refresh token returns `invalid_grant`.
- **Initial super-admin outside `local` / `testing`.** `CreateSuperAdminSeeder` creates the admin only when `INITIAL_SUPER_ADMIN_PASSWORD` is set, is not the known default `password`, and passes the password policy; otherwise it skips. Set a strong password in the environment (or use `/setup`) before relying on `db:seed` in staging/production.
- **Migrate does not create roles.** A plain `php artisan migrate` (including `RUN_MIGRATIONS=true` in Docker) leaves Spatie roles empty until `signaculum:sync-permissions` runs. The Docker entrypoint now runs sync-permissions after migrate; Compose/manual installs that only migrate must run it themselves (or finish with `signaculum:install` / `signaculum:upgrade`).
- **Soft-deleted emails stay taken** until the erasure job (#220 / C17). Do not expect to re-register the same address after self-deletion in this beta.
- **Self-deletion revokes credentials.** Profile destroy deactivates the account (Passport tokens and sessions cleared, principal deactivated), revokes the user's API keys, then soft-deletes. A refresh token issued before deletion returns `invalid_grant`.
- **LDAP-linked accounts cannot self-delete.** The directory owns their lifecycle: the profile delete control is hidden, `DELETE /settings/profile` returns 403, and LDAP sync never restores a soft-deleted user (decision 2026-11-22).
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

### Added

- Spatie roles and permissions (`teams => false`), user groups, optional `users.username`, OIDC `preferred_username`, and the standard initial super-admin seeder / `signaculum:create-user` before beta (#206). Organisation roles stay JSON on memberships (declared divergence).
- Phase 1 fleet conformance suite (app-ui v0.12.0) with PostgreSQL/MySQL CI (#213).
- RFC 8628 device authorization grant for applications that opt in, with an `id_token` from the approving session, user-code entry rate limited per IP and per account, a final first decision per device code, single-use exchange, and the organisation MFA gate on approval (#64).
- RFC 9126 Pushed Authorization Requests at `POST /oauth/par` (`pushed_authorization_request_endpoint`), with discovery `require_pushed_authorization_requests: false`; `/oauth/authorize` accepts the issued single-use `request_uri`; an application can be set to require PAR (RFC 9126 §6), and its plain authorization requests are then refused with `invalid_request` (#62).
- LDAP / Active Directory read-only user sync: instance settings storage (TLS required by default, encrypted bind password), `SyncLdapUsersJob`, `signaculum:ldap-sync`, connection test action, and `docs/ldap.md` (#77). Settings UI is #78.

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

- Pre-stable `add_` / `alter_` / `drop_` migrations from the fleet schema week (timezone, activity `organisation_id`, scoped settings, principals / `owner_principal_id`, username, nullable LDAP password, and the `is_instance_admin` → Spatie role path) are folded into the `create_` migrations (#211). Fresh installs get the final shape in one pass; existing beta databases must be recreated.
- Instance settings use `scope_id = ''` as the instance sentinel (never NULL). Future-dated `api_keys.expires_at` / `previous_key_valid_until` are `dateTime`. The Laravel `notifications` table ships in `create_`. Soft deletes and `last_login_*` land on `users` in `create_` (#213).
- Admin REST `/api/v1/users/{user}` is addressed by the public `subject` UUID; `UserResource.id` is that UUID (never the bigint). The separate `uuid` field is removed from the user payload. Activity `properties.api_key_id` stores the key's public UUID (#213).
- `api_keys.owner_id` uses `restrictOnDelete`; `principals.user_id` uses `nullOnDelete` (#213).

- Standards cleanup: `declare(strict_types=1)`, English docblocks, UI token ratchet and related craftsmanship fixes across the tree (#117).
- PHPStan baseline for Settings controllers shrinks further (#118).
- Remaining hard-coded auth and security UI strings moved into en/it/de locale files (#130).

### Fixed

- Request input that targets a uuid column (`users.subject`, `oauth_clients.id`, webhook/api-key route keys) is format-checked before the query, so a non-UUID yields 422 or 404 on PostgreSQL instead of SQLSTATE 22P02 / 500. The webhook settings list fixture now inserts real UUIDs for `event_id` (#197).
- The Admin REST API carries every application field the admin form does. `POST /api/v1/applications` accepts `is_device_authorization_enabled` and both application resources return it, so an application created through the API can use the device grant (RFC 8628) instead of silently having it off; the API refuses a non-boolean value as the form does. Both doors now build `ApplicationData` through one named constructor (`ApplicationData::fromValidated()`), which refuses to build a payload whose property it does not carry, and a test walks the payload's properties by reflection and asserts each is accepted on create and returned by the API resource — so a field added for one door cannot reach it alone. `OrganizationData` is built the same way, and the API organisation resource now reports `is_mfa_required` (#186).
- `signaculum:install` checks for and generates the Passport keys at `Passport::keyPath()`, so a key directory set with `Passport::loadKeysFrom()` is honoured; its tests use their own key directory instead of deleting the shared pair, which made the parallel suite flaky (#144).
- Organisation MFA now requires the current session `amr` to prove a second factor (`mfa`, or passkey `hwk`/`swk`); enrolled users with email-code-only or password-only sessions are stepped up like `max_age` / `prompt=login` (#131).
- Organisation MFA with `prompt=none` returns `interaction_required` (keeps the session); interactive step-up flashes a second-factor toast on login (#131).
- Webhook `CURLOPT_RESOLVE` pins wrap IPv6 addresses in brackets (`host:443:[2001:db8::1]`) (#131).

- Authorization endpoint accepts `application/x-www-form-urlencoded` POST (OIDC Core §3.1.2.1) in addition to GET (#114).
- Unsupported OIDC Request Object parameters `request` / `request_uri` are refused with `request_not_supported` / `request_uri_not_supported` (plus `state` and RFC 9207 `iss`); never a `code` (#113).
- Remember-me recaller login no longer records a spurious `pwd` authentication method for `amr` (only interactive password login does) (#96).
- RFC 9207 `iss` middleware scoped to authorize routes so unrelated redirects are untouched (#92).

### Security

- Adding a member to an organisation that already requires MFA (UI, Admin API, or invitation acceptance) revokes that user's access tokens, refresh tokens and unredeemed authorization codes, and queues back-channel logout for their open OIDC sessions. Joining an organisation without MFA changes nothing (#163).
- A local password change (Security settings, password reset, or admin/API-set password) revokes the user's OAuth grants and ends browser sessions (current browser kept only on self-service change). LDAP-sourced accounts have no local password path (#163).
- Access tokens default to 1 hour and refresh tokens to 30 days (`OIDC_ACCESS_TOKEN_TTL` / `OIDC_REFRESH_TOKEN_TTL`), with Passport refresh-token rotation (RFC 9700). Personal access token lifetime is untouched (#163).
- The webhook SSRF guard blocks Alibaba's `100.100.100.200` and Oracle's `192.0.0.192` metadata addresses even with `webhooks_allow_private_urls` on. Both were covered by the private-range blocklist only while the setting was off; they are now in the metadata list, including their alternate IPv4 spellings and IPv4-in-IPv6 forms (#190).
- The organisation MFA gate also covers the RFC 8628 device flow: code entry, the device consent screen and the approving `POST` are gated by route name, so a requirement switched on while a device consent screen is open still applies. A refused device approval leaves the code pending (the device keeps polling `authorization_pending`) and sends the user to enrolment or step-up and then back to code entry; denying a device code is never gated (#64).
- The webhook SSRF guard pins every delivery to an address it checked. An IPv4 address spelt other than in dotted decimal (`2852039166`, `0251.0376.0251.0376`, `0xa9.0xfe.0xa9.0xfe`, `169.254.43518`, `169.254.169.254.`) is the EC2 metadata service to curl but not an IP to `filter_var()`, so the guard treated it as a hostname: with `webhooks_allow_private_urls` on it was authorised with no pin, and with it off refused only because the lookup of the string failed. The guard now reads such a host as curl does, refuses it (`Host is not allowed.` for the metadata address, `Invalid URL.` otherwise), and no longer lets any request out unpinned: a hostname that resolves to nothing is refused whatever the setting, a literal is pinned to itself, a hostname resolving to a metadata address is refused even with the setting on, and a trailing dot no longer slips a name such as `metadata.google.internal.` or `localhost.` past the name checks. The pin moves from `CURLOPT_RESOLVE`, which curl ignores for any host it parses as an address, to `CURLOPT_CONNECT_TO` with an empty source host, which applies however the host is spelt (#185).
- The webhook SSRF guard recognises an IPv6 destination. `parse_url()` returns an IPv6 host in URL bracket form (`[fd00:ec2::254]`) and `filter_var()` rejects that, so neither the address blocklist nor the metadata list was ever reached for an IPv6 URL: with `webhooks_allow_private_urls` on, the EC2 IPv6 metadata endpoint saved and delivered, and with it off a loopback or link-local literal was refused only because the DNS lookup of the bracketed string failed. The guard now unwraps and canonicalises the literal before every check, reduces an IPv4-in-IPv6 literal (`::ffff:169.254.169.254`, `::169.254.169.254`) to the IPv4 it carries so the metadata list and the IPv4 ranges apply, and refuses a bracketed host that is not a valid IPv6 literal (a zone id such as `[fe80::1%25en0]`) instead of passing it to the resolver as a name (#181).
- The organisation MFA gate now also checks the consent approval, not only the consent screen: a member whose organisation started requiring MFA after the screen opened could still approve and the client received an authorization code. The gate matches route names instead of the `oauth/authorize` path, so Passport's route prefix cannot move a route out of it, and denying consent stays allowed (extracted from #110).
- Every second-factor change (TOTP enable, confirm and disable, recovery-code regeneration, passkey registration and deletion, the admin TOTP reset) mails the account owner and writes an `auth` activity event. A password alone can still enrol a factor; this makes it visible to the owner until factor management requires a proven second factor (#168).
- Organisation MFA gate no longer accepts enrolment alone; session `amr` must contain a second factor before `/oauth/authorize` completes (#131).
- Session `amr` is bound to the user who proved it, on write and on every read: a passkey verified for an account whose login is then refused no longer counts for the next account that signs in, and methods bound to another (or no) subject never reach the `id_token` or the organisation MFA gate (#131).
- Organisation MFA and the instance-wide two-factor requirement no longer switch off when Fortify's TOTP feature is disabled: passkeys still satisfy them, a factor counts only while its method is enabled (a TOTP-only user is sent to enrol a passkey instead of a step-up that cannot challenge TOTP), and with no second-factor method enabled the user gets a 403 page that says why (`access_denied` for `prompt=none`). Settings → Authentication & security warns the instance admin and lists the affected organisations. **Behaviour change:** instances that disabled TOTP now enforce these requirements (#169).
- Turning on an organisation's MFA requirement revokes its members' access tokens, refresh tokens and unredeemed authorization codes, and logs `organization_member_tokens_revoked`; the settings page says so next to the switch. Before, a refresh token issued without a second factor kept minting access tokens for a year (#163).
- Turning on an organisation's MFA requirement also ends members' open OIDC sessions and queues OIDC Back-Channel Logout 1.0 to every client with a back-channel URI (logged as `organization_member_backchannel_logout_dispatched`); the acting owner's Signaculum browser session stays intact. Apps without a back-channel URI keep their own session until it expires (#174).

- Optional “reject breached passwords” sends only a SHA-1 **prefix** to Have I Been Pwned’s k-anonymity API when enabled; off by default; no other credential telemetry (#124).
- Webhook delivery URLs are SSRF-validated (private / loopback / link-local refused unless explicitly allowed for local QA; cloud metadata hosts always blocked); DNS rebinding mitigated with `CURLOPT_RESOLVE` (#127).
- API keys are shown once, stored as SHA-256 hashes with a short UI prefix, and require an active instance-admin owner (#128).

[Unreleased]: https://github.com/qiick-io/signaculum-core/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/qiick-io/signaculum-core/releases/tag/v1.0.0-beta.1
