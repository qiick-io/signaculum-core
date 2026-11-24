# OIDC clients (applications)

Instance admins register relying parties under **Admin → Applications**. Each application is an OAuth 2.0 / OpenID Connect client. The living wire contract is [OIDC profile](./oidc-profile.md).

## Register a client

In the Applications drawer set:

| Field                         | Meaning                                                                                                                                 |
| ----------------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| **Name**                      | Shown on the consent screen.                                                                                                            |
| **Redirect URIs**             | One per line. Exact match at runtime (no wildcards). `https` required except localhost. No fragments.                                   |
| **Post-logout redirect URIs** | Exact match for RP-initiated logout (`post_logout_redirect_uri`).                                                                       |
| **Back-channel logout URI**   | Absolute URL that accepts `POST` with `logout_token` (optional).                                                                        |
| **Front-channel logout URI**  | Absolute https URL loaded in a hidden iframe on logout (optional; OIDC Front-Channel Logout 1.0). Exact value; no fragment.             |
| **Front-channel session**     | When enabled, Signaculum appends `iss` and `sid` query parameters to the front-channel logout URI.                                      |
| **Confidential / public**     | Confidential clients have a secret; public clients use PKCE only (`token_endpoint_auth_methods_supported` includes `none`).             |
| **First-party**               | Skips the consent screen for this instance's own apps.                                                                                  |
| **Allow client credentials**  | Opt-in for confidential clients only: machine tokens without a user (`grant_type=client_credentials`). No `id_token`, no refresh token. |

On create, Signaculum shows the **client id** and **client secret** once. Store the secret offline; regenerate from the UI if it is lost (confirm dialog). Delete requires typed confirmation.

The drawer also shows the discovery URL and a ready-to-run `oidc:configure` example for [`qiick/laravel-oidc`](https://github.com/qiick-io/laravel-oidc).

## Protocol expectations

- **Authorization code + PKCE S256** for interactive SSO (required for every client, including confidential, when `OIDC_REQUIRE_PKCE` is true).
- **`nonce`** required on every `openid` authorize request when `OIDC_REQUIRE_NONCE` is true; echoed in the `id_token`.
- **`state`** is passed through; clients must validate it.
- Scopes: `openid`, `profile`, `email`, `qiick.orgs` (organisations — see [Organisations](./organizations.md)).
- Logout: register post-logout, back-channel and optional front-channel URIs; Signaculum supports RP-initiated, back-channel and front-channel logout.

## Connect a Laravel app (`qiick/laravel-oidc`)

On the application:

```bash
composer require qiick/laravel-oidc
php artisan vendor:publish --tag=oidc-config
php artisan migrate
php artisan oidc:configure \
  --issuer=https://signaculum.example.com \
  --client-id=... \
  --client-secret=...
```

`oidc:configure` checks discovery (issuer, code flow, PKCE S256), JWKS, client type and redirect URIs, and prints the redirect, post-logout and back-channel URIs to register in Signaculum. `--write-env` writes `OIDC_*` only when every check passed. Add scope `qiick.orgs` in the app's `oidc.scopes` when you need the `orgs` claim.

Typical URIs for the package defaults:

| Purpose      | URI                                                                                                                           |
| ------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| Redirect     | `{APP_URL}/auth/oidc/callback`                                                                                                |
| Post-logout  | `{APP_URL}/auth/oidc/logged-out` from the next laravel-oidc beta; `{APP_URL}/` before it (or `oidc.post_logout_redirect_uri`) |
| Back-channel | `{APP_URL}/auth/oidc/backchannel-logout`                                                                                      |

While an application moves to the next laravel-oidc beta, register both post-logout URIs: Signaculum matches the `post_logout_redirect_uri` exactly against the registered list.

## Machine clients

Prefer authorization code for people. Use **client credentials** only when no human is present (jobs, agents). Enable “Allow client credentials” on a confidential application. Introspection (`POST /oauth/introspect`) and revocation (`POST /oauth/revoke`) are available to confidential clients — see [OIDC profile](./oidc-profile.md).
