# Upgrade

How to move an existing Signaculum install to a newer release. **Do not** run `composer create-project` again — that only copies a fresh tree once.

## Steps

1. Put the new code in place (git merge / checkout of a release tag, image pull, or `signaculum:upgrade --sync-upstream=vX.Y.Z`).
2. Install dependencies and rebuild the frontend:

    ```bash
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build
    ```

3. Run the upgrade command:

    ```bash
    php artisan signaculum:upgrade
    ```

    In production:

    ```bash
    php artisan signaculum:upgrade --force
    ```

`signaculum:upgrade` migrates (unless `--no-migrate`), runs any version-specific data steps, and clears caches. It does **not** run Composer or npm.

### Opt-in upstream sync

If your tree is a git clone of your own remote and you want to pull official release files:

```bash
php artisan signaculum:upgrade --sync-upstream=v1.0.0-beta.1 --dry-run
php artisan signaculum:upgrade --sync-upstream=v1.0.0-beta.1 --yes
```

`--sync-upstream` may overwrite local files. Use `--dry-run` first. A dirty working tree needs `--force`.

## Docker

Pull (or rebuild) the image for the new tag, keep the same volumes for `database/` and `storage/`, then run migrations / upgrade inside the container:

```bash
docker compose pull   # when using GHCR tags
docker compose up -d
docker compose exec signaculum php artisan signaculum:upgrade --force --no-migrate
# or let RUN_MIGRATIONS=true on boot, then clear caches:
docker compose exec signaculum php artisan optimize:clear
```

Preserve `storage/oauth-*.key` and the database (including the `signing_keys` table). See [Key rotation and backup](./key-rotation-and-backup.md).

## Upgrade notes

Every released version lists **Upgrade notes** under its heading in [CHANGELOG.md](../CHANGELOG.md). Read them before you upgrade.

For `1.0.0-beta.1` (first tagged release):

- Fresh installs: `create-project` then `signaculum:install` (migrate is no longer part of `create-project`).
- Trees installed from earlier `develop` snapshots: put this tag's code in place, `composer install`, then `signaculum:upgrade` so migrations apply (`activity_log`, `email_login_codes`, `organizations.is_mfa_required`, `webhooks` / `webhook_deliveries`, `api_keys`, and earlier OIDC / org tables).
- New dependency: `spatie/laravel-activitylog`.
- Authentication & security settings replace the old `weak` / `medium` / `strong` password presets with granular fields. Re-save that page after upgrade if you relied on a stored preset; enable “Reject breached passwords” explicitly if you want the HIBP check (it is never implied).
- Email-code login and outbound webhooks need a **queue worker**. Mail (verification, reset, invitations, email codes) and webhook delivery are queued.
- Admin REST API keys are created under Admin → API keys after upgrade; there is no seed key.

## Beta database cutover (`1.0.0-beta.2`)

`1.0.0-beta.2` replaces several tables with those of the fleet packages (`qiick/laravel-access`: groups and `principals`; `qiick/laravel-api-keys`: `api_keys`) by editing their `create_` migrations, as allowed before the first stable release. A beta.1 database cannot be migrated forward: `signaculum:upgrade` or `RUN_MIGRATIONS=true` would run the new `create_` migrations against tables that already exist and stop. Production refuses `migrate:fresh`, so the cutover recreates the database instead. Nothing in it is carried over.

**What is lost and what to keep**

- Lost with the database: users and their passkeys and two-factor enrolments, organisations, groups, OIDC applications (client ids and secrets), consents and tokens, Admin API keys, webhooks, settings, the activity log, and the `id_token` signing keys (`signing_keys`).
- Keep: `APP_KEY` and `storage/oauth-*.key` (Passport), and your `.env`. Relying parties must be registered again and given their new client credentials; they fetch the new signing key from `/.well-known/jwks.json` (one with a cached JWKS fails until it refreshes). Admin API keys and webhook endpoints are issued again.

**Steps**

1. Back up the beta.1 database (and `storage/`), then stop the app and its queue worker.
2. Recreate an empty database:
    - SQLite: move `database/database.sqlite` aside and create an empty file (`touch database/database.sqlite`).
    - PostgreSQL / MySQL: drop the database and create it again, empty, with the same name and owner.
    - Docker: stop the stack and remove the volume that holds `database/` (for SQLite) or recreate the database on the server; keep the `storage/` volume.
3. Put the beta.2 code in place and install dependencies, as in the steps above.
4. Build the empty schema and the first administrator (with `INITIAL_SUPER_ADMIN_*` set in `.env`):

    ```bash
    php artisan migrate --force
    php artisan signaculum:sync-permissions
    php artisan db:seed --class=CreateSuperAdminSeeder --force
    php artisan signaculum:rotate-signing-key
    php artisan optimize:clear
    ```

    With Docker, `RUN_MIGRATIONS=true` runs the first two on boot; run the last three with `docker compose exec signaculum php artisan …`.

5. Start the app and the queue worker, sign in as the administrator, and register the applications, organisations, API keys and webhooks again.

## After upgrade

- Confirm `GET /health/ready` returns 200.
- Confirm a queue worker is running if you use mail or webhooks.
- Spot-check discovery (`/.well-known/openid-configuration`) and a test login from a registered client.
- If you rotated signing keys as part of maintenance, leave the overlap window in place — [Key rotation and backup](./key-rotation-and-backup.md).
