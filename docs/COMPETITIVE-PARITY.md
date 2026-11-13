# Competitive parity — Signaculum vs alternatives

Written 2026-11-13 for scoping **`v1.0.0-beta.1`**. Sources: public docs of each product (Keycloak specs and admin guide, Zitadel features/docs, authentik docs, Authelia overview, Logto docs, Ory Hydra/Kratos docs, Auth0 and Clerk product docs), plus the accepted Signaculum design (`knowledge/projects/qiick/research/2026-11-05-signaculum-sso-design.md`) and `docs/oidc-profile.md`.

**How to read the table**

| Cell    | Meaning                                                                        |
| ------- | ------------------------------------------------------------------------------ |
| ✓       | Supported as a first-class feature                                             |
| partial | Present with limits (preview, enterprise-only, headless/BYO UI, or incomplete) |
| ✗       | Not offered (or not the product’s job)                                         |

**Signaculum** column is the **planned beta** (design + approved board), not only what is already on `develop`. Gaps after the tables say **in beta** / **later** / **never** with one line why.

**Products compared**

| Product               | Role for a qiick customer                                     |
| --------------------- | ------------------------------------------------------------- |
| **Signaculum**        | qiick’s optional self-hosted OIDC IdP (Laravel + Passport)    |
| **Keycloak**          | Default enterprise self-hosted IAM                            |
| **Zitadel**           | Multi-tenant B2B IdP (AGPL)                                   |
| **Authentik**         | Full IdP + flows/outposts (MIT core)                          |
| **Authelia**          | Lightweight forward-auth / OIDC beside a reverse proxy        |
| **Logto**             | Developer CIAM (self-host or cloud)                           |
| **Ory**               | Hydra (OIDC AS) + Kratos (identity); headless                 |
| **Auth0** / **Clerk** | Hosted references for _user-facing_ account expectations only |

---

## 1. OIDC / OAuth core

| Feature                            | Signaculum (beta)     | Keycloak | Zitadel | Authentik | Authelia | Logto   | Ory     | Auth0 | Clerk   |
| ---------------------------------- | --------------------- | -------- | ------- | --------- | -------- | ------- | ------- | ----- | ------- |
| Authorization code + PKCE S256     | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Discovery (`openid-configuration`) | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| JWKS + key rotation with overlap   | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Userinfo                           | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Refresh tokens                     | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Token revocation (RFC 7009)        | partial → **in beta** | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Token introspection (RFC 7662)     | ✗ → **in beta**       | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | partial |
| RP-initiated logout                | ✓                     | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | ✓       |
| Back-channel logout                | ✓                     | ✓        | ✓       | ✓         | ✗        | partial | ✓       | ✓     | partial |
| Front-channel logout               | ✗ **never**           | ✓        | partial | ✓         | ✗        | ✗       | partial | ✓     | ✗       |
| `prompt` / `max_age` / `acr`       | ✓ (S2)                | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | partial |
| PAR (RFC 9126)                     | ✗ **later**           | ✓        | partial | partial   | ✗        | ✗       | ✓       | ✓     | ✗       |
| DPoP (RFC 9449)                    | ✗ **later**           | ✓        | partial | partial   | ✗        | ✗       | ✓       | ✓     | ✗       |
| Device authorization flow          | ✗ **later**           | ✓        | ✓       | ✓         | ✗        | ✓       | ✓       | ✓     | ✗       |
| Client credentials (M2M)           | ✗ → **in beta**       | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | ✓       |
| Dynamic client registration        | ✗ **later**           | ✓        | ✗       | ✓         | ✗        | partial | ✓       | ✓     | ✗       |

---

## 2. Accounts

| Feature                            | Signaculum (beta)     | Keycloak | Zitadel | Authentik | Authelia | Logto   | Ory (Kratos) | Auth0 | Clerk |
| ---------------------------------- | --------------------- | -------- | ------- | --------- | -------- | ------- | ------------ | ----- | ----- |
| Local email/password login         | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| MFA TOTP                           | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Passkeys / WebAuthn                | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Recovery codes                     | ✓                     | ✓        | ✓       | ✓         | ✓        | partial | ✓            | ✓     | ✓     |
| Email verification                 | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Password reset                     | ✓                     | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Password policy                    | ✓                     | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Brute-force / lockout              | partial → **in beta** | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Session management (list / revoke) | ✓                     | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account self-service               | ✓                     | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account deletion                   | ✓                     | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account data export                | ✗ → **in beta**       | partial  | partial | partial   | ✗        | partial | partial      | ✓     | ✓     |

---

## 3. Admin

| Feature                     | Signaculum (beta) | Keycloak   | Zitadel | Authentik | Authelia | Logto | Ory     | Auth0 | Clerk |
| --------------------------- | ----------------- | ---------- | ------- | --------- | -------- | ----- | ------- | ----- | ----- |
| Users admin                 | ✓                 | ✓          | ✓       | ✓         | partial  | ✓     | ✓ (API) | ✓     | ✓     |
| OIDC clients / applications | ✓                 | ✓          | ✓       | ✓         | ✓        | ✓     | ✓       | ✓     | ✓     |
| Organisations / tenants     | ✓                 | ✓ (realms) | ✓       | ✓         | ✗        | ✓     | partial | ✓     | ✓     |
| Roles (org roles in claims) | ✓                 | ✓          | ✓       | ✓         | partial  | ✓     | partial | ✓     | ✓     |
| Invitations                 | ✓                 | ✓          | ✓       | ✓         | ✗        | ✓     | ✓       | ✓     | ✓     |
| Impersonation               | ✗ **later**       | ✓          | ✓       | ✓         | ✗        | ✓     | partial | ✓     | ✓     |
| Audit / activity log        | ✓                 | ✓          | ✓       | ✓         | ✓        | ✓     | partial | ✓     | ✓     |
| Branding (instance)         | ✓                 | ✓          | ✓       | ✓         | partial  | ✓     | BYO UI  | ✓     | ✓     |
| Branding per tenant/org     | ✗ **later**       | ✓          | ✓       | partial   | ✗        | ✓     | BYO UI  | ✓     | ✓     |
| Editable email templates    | ✓                 | ✓          | ✓       | ✓         | partial  | ✓     | ✓       | ✓     | ✓     |
| i18n (en / it / de+)        | ✓                 | ✓          | ✓       | ✓         | partial  | ✓     | BYO UI  | ✓     | ✓     |

---

## 4. Federation

| Feature                  | Signaculum (beta) | Keycloak | Zitadel | Authentik | Authelia    | Logto   | Ory           | Auth0 | Clerk   |
| ------------------------ | ----------------- | -------- | ------- | --------- | ----------- | ------- | ------------- | ----- | ------- |
| Social login (Google, …) | ✗ **later**       | ✓        | ✓       | ✓         | ✗           | ✓       | ✓             | ✓     | ✓       |
| SAML IdP / SP            | ✗ **later**       | ✓        | ✓       | ✓         | ✗           | partial | partial (OEL) | ✓     | ✓       |
| LDAP / Active Directory  | ✗ **later**       | ✓        | ✓       | ✓         | ✓ (backend) | ✗       | ✗             | ✓     | partial |
| SCIM provisioning        | ✗ **later**       | ✓        | ✓       | ✓         | ✗           | partial | partial (OEL) | ✓     | ✓       |
| IdP-initiated login      | ✗ **later**       | ✓        | ✓       | ✓         | ✗           | partial | ✗             | ✓     | ✓       |

Design v1 explicitly excludes SAML, SCIM, social logins, IdP-initiated login and dynamic client registration. Signaculum stays a **standard OIDC provider**; qiick apps keep talking to Keycloak/Zitadel/… through `qiick/laravel-oidc` when those are preferred.

---

## 5. Operations

| Feature                             | Signaculum (beta) | Keycloak      | Zitadel       | Authentik | Authelia      | Logto   | Ory       | Auth0 | Clerk   |
| ----------------------------------- | ----------------- | ------------- | ------------- | --------- | ------------- | ------- | --------- | ----- | ------- |
| Docker / Compose image              | ✓                 | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Health endpoints                    | ✓                 | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Metrics (Prometheus, …)             | ✗ **later**       | ✓             | ✓             | ✓         | ✓             | partial | ✓         | ✓     | partial |
| Backup guidance (keys + DB)         | ✓ (R1 docs)       | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Signing-key rotation command        | ✓                 | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | ✓     | ✓       |
| Named rate limits                   | ✓ (S6)            | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | ✓     | ✓       |
| High availability / clustering      | ✗ **later**       | ✓             | ✓             | ✓         | partial       | partial | ✓         | ✓     | ✓       |
| Upgrade path (`:upgrade` / migrate) | ✓                 | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| OpenID conformance in CI            | ✓ (S7)            | ✓ (certified) | ✓ (certified) | ✓         | ✓ (certified) | partial | ✓ (Hydra) | ✓     | partial |

---

## Gap disposition (Signaculum)

### In beta (`v1.0.0-beta.1`) — small parity gaps

Already in the design or checklist: OIDC core (code+PKCE, discovery, JWKS rotation, userinfo, refresh), RP-initiated + back-channel logout, `amr`/`acr`/`prompt`/`max_age`, local accounts with TOTP/passkeys/recovery/email verify/password policy/sessions/self-service, admin users/clients/orgs/invitations, activity log, rate limits, Docker/health/upgrade, conformance CI.

**New proposals** (issues labelled `needs-decision`, `priority:P2` until Luca confirms):

| Gap                                             | Issue | Why parity needs it                                                                                                              |
| ----------------------------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------------------- |
| Token revocation RFC 7009 (advertised + tested) | #49   | Passport can revoke; customers and RFC 9700 expect a standard `/oauth/revoke` and discovery. Design §5 already names revocation. |
| Token introspection RFC 7662                    | #50   | Resource servers and API gateways validate opaque access tokens this way; every serious IdP ships it.                            |
| Client credentials grant                        | #51   | Machine clients (MCP agents, jobs) need M2M without a browser; design already separates machine credentials from SSO.            |
| Brute-force lockout                             | #52   | Instance settings already track login attempts; a real lockout matches Keycloak/Logto/Authelia regulation.                       |
| Editable email templates                        | #53   | **Shipped:** Settings → Email templates (verification / reset / invitation), en/it/de defaults, preview, fallback.               |
| Account data export                             | #54   | GDPR portability; Auth0/Clerk treat export as table stakes for account self-service.                                             |

### Later (milestone `Later`, not beta without Luca’s OK)

| Gap                          | Issue     | Why later                                                                            |
| ---------------------------- | --------- | ------------------------------------------------------------------------------------ |
| SAML                         | #55       | Design: not in v1; enterprise apps that need SAML keep Keycloak/Zitadel/Authentik.   |
| SCIM                         | #56       | Design: not in v1; Cloud provisioning can add it later.                              |
| Social logins inside the IdP | #57       | Design: not in v1; apps may still use Socialite separately.                          |
| IdP-initiated login          | #58       | Design: not in v1; SP-initiated OIDC only.                                           |
| Dynamic client registration  | #59       | Design: not in v1; admin-registered clients only.                                    |
| LDAP / Active Directory      | #60       | Large federation surface; out of MVP.                                                |
| HA clustering                | #61       | Ops-heavy; single-node Compose is enough for beta self-host.                         |
| PAR / DPoP                   | #62 / #63 | FAPI-oriented; no qiick RP needs them for beta.                                      |
| Device authorization flow    | #64       | Explicitly disabled in the profile today; TV/CLI can wait.                           |
| Front-channel logout         | #65       | Design: never rely on iframes/third-party cookies; back-channel + RP-initiated only. |
| Impersonation                | #66       | Support tooling; not required to run qiick SSO.                                      |
| Per-organisation branding    | #67       | Instance branding covers beta; multi-tenant themes wait for Cloud.                   |
| Prometheus metrics           | #68       | Nice for ops; health endpoints cover install smoke.                                  |

### Never (for Signaculum’s product intent)

| Gap                                              | Why never (as a goal)                                                                                    |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------- |
| Replacing Keycloak for every enterprise protocol | Signaculum is one OIDC IdP among others; depth in SAML/LDAP/SCIM is deliberately left to those products. |
| Front-channel logout as the primary logout story | Third-party cookies make it unreliable; back-channel is the contract with `qiick/laravel-oidc`.          |

---

## Sources (sampled 2026-11-13)

- Keycloak: [Specifications implemented](https://www.keycloak.org/securing-apps/specifications), Server Admin Guide (OIDC logout, passkeys, SCIM, DPoP, PAR).
- Zitadel: [Features](https://zitadel.com/features), docs (OIDC, SAML, SCIM, passkeys, audit trail, branding).
- authentik: [Documentation](https://goauthentik.io/docs/) (OIDC, SAML, LDAP, SCIM, flows, impersonation).
- Authelia: [Overview](https://www.authelia.com/overview/prologue/introduction/) (2FA, regulation/lockout, OIDC provider).
- Logto: [Introduction](https://docs.logto.io/introduction) (MFA, passkeys, lockout, orgs, audit, email/customization).
- Ory: Hydra (certified OAuth2/OIDC AS) + Kratos (self-service, MFA, passkeys); SAML/SCIM often OEL / Network.
- Auth0 / Clerk: public docs and comparison pages for MFA, passkeys, social, SAML/SCIM, audit, GDPR export.

Cells can drift as vendors ship; update this file when a parity decision changes scope.
