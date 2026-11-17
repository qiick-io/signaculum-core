# Admin REST API

Machine-accessible administration under `/api/v1`, authenticated with Bearer API
keys managed in **Admin → API keys**. The Inertia UI never calls this API.

OpenAPI 3.1: `GET /api/v1/openapi.json` (hand-written; no generator package).

## Authentication

1. Create a key as an instance admin in **Admin → API keys**. The token is shown once.
2. Send `Authorization: Bearer sig_live_…` (`sig_test_…` outside production).
3. The token is `sig_<live|test>_<id><secret>`: the public part `sig_<env>_<id>` (8-character id)
   is what the UI, the activity log and support threads show; the 32-character secret is never
   stored. Only the SHA-256 of the full token is kept. Secret scanners match the qiick fleet
   pattern `^(ext|exc|ins|sig)_(live|test)_[A-Za-z0-9]{40,}$`.
4. Every refusal of a key is `401` with `WWW-Authenticate: Bearer error="invalid_token"` and
   `{"message": "…", "error": "invalid_token"}`: missing, unknown, expired or revoked key, a key
   whose scopes no longer parse, or an owner who is no longer an active instance admin.
5. A missing scope is `403` with `"error": "insufficient_scope"` and the `scope` it needed; an
   address outside the key's IP allowlist is `403`; the per-key rate limit is `429`.
6. **Rotation** issues a new secret for the same key (same prefix, scopes and audit trail). The
   old token keeps working for `api.key_rotation_grace_hours` (24 by default). Revoking ends both.
7. Keys are managed in the signed-in admin UI only: no API key can create, rotate or widen a key,
   and an API key never establishes a web session (it has its own `api-key` guard).
8. Owners (and issuers) get a mail seven days before a key expires
   (`signaculum:notify-expiring-api-keys`, scheduled daily).

## Scopes

Scopes follow the shared qiick vocabulary `<resource>:<read|write>`. **`write` implies `read`** on
the same resource, so a key stores one scope per resource, the highest it holds.

| Scope                 | Access                                              |
| --------------------- | --------------------------------------------------- |
| `users:read`          | List and show users                                 |
| `users:write`         | Create, update, deactivate, reset 2FA               |
| `organizations:read`  | List/show organisations and memberships             |
| `organizations:write` | Create organisations; add/update/remove memberships |
| `applications:read`   | List and show OIDC applications                     |
| `applications:write`  | Create applications; rotate client secret           |

Endpoints are gated by scope route middleware (`EnsureApiKeyScopeMiddleware`), a declared
divergence from the app-ui baseline: the identity provider gates its own resources this way.

## Endpoints (summary)

- `GET/POST /api/v1/users`, `GET/PATCH /api/v1/users/{id}`
- `POST /api/v1/users/{id}/deactivate`, `POST /api/v1/users/{id}/reset-two-factor`
- `GET/POST /api/v1/organizations`, `GET /api/v1/organizations/{slug}`
- `GET/POST /api/v1/organizations/{slug}/memberships`
- `PATCH/DELETE /api/v1/organizations/{slug}/memberships/{user_subject}`
- `GET/POST /api/v1/applications`, `GET /api/v1/applications/{id}`
- `POST /api/v1/applications/{id}/rotate-secret`

Lists accept `page`, `per_page` (max 100), and where noted `search` / `sort` / `direction`.
Responses use snake_case JSON resources. Writes reuse the same `*Action` classes as the UI and appear in the activity log, with the
`api_key_uuid` of the key that authorised them.
