# Beta readiness: product checklist (proposal)

A written checklist for deciding when a qiick **product** (a self-hosted app, not a Composer package) is ready for a beta and for its first stable release. It is proposed here, on Signaculum, so the other products (`instituta-core`, `excubia-app`, later `externa-core`) can reuse it unchanged: only the status column belongs to Signaculum.

It combines four sources:

- the items of the qiick **packages 1.0 checklist** (knowledge: `projects/qiick/packages/packages-1-0-checklist-and-status.md`) that also apply to a product: scope, tests, security, contract, supply chain, documentation;
- the qiick **standards for products**: distribution (`.qiick/standards/distribution.md`), automated checks (`.qiick/standards/checks.md`), the fleet conformance suite and the app-ui drift check;
- the product's own **milestone** and approved scope (for Signaculum: `v1.0.0`, approved in #134, and the 2026-11-18 decision that unreleased products build the full programme);
- the **adoption of the shared qiick packages**, with what the product must change to adopt each one.

## How to read it

- **Gate** says when an item must hold:
    - **Beta**: before a pre-release (`vX.Y.Z-beta.N`) that people outside the team are told to install;
    - **Stable**: before the first stable tag (`vX.Y.Z`), after which migrations are frozen.
- **Status** is Signaculum's state on `develop` at `fa535da` (2026-11-24): ✅ holds, 🟡 partly, ❌ missing, ➖ not applicable. Each status names its evidence (issue, file or check).
- A release PR links the evidence for every Beta item (for a beta) or every item (for a stable release).

Signaculum is public, has one pre-release (`v1.0.0-beta.1`, 2026-11-22) and no stable tag.

## 1. Scope and tests

| #    | Item                                                                                                                               | Gate   | Signaculum                                                                                                                                                                                              |
| ---- | ---------------------------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1.1  | The milestone, the design documents and the README describe the same scope; a superseded document is updated in a PR that says so. | Beta   | ❌ #177 is open; `docs/COMPETITIVE-PARITY.md` still describes the pre-2026-11-18 positioning; the epic #210 checklist is stale (CI on three engines and beta.1 are done but unticked)                   |
| 1.2  | Every milestone item is implemented, or moved to a later milestone with a dated decision.                                          | Stable | ❌ open in `v1.0.0`: SAML IdP (#55, #73, #74), SCIM (#56, #75, #76), upstream IdP broker, presets and account linking (#57, #70, #71, #72), LDAP admin UI (#78, umbrella #60), Prometheus metrics (#68) |
| 1.3  | Every protocol and security rule has a test that fails when the rule is removed (red first).                                       | Beta   | ✅ repository rule, applied in every protocol PR (PKCE, nonce, redirect URI, token families, logout)                                                                                                    |
| 1.4  | The suite runs on SQLite, PostgreSQL, MySQL and MariaDB in CI.                                                                     | Beta   | ✅ checks `ci`, `ci-pgsql`, `ci-mysql`, `ci-mariadb`                                                                                                                                                    |
| 1.5  | Concurrency tests run truly in parallel wherever the design relies on atomicity (claims, locks, token families).                   | Beta   | ✅ `tests/Concurrency` (token family races, deadlock retry) on the three server engines                                                                                                                 |
| 1.6  | Line coverage has a committed floor that only rises.                                                                               | Beta   | ✅ `coverage-floor.txt` = 93.6                                                                                                                                                                          |
| 1.7  | Where a public protocol test suite exists, the product passes it with 0 failures.                                                  | Beta   | ✅ OIDF conformance (`oidc-conformance.yml`), 0 failures on the recent security PRs                                                                                                                     |
| 1.8  | The fleet conformance suite is green; every divergence has a reason, a decision, a `review_by` date and an issue.                  | Beta   | 🟡 green with 8 divergences: C3 (permanent, the IdP has no `user_identities`), C17 erasure (#220, review by 2026-12-20), C23–C25 (#221), C27 (#222), C29 (#223), C34 (#218)                             |
| 1.9  | No divergence is left except permanent ones, and every behaviour hook of the conformance suite is wired.                           | Stable | ❌ seven temporary divergences; #224 (hooks C13, C19, C20, C22, C23, C26, C31, C32)                                                                                                                     |
| 1.10 | UI flows that only a browser proves (navigation, dialogs, keyboard, focus) have browser tests, in light and dark.                  | Stable | ❌ no `tests/Browser` suite; UI changes are checked by hand                                                                                                                                             |

## 2. Security

| #   | Item                                                                                                                            | Gate   | Signaculum                                                                                                                                                                              |
| --- | ------------------------------------------------------------------------------------------------------------------------------- | ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2.1 | Every PR that touches authentication, tokens or a trust boundary has an adversarial review by a reviewer that did not write it. | Beta   | ✅ the token family, logout and admin URI PRs of November had one                                                                                                                       |
| 2.2 | An adversarial review of the **whole product** at the release-candidate commit; every finding fixed or in a dated decision.     | Stable | ❌ not done yet                                                                                                                                                                         |
| 2.3 | No open issue labelled `bug` or `security`.                                                                                     | Beta   | ❌ open bugs: #164 (step-up of a form POST authorization request loses it), #176 (`InstallCommandTest` rewrites the shared `.env`); also #205 (duplicate instance settings), unlabelled |
| 2.4 | `composer audit` and `npm audit` are clean; no open Dependabot or secret-scanning alert.                                        | Beta   | ✅ `composer audit` clean, 0 Dependabot and 0 secret-scanning alerts (2026-11-24); `npm audit --omit=dev` clean                                                                         |
| 2.5 | Static analysis for security (CodeQL or equivalent) runs on PRs.                                                                | Stable | ❌ no code-scanning analysis on the repository                                                                                                                                          |
| 2.6 | A threat model (assets, trust boundaries, attacker capabilities, what is out of scope) is written and matches the code.         | Stable | ❌ neither `README.md` nor `SECURITY.md` has one; `docs/oidc-profile.md` covers the protocol rules only                                                                                 |
| 2.7 | Open security decisions waiting on the maintainer are answered or explicitly deferred.                                          | Beta   | 🟡 #154 (which AI scopes the IdP may hold) is `needs-decision`                                                                                                                          |

## 3. Contract and schema

| #   | Item                                                                                                                      | Gate   | Signaculum                                                                                                                                                                                       |
| --- | ------------------------------------------------------------------------------------------------------------------------- | ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 3.1 | Every wire contract is written down: protocol profile, REST API (OpenAPI), webhooks, and what a client library relies on. | Beta   | ✅ `docs/oidc-profile.md`, `docs/admin-api.md` with `/api/v1/openapi.json`, `docs/webhooks.md`; `qiick/laravel-oidc` runs interop tests against Signaculum                                       |
| 3.2 | The fleet schema decisions are applied in the `create_` migrations while the product is unreleased.                       | Beta   | ✅ #289: `principals.organisation_id` restrict with organisation offboarding; `api_keys.owner_principal_id` restrict NOT NULL; C1 soft deletes and `last_login` on users already in place (#217) |
| 3.3 | One `create_` migration per table: the `add_` / `alter_` migrations of the betas are folded in.                           | Stable | ❌ 18 `add_` migrations on `develop` (#218, C34)                                                                                                                                                 |
| 3.4 | Personal-data erasure works with unique tombstones (GDPR erasure without breaking unique keys).                           | Stable | ❌ #220 (C17)                                                                                                                                                                                    |
| 3.5 | After the first stable tag, migrations are forward-only; `migrations-frozen` guards it.                                   | Stable | ✅ the reusable `migrations-frozen.yml` runs                                                                                                                                                     |
| 3.6 | Every version has upgrade notes; a beta that needs a fresh database says so.                                              | Beta   | ✅ `CHANGELOG.md` (Keep a Changelog, compare links) and `docs/upgrade.md`                                                                                                                        |

## 4. Supply chain

| #   | Item                                                                                                  | Gate   | Signaculum                                                              |
| --- | ----------------------------------------------------------------------------------------------------- | ------ | ----------------------------------------------------------------------- |
| 4.1 | Rulesets: release tags created by admins only and immutable; `main` takes merge commits only.         | Beta   | ✅ the three rulesets are on the repository                             |
| 4.2 | `develop` and `main` change only through PRs with green required checks.                              | Beta   | ✅ branch protection, required checks set by the maintainer             |
| 4.3 | Dependabot runs weekly grouped updates and security updates.                                          | Beta   | ✅ `.github/dependabot.yml`                                             |
| 4.4 | `minimum-stability: stable`, no `repositories` entries; reusable workflows and actions pinned by SHA. | Beta   | ✅ `composer.json`; `qiick.yml` pins `qiick-io/app-ui` workflows by SHA |
| 4.5 | Release tags are signed.                                                                              | Stable | ❌ waits on the maintainer's signing key                                |

## 5. Distribution (self-hosted products only)

| #   | Item                                                                                                                               | Gate | Signaculum                                                                                     |
| --- | ---------------------------------------------------------------------------------------------------------------------------------- | ---- | ---------------------------------------------------------------------------------------------- |
| 5.1 | `composer create-project qiick/<app>` works from Packagist.                                                                        | Beta | ❌ `qiick/signaculum-core` is not on Packagist (404 on 2026-11-24)                             |
| 5.2 | `php artisan <app>:install` (interactive and `--no-interaction --force`) and `<app>:upgrade`.                                      | Beta | ✅ `signaculum:install`, `signaculum:upgrade` (with `--sync-upstream`)                         |
| 5.3 | The CI smoke test: `create-project`, non-interactive install, health endpoints, upgrade from the previous tag.                     | Beta | ✅ check `distribution-smoke / smoke`                                                          |
| 5.4 | A release workflow publishes `ghcr.io/qiick-io/<repo>` for linux/amd64 and arm64 on a `v*` tag (`X.Y.Z-beta.N` for a pre-release). | Beta | ❌ `Dockerfile` and `docker-bake.hcl` exist, but no release workflow publishes an image        |
| 5.5 | Docs: installation (Composer, Docker), configuration, upgrade, backup.                                                             | Beta | ✅ `docs/install.md`, `docs/upgrade.md`, `docs/key-rotation-and-backup.md`                     |
| 5.6 | Health endpoints for orchestration.                                                                                                | Beta | ✅ `/health/live`, `/health/ready`                                                             |
| 5.7 | Repository visibility and the agent rules agree.                                                                                   | Beta | ❌ the repository is public, but `AGENTS.md` still says "private until the maintainer decides" |

## 6. UI

| #   | Item                                                                                          | Gate   | Signaculum                                                           |
| --- | --------------------------------------------------------------------------------------------- | ------ | -------------------------------------------------------------------- |
| 6.1 | The app-ui drift check is green against a pinned app-ui release; every override has a reason. | Beta   | ✅ `app-ui-drift / drift` and `/ standards`                          |
| 6.2 | Every UI string in `en`, `it` and `de`; the hard-coded string ratchet only shrinks.           | Beta   | ✅ `npm run lint:i18n`                                               |
| 6.3 | The product's theme preset is in use.                                                         | Stable | 🟡 shared base theme until app-ui ships the Signaculum (rose) preset |

## 7. Shared qiick packages

The fleet rule is one implementation per concern. An unreleased product adopts the shared packages in beta, and it counts as the "second product" a package needs before its own `1.0.0` (packages checklist item 12). The order below follows the knowledge note `projects/qiick/package-adoption-plan-2026-11.md`.

| Package                     | Gate   | Status  | What Signaculum changes to adopt it                                                                                                                                                                                                                                                                                                                                                                                                  |
| --------------------------- | ------ | ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `qiick/laravel-outbound`    | Beta   | ❌ #222 | Replace `WebhookUrlGuardService` and the signing in `DeliverWebhookJob` with the package; webhook payloads move to the Standard Webhooks envelope `{type, timestamp, data}` and headers (C27). Relying parties that verify webhooks change their verification, so it is a beta-only change and goes in the CHANGELOG.                                                                                                                |
| `qiick/laravel-access`      | Beta   | ❌ #223 | Delete `create_user_groups_tables` and use the package's group tables (`public_id`, `metadata`, `expires_at`); the `principals` migration moves into the package, with a restrict organisation FK; turn Spatie teams on with `team_foreign_key = organisation_id` (C29); `Gate::before` stops granting API actors, and `SuperAdminGuard` applies. Super-admin-owned provisioning keys lose access to super-admin targets (accepted). |
| `qiick/laravel-api-keys`    | Beta   | ❌      | Replace `AuthenticateApiKeyMiddleware`, `ApiKeySecretService` and the rotate and record actions; `api_keys.owner_principal_id` becomes restrict and NOT NULL in the `create_` migration, after backfilling or revoking keys without an owner.                                                                                                                                                                                        |
| `qiick/laravel-http-basics` | Beta   | ❌ #221 | Replace `SetSecurityHeaders` and `SetLocale`; throttle headers, `X-Request-Id` and `Idempotency-Key` come from the package (C23–C25); the package's error shape for the Admin API.                                                                                                                                                                                                                                                   |
| `qiick/laravel-audit`       | Stable | ❌      | `ActivityLogService` moves to the package; retention 365 days, 730 days for the `auth` log.                                                                                                                                                                                                                                                                                                                                          |
| `qiick/laravel-settings`    | Stable | ❌      | Instance settings move to the package's natural key `(scope, scope_id, group, key)`, which also closes #205.                                                                                                                                                                                                                                                                                                                         |
| `qiick/laravel-oidc`        | Stable | ➖ / ❌ | Signaculum is the identity provider, not a client; it keeps the interop tests green. The upstream IdP broker (#70) is built on `qiick/laravel-oidc`, as approved in #134.                                                                                                                                                                                                                                                            |
| `qiick/laravel-mcp-oauth`   | ➖     | ➖      | Not applicable: Signaculum has no `/mcp` endpoint; its OAuth server is the identity provider itself.                                                                                                                                                                                                                                                                                                                                 |

None of these packages is at `1.0.0` yet. Each adoption is a separate PR with its own review, and a schema change that a package brings goes into the `create_` migration while Signaculum is unreleased.

## 8. What is missing for the next Signaculum beta, by priority

**P0: blocks a beta that people outside the team can install**

1. **Packagist and the Docker image** (5.1, 5.4). Proposal: a PR adding the release workflow (multi-arch `ghcr.io/qiick-io/signaculum-core`, pre-release tags only for betas), copied from `externa-core`; the maintainer registers the package on Packagist.
2. **Open bugs** (2.3): #176 first, because it destroys contributors' worktrees; then #164 and #205.
3. **Schema decisions in the `create_` migrations** (3.2, 3.3): the restrict FK on `principals.organisation_id`, `api_keys.owner_principal_id` restrict NOT NULL, #217 (soft deletes, `last_login`), and folding the 18 `add_` migrations (#218). Proposal: one schema PR before the next beta, so beta users drop their database once rather than at every adoption.
4. **Documents that disagree** (1.1, 5.7): close #177 by updating `docs/COMPETITIVE-PARITY.md` to the full-programme positioning; update `AGENTS.md` for the public repository; tick or close the epic #210.

**P1: should be in the beta, because they change wire formats or schema**

5. **Package adoption** in the order of section 7: outbound (C27, changes webhook payloads), access (groups, principals, teams), api-keys, http-basics. Proposal: adopt each at its current beta and report adoption problems to the package, not around it; Signaculum then counts as the second product for those packages' `1.0.0`.
6. **#154** decided, or deferred with a date (2.7).

**P2: before the first stable tag, not the beta**

7. The open `v1.0.0` features (1.2): SAML, SCIM, the upstream broker and presets, account linking, the LDAP admin UI, metrics; or each moved with a dated decision.
8. Erasure with tombstones (#220, review by 2026-12-20), the conformance hooks (#224), browser tests in light and dark, CodeQL, the threat model, a whole-product adversarial review at the release candidate, signed tags.
