# Outbound webhooks

Instance admins register HTTPS (or http) endpoints under **Settings → Webhooks**.
Signaculum POSTs a signed [Standard Webhooks](https://www.standardwebhooks.com/)
request when a subscribed domain event occurs. Delivery, signing, retries and
the SSRF guard come from [`qiick/laravel-outbound`](https://github.com/qiick-io/laravel-outbound)
(`^0.2.0-beta.3`, `config/outbound.php`).

This is not telemetry: deliveries go only to URLs an admin registers. Private,
loopback and link-local hosts are refused unless **Allow private webhook URLs**
is enabled (local QA); the setting applies to every endpoint, at once for
deliveries still pending, and no environment variable overrides it. Cloud metadata
hosts stay blocked either way, whether the URL names them or a hostname
resolves to them.

Every attempt is pinned to an address the guard checked (resolved for A and
AAAA, any disallowed address refuses the URL, one validated address handed to
curl with `CURLOPT_CONNECT_TO`), so neither DNS rebinding nor the way the host
is spelt can send the request elsewhere. Redirects are never followed. An IPv4
address must be written in dotted decimal.

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

## Payload

```json
{
    "type": "user.created",
    "timestamp": "2026-11-16T16:00:00+00:00",
    "data": {
        "id": "user-subject-uuid",
        "email": "alex@example.com",
        "name": "Alex Example"
    }
}
```

- `type`: one of the event strings above.
- `timestamp`: when the event happened (RFC 3339).
- `data`: event-specific object.

The event id is the `webhook-id` header (`msg_` followed by a ULID). Delivery is
at least once: treat `webhook-id` as the idempotency key; a retry or a
redelivery keeps it.

## Headers

| Header              | Value                                                                                   |
| ------------------- | --------------------------------------------------------------------------------------- |
| `Content-Type`      | `application/json`                                                                      |
| `webhook-id`        | Event id, stable across retries                                                         |
| `webhook-timestamp` | Unix seconds when this attempt was signed                                               |
| `webhook-signature` | Space-separated `v1,<base64>` signatures, one per active secret (two during a rotation) |

### Verifying the signature

The secret shown once when the endpoint is created is a `whsec_` secret. Use
any Standard Webhooks library, or compute it yourself:

```text
key      = base64_decode(secret without the "whsec_" prefix)
expected = base64(hmac_sha256(key, webhook-id + "." + webhook-timestamp + "." + raw_body))
```

Accept the request when one of the `v1,…` signatures equals `expected`, and
reject it when `webhook-timestamp` is more than five minutes away from your
clock (replay window).

## Delivery

The event stores one delivery per subscribed endpoint, in the same transaction
as the change it reports, and queues a small job (`SendWebhookDeliveriesJob`,
after the database commit) that sends the first attempts at once. The job starts
no new send after 20 seconds, so with a send taking at most 30 seconds it ends
inside a queue worker's default 60-second timeout; what is left stays due. The
scheduler runs `outbound:webhooks:work` **once a minute** (`onOneServer()`, up
to 50 attempts a run, a run still busy skips the next), for the retries and for
anything a lost job left behind; nothing polls more often, so an idle instance
costs nothing between events. Each delivery is leased just before its send, so
the job and the scheduled run never send one twice; a send cut off by a worker
timeout is sent again once its lease runs out, which delivery at least once
allows.

**When an attempt is sent:**

| Attempt                      | Sent                                                                        |
| ---------------------------- | --------------------------------------------------------------------------- |
| First                        | when a queue worker picks up the job (at once with `QUEUE_CONNECTION=sync`) |
| First, if the job never runs | by a scheduled run, which starts every minute                               |
| Retry                        | by the first scheduled run after it is due (`next_attempt_at`)              |

Sixty seconds is the scheduler's cadence, not a latency bound: a backlog of more
than 50 due attempts, or slow receivers, push attempts to later runs.

The queue worker and the scheduler (`php artisan schedule:run` every minute, or
`schedule:work`) must both run; `onOneServer()` needs a cache store with locks
(`database` or Redis, the defaults).

A failed attempt (any non-2xx answer, a timeout or a connection error) is
retried on the Standard Webhooks schedule, 10 attempts over about 75 hours,
honouring `Retry-After`. `410 Gone` stops at once and disables the endpoint. An
endpoint that keeps failing (five days without a success and at least ten
failures in a row) is disabled and a mail names its host to its creator while
still an active instance admin, otherwise to every active instance admin.
Disabling an endpoint by hand while changing its URL holds its pending
deliveries before the new URL is saved. Turning it back on
in the settings resumes its pending deliveries. Request bodies are kept for 30
days and delivery records for 90 (`outbound:webhooks:prune`, daily, on one
server). The five most recent deliveries of each endpoint appear on the
Webhooks settings page.

## Changed in this beta

Before `qiick/laravel-outbound`, Signaculum sent its own format: body
`{id, type, created_at, data}` and `X-Signaculum-Event`, `X-Signaculum-Event-Id`,
`X-Signaculum-Timestamp` and `X-Signaculum-Signature` (`sha256=` HMAC) headers.
Receivers verify the Standard Webhooks headers instead, with the new `whsec_`
secret.
