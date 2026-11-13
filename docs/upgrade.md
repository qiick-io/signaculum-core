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
- Trees installed from earlier `develop` snapshots: put this tag's code in place, `composer install`, then `signaculum:upgrade` so migrations (including `activity_log` from `spatie/laravel-activitylog`) apply.
- New dependency: `spatie/laravel-activitylog`.

## After upgrade

- Confirm `GET /health/ready` returns 200.
- Spot-check discovery (`/.well-known/openid-configuration`) and a test login from a registered client.
- If you rotated signing keys as part of maintenance, leave the overlap window in place — [Key rotation and backup](./key-rotation-and-backup.md).
