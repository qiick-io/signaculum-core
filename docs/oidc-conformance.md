# OpenID Foundation conformance (S7)

Signaculum runs the self-hosted OpenID Foundation conformance suite in CI against a disposable instance. Details and local steps live in [`conformance/README.md`](../conformance/README.md).

## What the maintainer must add as a required check

After the workflow’s first green (or accepted) run, add this check name to branch protection on `develop` and `main` (identical lists):

- `oidc-conformance`

Do **not** treat a missing required-check entry as a reason to squash-merge this PR until the maintainer understands the job. Agents must not change required checks themselves.

## Environment notes

- `OIDC_REQUIRE_PKCE=false` and `OIDC_REQUIRE_NONCE=false` only in the conformance job.
- `TRUSTED_PROXIES=*` so the TLS proxy’s `X-Forwarded-*` headers are honoured.
- `QUEUE_CONNECTION=sync` so back-channel logout jobs run inline.
