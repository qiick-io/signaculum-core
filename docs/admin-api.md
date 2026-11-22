# Admin REST API

Machine-accessible administration under `/api/v1`, authenticated with Bearer API
keys managed in **Admin → API keys**. The Inertia UI never calls this API.

OpenAPI 3.1: `GET /api/v1/openapi.json` (hand-written; no generator package).

## Authentication

1. Create a key as an instance admin in **Admin → API keys**. The token is shown once.
   Keys are owned by the singleton `instance_service` principal (`organisation_id` null);
   `created_by` records the issuing admin and is distinct from that owner (#229).
2. Send `Authorization: Bearer sig_live_…` (`sig_test_…` outside production).
3. The token is `sig_<live|test>_<id><secret>`: the public part `sig_<env>_<id>` (8-character id)
   is what the UI, the activity log and support threads show; the 32-character secret is never
   stored. Only the SHA-256 of the full token is kept. Secret scanners match the qiick fleet
   pattern `^(ext|exc|ins|sig)_(live|test)_[A-Za-z0-9]{40,}$`.
4. Every refusal of a key is `401` with `WWW-Authenticate: Bearer error="invalid_token"` and
   `{"message": "…", "error": "invalid_token"}`: missing, unknown, expired or revoked key, a key
   whose scopes no longer parse, a key whose `instance_service` principal is missing or
   deactivated, or when **no active instance admin** (someone with `can-manage-api-keys`)
   remains on the instance. Demoting or deactivating the creating admin alone does **not**
   revoke the key while another instance admin is still active; revocation stays manual.
5. A missing scope is `403` with `"error": "insufficient_scope"` and the `scope` it needed; an
   address outside the key's IP allowlist is `403`; the per-key rate limit is `429`.
6. **Rotation** issues a new secret for the same key (same prefix, scopes and audit trail). The
   old token keeps working for `api.key_rotation_grace_hours` (24 by default). Revoking ends both.
   Any instance admin with `can-manage-api-keys` may rotate or revoke any Admin API key.
   A rotation sets a new `expires_at` (see 9), so it never extends a key past the maximum.
7. Keys are managed in the signed-in admin UI only: no API key can create, rotate or widen a key,
   and an API key never establishes a web session (it has its own `api-key` guard).
8. Creators get a mail seven days before a key expires (`signaculum:notify-expiring-api-keys`,
   scheduled daily) while they are still active instance admins; when the creator is not, every
   active instance admin gets it.
9. **Every key expires.** `expires_at` is required at creation and at rotation, in the future and
   at most `api.key_max_lifetime_days` (`API_KEY_MAX_LIFETIME_DAYS`, 90 by default) after the
   creation or rotation; the limit itself is allowed. A request without an expiry or past the
   limit is refused with `422` (the console form shows the error); the actions refuse it too.

## Scopes

Scopes follow the shared qiick vocabulary `<resource>:<read|write>`. **`write` implies `read`** on
the same resource, so a key stores one scope per resource, the highest it holds. These are
**instance-only** scopes: an Admin API key cannot hold organisation-scoped capabilities, and
there is no Admin API surface inside a single organisation.

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

Create payloads are built into the same `*Data` object as the admin UI's form requests —
`ApplicationData::fromValidated()`, `OrganizationData::fromValidated()` — and those refuse to build a
payload whose property they do not carry, so a field added for the UI reaches the API or fails loudly.
`tests/Feature/Api/AdminApiApplicationFieldParityTest.php` walks `ApplicationData`'s properties by
reflection and asserts each one is accepted by the create validation and present in the API resource (#186).
