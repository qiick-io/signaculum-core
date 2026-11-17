# LDAP / Active Directory (read-only sync)

Signaculum can synchronise users from an LDAP directory or Active Directory into
local IdP accounts. The connector is **read-only**: Signaculum never writes back
to the directory. Settings UI lands in a follow-up issue; storage, sync job and
`signaculum:ldap-sync` ship here (#77).

Library: [`directorytree/ldaprecord-laravel`](https://ldaprecord.com/docs/laravel/v4)
`^4` with PHP `ext-ldap`.

## What is stored

Instance settings key `ldap` (JSON in `settings`):

| Field | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | Sync no-ops until enabled |
| `hosts` | `[]` | One or more hostnames / IPs |
| `port` | `636` | `636` for LDAPS, `389` for StartTLS |
| `encryption` | `tls` | `tls` (LDAPS), `starttls`, or `none` (refused while enabled) |
| `bind_dn` | `''` | Service account DN |
| `bind_password` | `null` | Laravel `Crypt` ciphertext; never returned to UI/API |
| `base_dn` | `''` | Search base |
| `user_filter` | `(objectClass=person)` | Extra LDAP filter (simple `(attr=value)` preferred) |
| Attribute map | mail / givenName / sn / displayName / uid / objectGUID | Configurable |
| `sync_interval_minutes` | `60` | Scheduler interval |
| `last_synced_at` | `null` | ISO-8601 after a successful run |

TLS (LDAPS) or StartTLS is **required by default**. Enabling the connector with
`encryption=none` is rejected.

## Sync behaviour

1. Bind with the service account (when configured).
2. Search under `base_dn` with the user filter.
3. For each entry with a stable GUID (`objectGUID` / `entryUUID` / configured attribute)
   and a valid `mail`:
   - **Create** a local user linked by `users.ldap_guid` (random unusable local password).
   - **Update** name / email / DN when already linked.
   - **Conflict**: a local account already owns that email and is not LDAP-linked →
     skip and write an activity-log `ldap_sync_conflict` (never overwrite).
4. LDAP-linked users missing from this run are **deactivated** (`is_active=false`),
   not deleted (tokens/sessions revoked via the usual deactivate path).

Activity log also records `ldap_sync_completed` with create/update/deactivate/conflict
counts. Bind passwords never appear in logs, activity properties, or `forEdit()`
payloads (`has_bind_password` boolean only).

## Commands and schedule

```bash
php artisan signaculum:ldap-sync
```

The scheduler dispatches `SyncLdapUsersJob` every minute; the job returns early when
LDAP is disabled or the sync interval since `last_synced_at` has not elapsed.

`TestLdapConnectionAction` probes bind/connectivity for the future settings UI (#78).

## Bind-on-login

Not wired in this change. LDAP-linked users currently hold a random local password
hash. Optional bind-on-login (verify against LDAP, no usable local password) needs a
Fortify / user-provider hook and should be a dedicated follow-up if still desired.

## Operations

- Requires `ext-ldap` on the PHP runtime (Herd / Docker image).
- Rotate the bind password from instance settings when the UI exists; empty password
  on save keeps the existing ciphertext.
- Test without a real server: LdapRecord `DirectoryEmulator` (see `tests/Feature/Ldap`).
