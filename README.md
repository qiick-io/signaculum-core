# Signaculum

**Signaculum** is the optional, open-source, self-hosted OpenID Connect identity provider of the [qiick](https://qiick.io) suite. It gives qiick apps such as Externa and Excubia App single sign-on, with organisations and roles, and it is the identity layer of qiick Cloud.

Every qiick app keeps working standalone with its own local login. Signaculum is one standard IdP among others: if you already run Keycloak, Zitadel, Authentik or Entra ID, you do not need it. On the app side, the generic client package [`qiick/laravel-oidc`](https://github.com/qiick-io/laravel-oidc) works with any of them.

> **Status:** early development. Nothing is released yet and it is not ready for production. An external security review is planned before 1.0.

## Planned for the MVP

- Local accounts: email and password, TOTP two-factor, passkeys, email verification
- Organisations with members and the roles `owner`, `admin`, `member` and `billing`
- OpenID Connect provider on Laravel Passport: authorization code flow with PKCE (S256, required), RS256 `id_token`, discovery, JWKS with key rotation, userinfo, RP-initiated and back-channel logout
- Scopes `openid`, `profile`, `email` and `qiick.orgs` (an `orgs` claim with the user's organisations and roles), plus `amr` / `acr` so apps know whether MFA happened
- Admin screens for OIDC clients and organisations
- Tested against the OpenID Foundation conformance suite (see [`conformance/README.md`](conformance/README.md))

Self-registration is **off by default** (closed IdP). Instance admins turn it on under Settings → Authentication & security (`registration_enabled`); qiick Cloud can enable it for its hosted instance. Until then, only instance admins create users.

Not planned for v1: SAML, SCIM, IdP-initiated login, dynamic client registration and social logins.

## Install

`composer create-project` only copies `.env` and generates `APP_KEY`. It does not migrate or seed. Finish a new tree with:

```bash
php artisan signaculum:install
```

Non-interactive (CI and Docker): `php artisan signaculum:install --no-interaction --force`.

Updates use `php artisan signaculum:upgrade` after the new code is in place. Do not run `create-project` again to upgrade.

`GET /health/live` and `GET /health/ready` are the unauthenticated probes. A Compose quick start is in `docker-compose.yml`.

## Development

Requirements: PHP 8.4, Composer, Node 24. The local site runs on [Laravel Herd](https://herd.laravel.com) at `https://signaculum-core.test`.

```bash
composer setup
composer test                                    # Pint, PHPStan, Pest
npm run lint && npm run types && npm run build
```

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

MIT, see [LICENSE](LICENSE).
