# Install

How to bring up a new Signaculum instance. Updates use [Upgrade](./upgrade.md) — never run `composer create-project` again on an existing tree.

## Requirements

| Requirement | Notes |
| --- | --- |
| **PHP 8.4+** | Pinned in `.php-version` / `composer.json` (`^8.4`). |
| **Composer 2** | PHP dependencies. |
| **Node.js 24** | Pinned in `.nvmrc` and `package.json` `engines`. |
| **Database** | **SQLite** (default for install, tests and CI); **MySQL 8** or **PostgreSQL 17** for production if you prefer. |
| **HTTPS** | Required in production for cookies, passkeys and OIDC redirects. Local: [Laravel Herd](https://herd.laravel.com) with `herd secure`. |

Queue, cache and session default to the `database` driver. Redis is optional.

## Composer (recommended)

```bash
composer create-project qiick/signaculum-core my-signaculum
cd my-signaculum
nvm use   # Node 24
php artisan signaculum:install
```

Pin a release when Packagist has it: `qiick/signaculum-core:1.0.0-beta.1`. Pre-tag smoke from git: `composer create-project qiick/signaculum-core:dev-develop my-signaculum` (needs `"minimum-stability": "dev"` / `@dev` as Composer requires).

`create-project` only copies `.env` (if missing) and runs `key:generate`. It does **not** migrate. First-run is `signaculum:install`:

- Writes `.env` (name, URL, database)
- Migrates
- Creates Passport keys (`storage/oauth-*.key`) when missing
- Creates the first id_token signing key (`signaculum:rotate-signing-key`)
- Creates the first instance administrator
- Optionally runs `npm install` and `npm run build` (skip with `--no-npm`)

Non-interactive (CI / Docker): always SQLite today.

```bash
php artisan signaculum:install --no-interaction --force
```

Pass `--force` to overwrite an existing `.env` or to run when `APP_ENV` looks like production.

## Docker Compose quick start

```bash
docker compose up --build
# App: http://localhost:8080
# Health: GET /health/live · GET /health/ready
```

The sample sets `RUN_MIGRATIONS=true` and SQLite volumes. Create the first administrator once the container is up (image already has frontend assets; skip npm):

```bash
docker compose exec signaculum php artisan signaculum:install --no-interaction --force --no-npm
```

Multi-arch images publish to `ghcr.io/qiick-io/signaculum-core` on a `v*` tag only (after the maintainer releases). Until then, build from this Compose file.

## Contributors (git clone)

```bash
git clone https://github.com/qiick-io/signaculum-core.git
cd signaculum-core
nvm use
composer setup          # install, .env, key, migrate, passport:keys, npm, build
# or: composer install && cp .env.example .env && php artisan key:generate
#     && php artisan migrate && php artisan passport:keys
#     && php artisan signaculum:rotate-signing-key && npm ci && npm run build
```

Local site: Laravel Herd at `https://signaculum-core.test` (`herd link`, `herd secure`), then `composer run dev` (or Vite + a queue worker).

## Health probes

Unauthenticated:

- `GET /health/live` — process up, no I/O
- `GET /health/ready` — database (and configured cache / queue) reachable; `503` otherwise

## Next steps

1. Sign in as the instance admin created by `signaculum:install`.
2. Register an OIDC application — [OIDC clients](./clients.md).
3. Create organisations and invite members — [Organisations](./organizations.md).
4. Back up Passport and signing keys — [Key rotation and backup](./key-rotation-and-backup.md).
5. Wire a qiick app with [`qiick/laravel-oidc`](https://github.com/qiick-io/laravel-oidc) (`php artisan oidc:configure`).

Protocol details: [OIDC profile](./oidc-profile.md).
