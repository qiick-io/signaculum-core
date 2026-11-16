# Security Policy

## Supported versions

| Version          | Supported                                                  |
| ---------------- | ---------------------------------------------------------- |
| `1.0.0-beta.x`   | Security fixes on the latest beta tag only                 |
| `< 1.0.0-beta.1` | No — pre-tag `develop` snapshots are not a support channel |

Signaculum `v1.0.0-beta.1` is a **beta** identity provider: suitable for evaluation and qiick-suite integration testing, **not** a substitute for a production IdP hardening review. An **external security review is planned before 1.0.0**, not for this beta.

## Reporting a vulnerability

Please do **not** open a public issue.

Use GitHub's private vulnerability reporting (“Report a vulnerability” on the Security tab of [`qiick-io/signaculum-core`](https://github.com/qiick-io/signaculum-core/security)). We aim to acknowledge reports within **72 hours**.

Signaculum is an identity provider. Flaws in token signing, PKCE, redirect URI matching, nonce handling, session or logout handling, JWKS / key storage, or client-secret handling are treated as **critical**.

Signaculum has **no telemetry**. The optional “reject breached passwords” setting (off by default) is the only built-in outbound check related to credentials: it sends a SHA-1 hash **prefix** to Have I Been Pwned’s k-anonymity API when enabled. See [docs/password-policy.md](./docs/password-policy.md). Outbound webhook delivery URLs are SSRF-validated before request (see [docs/webhooks.md](./docs/webhooks.md)).

## Hardening expectations (operators)

- Keep `OIDC_REQUIRE_PKCE` and `OIDC_REQUIRE_NONCE` at their defaults (`true`) in production.
- Serve the issuer over HTTPS; set `APP_URL` / `OIDC_ISSUER` without a trailing slash and keep them stable.
- Back up `APP_KEY`, Passport keys and the database — see [Key rotation and backup](./docs/key-rotation-and-backup.md).
- Register redirect and post-logout URIs with exact match only; never wildcards.
