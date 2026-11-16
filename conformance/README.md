# OpenID Foundation conformance (S7)

Self-hosted [OpenID Foundation conformance suite](https://gitlab.com/openid/conformance-suite) against a local Signaculum instance. CI runs Basic OP, RP-Initiated Logout and Back-Channel Logout. Production defaults stay strict: `OIDC_REQUIRE_PKCE` / `OIDC_REQUIRE_NONCE` are relaxed **only** in this job.

## Plans

| Plan | Variant |
| --- | --- |
| `oidcc-basic-certification-test-plan` | `[server_metadata=discovery][client_registration=static_client]` |
| `oidcc-rp-initiated-logout-certification-test-plan` | `[response_type=code][client_registration=static_client]` |
| `oidcc-backchannel-rp-initiated-logout-certification-test-plan` | `[response_type=code][client_registration=static_client]` |

Hybrid, implicit, front-channel and session-management plans stay out of scope (v1 is authorization code only; no front-channel logout).

## Local run

### Running locally (Docker-only)

On hosts that cannot edit `/etc/hosts`, install host Java/Maven, or bind Signaculum on the host, use the Compose overlay `docker-compose.local.yml` together with the suite’s `docker-compose-localtest.yml` and `docker-compose.ci.yml`:

- Build the suite JAR **inside** a Maven container with a **named** volume for `~/.m2` (never the host home directory).
- Run Signaculum in a PHP container on the same Compose network (`dunglas/frankenphp:1-php8.4-alpine` or similar); `signaculum-proxy.local.conf` points at that service.
- Run `browser-runner.py` and `scripts/run-test-plan.py` in a `python:3.12-slim` service on that network so `nginx`, `signaculum-proxy` and `chrome` resolve via Compose DNS.
- Trust the suite TLS certificate **inside** those containers only. Never change the host trust store.

The browser runner drives Inertia login/consent over HTTP (headless Chromium is unreliable on those forms), confirms RP-initiated logout from the Inertia `data-page` payload, and uploads PNG screenshots to suite REVIEW placeholders (`/api/log/{id}/images/{upload}`).

### Classic host run

Requirements: Docker Compose, PHP 8.4, Composer, Node 24, Java 21 (suite build), Python 3 with `httpx` and `selenium`.

1. Install and build the app (`composer setup`, or `composer install` + `npm ci` + `npm run build`).
2. Copy `.env.example` → `.env`, then set at least:

   ```env
   APP_URL=https://signaculum-proxy:8443
   OIDC_ISSUER=https://signaculum-proxy:8443
   OIDC_REQUIRE_PKCE=false
   OIDC_REQUIRE_NONCE=false
   TRUSTED_PROXIES=*
   DB_CONNECTION=sqlite
   ```

3. Migrate, install Passport keys, seed the conformance user and clients:

   ```bash
   php artisan migrate --force
   php artisan passport:keys --force
   php conformance/seed.php
   ```

4. Serve Signaculum on port 8080 (`php artisan serve --host=0.0.0.0 --port=8080`).
5. Clone the suite (pin the same tag as `.github/workflows/oidc-conformance.yml`), build the JAR (`mvn -B -DskipTests package`), and start MongoDB, nginx, server, Chrome and the Signaculum TLS proxy:

   ```bash
   docker compose \
     -f docker-compose-localtest.yml \
     -f ../signaculum-core/conformance/docker-compose.ci.yml \
     up -d mongodb nginx server chrome signaculum-proxy
   ```

6. Render plan configs and run the browser worker + `scripts/run-test-plan.py` (see the workflow for the exact invocations). Export zips land under `conformance-results/`; `conformance/check-results.py` fails only on blocking outcomes.

Never relax PKCE/nonce outside this environment. Record failing modules on the PR for the maintainer; do not change required checks from this work.
