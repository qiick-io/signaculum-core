# CLAUDE.md — Signaculum (`qiick-io/signaculum-core`)

Guidance for Claude Code in this repository. Read it fully before any change.

## Project

Signaculum is the optional, open-source (MIT), self-hosted **OpenID Connect identity provider** of the qiick suite. It gives qiick apps (Externa, Excubia App, …) single sign-on, and it is the identity layer of qiick Cloud (`app.qiick.io`). Every qiick app keeps working standalone with local login: Signaculum is one IdP among the standard ones (Keycloak, Zitadel, Entra ID, …).

- **Source of truth:** the accepted design `knowledge/projects/qiick/research/2026-11-05-signaculum-sso-design.md` (protocol profile, claims, scopes, roles, phases). Do not add scope it does not list.
- **Wire contract:** the client side is the generic package `qiick/laravel-oidc` (`qiick-io/laravel-oidc`). Discovery, token response, `id_token` claims, `orgs` claim, scopes and logout are a shared contract. Any change to it needs Luca's OK, and the client must follow.
- **Keep it small.** Luca prefers less code and fewer moving parts. No feature, package, service or abstraction the design or an issue does not ask for.
- Display name in the UI is "Signaculum", never "qiick Signaculum". The lockup `qiick | Signaculum` is the logo only.

## Protocol profile (v1)

- Authorization code flow only, **PKCE S256 required for every client** (also confidential). No implicit, hybrid or password grant. `state` is passed through; `nonce` is required for `openid` requests and echoed in the `id_token`.
- `id_token` signed **RS256** (`kid` in the header), keys published at `/.well-known/jwks.json`, rotation with overlap.
- Discovery at `/.well-known/openid-configuration`; issuer = `APP_URL` without a trailing slash.
- Scopes `openid profile email qiick.orgs`; claims `sub`, `email`, `email_verified`, `name`, `preferred_username`, `picture`, `locale`, `amr`, `acr`, `orgs` (`[{ id, slug, roles }]`, roles `owner` / `admin` / `member` / `billing`).
- Logout: RP-initiated (`end_session_endpoint`) and back-channel. No front-channel logout.
- Not in v1: SAML, SCIM, IdP-initiated login, dynamic client registration, social logins.

## Where the OIDC layer lives

- `app/Oidc`: league/Passport extension points — `OidcAuthCodeGrant` (PKCE S256 and nonce rules), `OidcAuthCodeRepository` (stores `nonce` / `auth_time` with the code), `IdTokenResponse` (adds `id_token`). Wired in `AppServiceProvider::configureOpenIdConnect()`.
- `app/Services`: `IdTokenService`, `OidcClaimsService` (claims shared by id_token and userinfo), `SigningKeyService` (active key, JWKS), `DiscoveryDocumentService`, `PendingAuthorizationService`.
- `routes/oidc.php`: discovery, JWKS, userinfo (stateless `api` group). Passport registers `/oauth/authorize` and `/oauth/token`.
- `config/oidc.php`: issuer, id_token TTL, PKCE/nonce strictness (relax only for the conformance suite), key overlap.

## Stack (Zero-Snowflake, aligned with `qiick-io/excubia-app`)

- PHP 8.4, Laravel 13, official Laravel React starter kit: Inertia 3, React 19, TypeScript, Tailwind CSS v4, Fortify auth, Wayfinder.
- Laravel Passport 13 for OAuth 2.0 (`league/oauth2-server`); the in-house OIDC layer lives in `app/Oidc` and signs with `lcobucci/jwt`.
- Node 24 (`.nvmrc`), Vite+ (`vp`). SQLite by default for self-hosting; database driver via `.env`.
- Dev: Pest 5 (+ pest-plugin-laravel), Pint (laravel preset), Larastan, `vp check` (lint and format).
- Local site and dev processes run through Laravel Herd (`https://signaculum-core.test`).

## Commands

```bash
composer test          # Pint --test, PHPStan, Pest
npm run lint && npm run types && npm run build
```

Run them before every push.

## UI (qiick app UI)

This app follows the shared qiick app UI: https://github.com/qiick-io/app-ui. **Read the app-ui [`AGENTS.md`](https://github.com/qiick-io/app-ui/blob/develop/AGENTS.md) and [`docs/`](https://github.com/qiick-io/app-ui/tree/develop/docs) before any UI change.**

- Never invent a layout, component, colour or component position. Install it from the `@qiick` registry (`npx shadcn add @qiick/<name>`); if it is not there yet, copy it from externa-core `develop`; if externa-core has no equivalent, stop and ask Luca.
- Intentional differences go in `app-ui.overrides.json` with a reason; anything else fails the drift check in CI.
- shadcn components and `lucide-react` icons only. Theme tokens only: no hex, no raw palette classes, no arbitrary font sizes. AI surfaces use the violet `ai` tokens; status colours carry status only.
- Control scale `sm` 24 / default 32 / `lg` 40 px; default everywhere.
- WCAG 2.2 AA in light, dark and high contrast.
- Brand preset: **Signaculum (rose, 348°)** once app-ui ships it; until then the shared base theme. Do not edit surface tokens.

## Code conventions ("Codice Perfetto")

- Simplest thing that works (YAGNI). Fewest files possible. Mark deliberate shortcuts with a `ponytail:` comment naming the ceiling and the upgrade path.
- `declare(strict_types=1);` in every PHP file you write.
- Only Eloquent models use bare nouns. Every other class ends with its role suffix: `*Controller`, `*Request`, `*Action` (one public `execute()`, for writes), `*Service` (reads, orchestration, integrations), `*Data` (readonly DTO), `*Enum`, `*Policy`, `*Job`, `*Command`, `*Resource`, `*Exception`.
- Leave Laravel scaffolding, starter-kit, Passport and `shadcn/ui` files with upstream names; exclude them in `tests/Arch/NamingArchitectureTest.php` with `->ignoring(...)`.
- Thin controllers: validate with a `*Request`, call an `*Action` / `*Service`, return Inertia or JSON. No `DB` facade in controllers. Models hold relations, casts and scopes only. Authorization in Policies.
- Full words only; booleans start with `is`, `has`, `can`, `should` (except framework contract methods and native React/DOM props such as `disabled`, `open`).
- Curly braces always; a blank line around control-flow blocks. Strict DRY.
- English PHPDoc / JSDoc on classes, components, hooks and non-trivial methods: why, array shapes, side effects, `@throws`, and the spec section for protocol rules.
- Frontend files are `kebab-case` on disk; components and types `PascalCase`; hooks and functions `camelCase`.
- No `dd`, `dump`, `var_dump`, `console.log` left behind, no `env()` outside `config/`.
- Migrations are forward-only: never edit or delete a merged migration; add a new one.
- Tests: a Pest test for every protocol rule; no real network (fake HTTP). Never delete or weaken a test.

## Security

- Passport's access-token keys (`storage/oauth-*.key`, `php artisan passport:keys`) and the id_token signing keys (table `signing_keys`, private keys encrypted with `APP_KEY`, `php artisan signaculum:rotate-signing-key`) are generated on the server and never committed.
- Redirect URIs match exactly (no prefix or wildcard matching).
- No telemetry.

## Git and PR workflow

One change → one branch → one PR into `develop`. Never commit directly to `develop` or `main`.

1. `git checkout develop && git pull --ff-only`
2. `git checkout -b <type>/<kebab-slug>` — types: feat, fix, refactor, chore, docs, test, perf, ci, build, security.
3. Conventional Commits in English (`feat(oidc): add discovery document`).
4. Run the quality gates, then `git push -u origin HEAD`.
5. `gh pr create --base develop --title "<conventional title>"`.
6. Merge only with Luca's explicit OK (squash).

The GitHub token has no `workflow` scope: changes under `.github/workflows/` go in their own commit, and Luca pushes them.

## Identity and security

- Commit and push only as **LucaGiardi20** (`git config user.name` = "Luca Giardi"). Check `gh api user -q .login` returns `LucaGiardi20` before any `gh` write.
- Never use `--admin`, `--no-verify`, force-push to shared branches, or bypass branch protection.
- The repo is **private** until Luca decides otherwise. Never change its visibility.
- Never commit `.env`, secrets, keys, tokens, personal phone numbers or email addresses. Only `.env.example`.

## Ask Luca first

- Releases: version bumps, tags, GitHub Releases, Docker/GHCR publishing, Packagist, `develop` → `main` merges.
- Any Composer or npm dependency the design does not name.
- Any change to the wire contract shared with `qiick/laravel-oidc`.
- Anything ambiguous, outside the design's scope, or needing secrets, accounts, paid services or repo settings.
