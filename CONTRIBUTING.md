# Contributing to Signaculum

Thanks for helping. Signaculum is a self-hosted OpenID Connect identity provider built on Laravel and Passport. A few rules keep the codebase consistent:

1. Open or pick an issue first. One issue per pull request.
2. Branch from `develop` as `<type>/<issue>-<kebab-slug>` (types: feat, fix, refactor, chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format.
4. Open the PR against `develop` with `Closes #N`. PRs are squash-merged.
5. Before pushing run the quality gates: `composer test` (Pint, PHPStan and Pest), then `npm run lint && npm run types && npm run build`.
6. Code style: strict types, explicit role suffixes, thin controllers, readonly `*Data` classes, English docblocks that explain _why_, no abbreviations. `tests/Arch/NamingArchitectureTest.php` enforces the naming rules.
7. Every protocol rule (PKCE, nonce, redirect URI matching, token claims, logout) has a Pest test. The wire contract is shared with `qiick/laravel-oidc`: changing it needs an issue first.
