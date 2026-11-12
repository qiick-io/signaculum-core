# Changelog

## [Unreleased]

### Added

- `signaculum:install` and `signaculum:upgrade` for a self-hosted tree.
- `GET /health/live` and `GET /health/ready`.
- Multi-stage `Dockerfile`, `docker-bake.hcl` and a Compose quick start.

### Upgrade notes

- `composer create-project` no longer migrates. Run `php artisan signaculum:install` (or `php artisan migrate --force` on a tree that was already installed before this change).
- Later updates: put the new code in place, install dependencies, then `php artisan signaculum:upgrade`. Do not run `create-project` again.
