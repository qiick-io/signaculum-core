# Outbound webhooks

Instance admins register HTTPS (or http) endpoints under **Settings → Webhooks**.
Signaculum POSTs a signed JSON body when a subscribed domain event occurs.

This is not telemetry: deliveries go only to URLs an admin registers. Private,
loopback and link-local hosts are refused unless **Allow private webhook URLs**
is enabled (local QA). Cloud metadata hosts stay blocked either way. Hostname
targets are resolved for A and AAAA; any disallowed address refuses the URL, and
delivery pins one validated address with `CURLOPT_RESOLVE` so DNS rebinding
cannot send the POST elsewhere.

## Events

| Type                              | When                                   |
| --------------------------------- | -------------------------------------- |
| `user.created`                    | Admin create or self-registration      |
| `user.updated`                    | Admin or profile update                |
| `user.deleted`                    | Account deletion                       |
| `user.locked`                     | Login lockout after failed attempts    |
| `user.signed_in`                  | Successful login                       |
| `organization.membership_changed` | Member added, removed or roles changed |
| `application.created`             | OIDC application registered            |
| `application.deleted`             | OIDC application revoked               |

## Payload (stable wire format)

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "type": "user.created",
    "created_at": "2026-11-16T16:00:00+00:00",
    "data": {
        "id": "user-subject-uuid",
        "email": "alex@example.com",
        "name": "Alex Example"
    }
}
```

- `id`: event id (UUID). At-least-once delivery; treat as idempotency key.
- `type`: one of the event strings above.
- `created_at`: ISO-8601 timestamp when the event was queued.
- `data`: event-specific object (never empty array).

## Headers

| Header                   | Value                                                                            |
| ------------------------ | -------------------------------------------------------------------------------- |
| `Content-Type`           | `application/json`                                                               |
| `X-Signaculum-Event`     | Event type                                                                       |
| `X-Signaculum-Event-Id`  | Same as payload `id`                                                             |
| `X-Signaculum-Timestamp` | Unix seconds when the request was signed                                         |
| `X-Signaculum-Signature` | `sha256=` + HMAC-SHA256 of `timestamp + "." + raw_body` with the endpoint secret |
| `User-Agent`             | `Signaculum-Webhooks/1.0`                                                        |

### Verifying the signature

```text
expected = "sha256=" + hmac_sha256(secret, timestamp + "." + body)
```

Reject when the timestamp is older than a few minutes (replay window) or the
signature does not match.

## Delivery

Queued job with three tries and backoff (10s, 30s, 60s). Non-2xx responses are
retried. SSRF refusals and empty secrets are logged as `refused` and not
retried. Recent attempts appear on the Webhooks settings page.
