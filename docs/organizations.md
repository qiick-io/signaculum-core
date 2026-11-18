# Organisations

Signaculum organisations group people for SSO apps. Membership roles appear on the OpenID Connect `orgs` claim when the client requests the `qiick.orgs` scope.

## Model

| Field               | Notes                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **id**              | UUID (stable on the wire).                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| **slug**            | Unique, URL-safe; also on the claim.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        |
| **name**            | Display name in the UI.                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                     |
| **is_mfa_required** | When true, members must enroll TOTP or a passkey **and** prove a second factor in the current session `amr` (`mfa`, or passkey `hwk`/`swk`) before finishing OAuth authorization. Enrolment alone is not enough; email-code-only or password-only sessions are stepped up (login toast explains the loop). With `prompt=none`, the client gets `interaction_required` and the session stays intact. `amr` / `acr` stay truthful. The requirement holds whatever `config/fortify.php` enables: only an enabled method counts (TOTP off → passkeys only), and with neither TOTP nor passkeys enabled members are refused with an explanation (`access_denied` for `prompt=none`); Settings → Authentication & security then lists the affected organisations. Turning it **on** revokes every member's access tokens, refresh tokens and unredeemed authorization codes (logged as `organization_member_tokens_revoked`), so clients must run the authorization code flow again; the gate only runs there, and tokens do not record how their session authenticated (#163). |

Members may hold one or more of: `owner`, `admin`, `member`, `billing` (Decision 2026-11-13). Every organisation must keep **at least one `owner`**.

## UI

Signed-in users manage organisations they belong to under **Organisations**:

- **Members** — list, add/remove roles, leave (guards prevent removing the last owner).
- **Invitations** — email invite with a hashed token, expiry (default seven days, `OIDC_ORG_INVITATION_TTL_DAYS`), resend and revoke.
- **Settings** — name / slug, optional MFA requirement, branding and other org settings.

Instance admins may also create organisations as part of day-to-day ops; invitations are rate-limited.

## `qiick.orgs` claim

Clients that request scope `qiick.orgs` receive on the `id_token` and userinfo:

```json
"orgs": [
  { "id": "…", "slug": "acme", "roles": ["owner", "billing"] }
]
```

- Empty list when the user has no memberships.
- Sorted by `slug`.
- Role strings match the enum above.

[`qiick/laravel-oidc`](https://github.com/qiick-io/laravel-oidc) can map `orgs` into app roles (`oidc.roles.*`, with `organization` set to the id or slug this app belongs to). Add `qiick.orgs` to `oidc.scopes` on the client.

Wire details: [OIDC profile](./oidc-profile.md).
