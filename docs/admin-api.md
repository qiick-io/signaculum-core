# Admin REST API

Machine-accessible administration under `/api/v1`, authenticated with Bearer API
keys managed in **Admin → API keys**. The Inertia UI never calls this API.

OpenAPI 3.1: `GET /api/v1/openapi.json` (hand-written; no generator package).

## Authentication

1. Create a key as an instance admin (shown once).
2. Send `Authorization: Bearer sk_…`.
3. Keys are SHA-256 hashed at rest; the UI shows a 12-character prefix only.
4. The key owner must stay an active instance admin; revoked or expired keys fail with `401`.

## Scopes

| Scope                 | Access                                              |
| --------------------- | --------------------------------------------------- |
| `users:read`          | List and show users                                 |
| `users:write`         | Create, update, deactivate, reset 2FA               |
| `organizations:read`  | List/show organisations and memberships             |
| `organizations:write` | Create organisations; add/update/remove memberships |
| `applications:read`   | List and show OIDC applications                     |
| `applications:write`  | Create applications; rotate client secret           |

Write scopes do not imply read: request both when needed.

## Endpoints (summary)

- `GET/POST /api/v1/users`, `GET/PATCH /api/v1/users/{id}`
- `POST /api/v1/users/{id}/deactivate`, `POST /api/v1/users/{id}/reset-two-factor`
- `GET/POST /api/v1/organizations`, `GET /api/v1/organizations/{slug}`
- `GET/POST /api/v1/organizations/{slug}/memberships`
- `PATCH/DELETE /api/v1/organizations/{slug}/memberships/{user_subject}`
- `GET/POST /api/v1/applications`, `GET /api/v1/applications/{id}`
- `POST /api/v1/applications/{id}/rotate-secret`

Lists accept `page`, `per_page` (max 100), and where noted `search` / `sort` / `direction`.
Responses use snake_case JSON resources. Writes reuse the same `*Action` classes as the UI and appear in the activity log.
