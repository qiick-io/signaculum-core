# Key rotation and backup

Signaculum uses two key sets. Losing either breaks token issuance or verification for existing clients.

## What to back up

| Material          | Where                                                   | Notes                                                                                                                            |
| ----------------- | ------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| **APP_KEY**       | `.env`                                                  | Encrypts signing-key private material at rest and other Laravel encrypted casts. Losing it makes stored private keys unreadable. |
| **Passport keys** | `storage/oauth-private.key`, `storage/oauth-public.key` | Access / refresh token cryptography (League OAuth2 / Passport). Created by `passport:keys` during `signaculum:install`.          |
| **Database**      | Your DB volume / dump                                   | Includes `signing_keys` (id_token JWKS), `oauth_*`, users, organisations, activity log.                                          |
| **`.env`**        | Secure secret store                                     | Issuer URL (`APP_URL` / `OIDC_ISSUER`), DB credentials, mail, etc. Never commit it.                                              |

Do **not** commit private keys or `.env`. Signing keys are generated on the server and stay there.

Suggested backup cadence: database dump + copy of `storage/oauth-*.key` and a sealed copy of `.env` / `APP_KEY` after every install and before every upgrade.

## id_token signing keys (JWKS)

- Algorithm **RS256**, RSA **3072-bit**, `kid` = RFC 7638 JWK thumbprint.
- Public keys: `GET /.well-known/jwks.json` (`Cache-Control: public, max-age=300`).
- Private key: column `signing_keys.private_key`, encrypted with `APP_KEY`.

### Rotate

```bash
php artisan signaculum:rotate-signing-key
```

The new key signs from that moment. The previous key is marked `retired_at` but **stays in the JWKS** for `OIDC_SIGNING_KEY_OVERLAP_HOURS` (default **24**), so id_tokens issued just before rotation still verify. Clients refetch JWKS when they meet an unknown `kid`.

Run once at install (`signaculum:install` does this) and on a schedule afterwards (for example every 90 days). After a suspected compromise: rotate immediately, shorten sessions if needed, and treat Passport key replacement as a separate incident response step.

### Overlap configuration

```env
OIDC_SIGNING_KEY_OVERLAP_HOURS=24
```

See `config/oidc.php`. Do not set the overlap shorter than your longest realistic id_token lifetime plus client clock skew.

## Passport keys

```bash
php artisan passport:keys --force
```

Only when replacing compromised or lost Passport keys. Existing access and refresh tokens become invalid; users sign in again. Prefer restoring from backup over regenerating when keys were merely misplaced on a new host.

## Restore checklist

1. Restore the database.
2. Restore `.env` with the **same** `APP_KEY`.
3. Restore `storage/oauth-private.key` and `storage/oauth-public.key` (mode `600` / `644` as appropriate).
4. `php artisan optimize:clear` and confirm `GET /health/ready` and JWKS.
5. Do **not** run `signaculum:rotate-signing-key` unless you intend to retire the restored active key.
