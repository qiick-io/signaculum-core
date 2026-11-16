# Security Policy

## Supported versions

Signaculum is pre-release and not production-ready. Security fixes go to the latest `0.x` release. An external security review is planned before 1.0.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" on the Security tab of `qiick-io/signaculum-core`). We aim to acknowledge reports within 72 hours.

Signaculum is an identity provider: flaws in token signing, PKCE, redirect URI matching, nonce handling, session or logout handling are treated as critical.

Signaculum has no telemetry. The optional “reject breached passwords” setting (off by default) is the only built-in outbound check related to credentials: it sends a SHA-1 hash prefix to Have I Been Pwned’s k-anonymity API. See [`docs/password-policy.md`](docs/password-policy.md).
