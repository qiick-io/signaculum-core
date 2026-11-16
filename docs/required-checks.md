# Required checks (for the maintainer)

Agents do **not** change branch protection. Luca sets the same required status checks on `develop` and on `main`, with “require branches to be up to date” enabled.

Reusable workflow checks are named `<caller job id> / <job>` (see `.qiick/standards/release.md`).

## Proposed list for signaculum-core

### Own jobs (`tests.yml`)

| Check name | Source                                                                                          |
| ---------- | ----------------------------------------------------------------------------------------------- |
| `ci`       | `.github/workflows/tests.yml` job `ci` (Pint, PHPStan, Pest, knip, lint:i18n, Larastan ratchet) |

### Shared (`qiick.yml` → app-ui `@v0.6.1`)

| Check name                              | Source                                                                                 |
| --------------------------------------- | -------------------------------------------------------------------------------------- |
| `pr-title / commitlint`                 | `pr-title.yml` (pull_request only; still list it if GitHub shows it for PRs)           |
| `app-ui-drift / standards`              | `drift-check.yml` with `standards: true`                                               |
| `php-quality / php-quality`             | `php-quality.yml`                                                                      |
| `ui-quality / ui-quality`               | `ui-quality.yml`                                                                       |
| `migrations-frozen / migrations-frozen` | `migrations-frozen.yml`                                                                |
| `distribution-smoke / smoke`            | `app-distribution-smoke.yml` (`package: qiick/signaculum-core`, `command: signaculum`) |

Confirm the exact names on a green PR’s Checks tab before saving branch protection — GitHub must match the reported check run names character-for-character. When a workflow renames a job, the same PR that renames it must tell Luca which required check to replace.

## Out of scope for agents

- Enabling or editing branch protection / rulesets
- Admin bypass, force-push, or merging to `main` without «rilascia»
