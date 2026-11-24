# Competitive parity — Signaculum vs alternatives

Written 2026-11-13 for scoping **`v1.0.0-beta.1`**; Signaculum column refreshed on 2026-11-17 and again on 2026-11-24 against `develop`. Sources: public docs of each product (Keycloak specs and admin guide, Zitadel features/docs, authentik docs, Authelia overview, Logto docs, Ory Hydra/Kratos docs, Auth0 and Clerk product docs), plus the accepted Signaculum design (`knowledge/projects/qiick/research/2026-11-05-signaculum-sso-design.md`) and `docs/oidc-profile.md`.

**How to read the table**

| Cell    | Meaning                                                                        |
| ------- | ------------------------------------------------------------------------------ |
| ✓       | Supported as a first-class feature                                             |
| partial | Present with limits (preview, enterprise-only, headless/BYO UI, or incomplete) |
| ✗       | Not offered (or not the product’s job)                                         |

**Positioning changed on 2026-11-18.** Until then this file described Signaculum as "one OIDC IdP among others", with SAML, SCIM, LDAP, device authorization and front-channel logout marked _later_ or _never_. The maintainer then decided that unreleased products build the full programme, and that the `v1.0.0` milestone governs over earlier design documents (knowledge: `decisions/2026-11-18-unreleased-products-build-the-full-programme`, scope approved in #134). This file follows the milestone (#177).

**Signaculum** column: **✓** is on `develop`; **v1.0.0** is in the `v1.0.0` milestone (issue linked); **later** is in the `Later` milestone with `needs-decision`. Gaps after the tables say **in v1.0.0** / **later** / **never** with one line why.

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

| Feature                            | Signaculum      | Keycloak | Zitadel | Authentik | Authelia | Logto   | Ory     | Auth0 | Clerk   |
| ---------------------------------- | --------------- | -------- | ------- | --------- | -------- | ------- | ------- | ----- | ------- |
| Authorization code + PKCE S256     | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Discovery (`openid-configuration`) | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| JWKS + key rotation with overlap   | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Userinfo                           | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Refresh tokens                     | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Token revocation (RFC 7009)        | ✓               | ✓        | ✓       | ✓         | ✓        | ✓       | ✓       | ✓     | ✓       |
| Token introspection (RFC 7662)     | ✓               | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | partial |
| RP-initiated logout                | ✓               | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | ✓       |
| Back-channel logout                | ✓               | ✓        | ✓       | ✓         | ✗        | partial | ✓       | ✓     | partial |
| Front-channel logout               | ✓ (best-effort) | ✓        | partial | ✓         | ✗        | ✗       | partial | ✓     | ✗       |
| `prompt` / `max_age` / `acr`       | ✓ (S2)          | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | partial |
| PAR (RFC 9126)                     | ✓               | ✓        | partial | partial   | ✗        | ✗       | ✓       | ✓     | ✗       |
| DPoP (RFC 9449)                    | later (#63)     | ✓        | partial | partial   | ✗        | ✗       | ✓       | ✓     | ✗       |
| Device authorization flow          | ✓ (RFC 8628)    | ✓        | ✓       | ✓         | ✗        | ✓       | ✓       | ✓     | ✗       |
| Client credentials (M2M)           | ✓ (opt-in, #51) | ✓        | ✓       | ✓         | partial  | ✓       | ✓       | ✓     | ✓       |
| Dynamic client registration        | later (#59)     | ✓        | ✗       | ✓         | ✗        | partial | ✓       | ✓     | ✗       |

---

## 2. Accounts

| Feature                            | Signaculum | Keycloak | Zitadel | Authentik | Authelia | Logto   | Ory (Kratos) | Auth0 | Clerk |
| ---------------------------------- | ---------- | -------- | ------- | --------- | -------- | ------- | ------------ | ----- | ----- |
| Local email/password login         | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| MFA TOTP                           | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Passkeys / WebAuthn                | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Recovery codes                     | ✓          | ✓        | ✓       | ✓         | ✓        | partial | ✓            | ✓     | ✓     |
| Email verification                 | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Password reset                     | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Password policy                    | ✓          | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Brute-force / lockout              | ✓          | ✓        | ✓       | ✓         | ✓        | ✓       | ✓            | ✓     | ✓     |
| Session management (list / revoke) | ✓          | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account self-service               | ✓          | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account deletion                   | ✓          | ✓        | ✓       | ✓         | partial  | ✓       | ✓            | ✓     | ✓     |
| Account data export                | ✓          | partial  | partial | partial   | ✗        | partial | partial      | ✓     | ✓     |

---

## 3. Admin

| Feature                     | Signaculum | Keycloak   | Zitadel | Authentik | Authelia | Logto | Ory     | Auth0 | Clerk |
| --------------------------- | ---------- | ---------- | ------- | --------- | -------- | ----- | ------- | ----- | ----- |
| Users admin                 | ✓          | ✓          | ✓       | ✓         | partial  | ✓     | ✓ (API) | ✓     | ✓     |
| OIDC clients / applications | ✓          | ✓          | ✓       | ✓         | ✓        | ✓     | ✓       | ✓     | ✓     |
| Organisations / tenants     | ✓          | ✓ (realms) | ✓       | ✓         | ✗        | ✓     | partial | ✓     | ✓     |
| Roles (org roles in claims) | ✓          | ✓          | ✓       | ✓         | partial  | ✓     | partial | ✓     | ✓     |
| Invitations                 | ✓          | ✓          | ✓       | ✓         | ✗        | ✓     | ✓       | ✓     | ✓     |
| Impersonation               | ✓          | ✓          | ✓       | ✓         | ✗        | ✓     | partial | ✓     | ✓     |
| Audit / activity log        | ✓          | ✓          | ✓       | ✓         | ✓        | ✓     | partial | ✓     | ✓     |
| Branding (instance)         | ✓          | ✓          | ✓       | ✓         | partial  | ✓     | BYO UI  | ✓     | ✓     |
| Branding per tenant/org     | ✓          | ✓          | ✓       | partial   | ✗        | ✓     | BYO UI  | ✓     | ✓     |
| Editable email templates    | ✓          | ✓          | ✓       | ✓         | partial  | ✓     | ✓       | ✓     | ✓     |
| i18n (en / it / de+)        | ✓          | ✓          | ✓       | ✓         | partial  | ✓     | BYO UI  | ✓     | ✓     |

---

## 4. Federation

| Feature                  | Signaculum       | Keycloak | Zitadel | Authentik | Authelia    | Logto   | Ory           | Auth0 | Clerk   |
| ------------------------ | ---------------- | -------- | ------- | --------- | ----------- | ------- | ------------- | ----- | ------- |
| Social login (Google, …) | v1.0.0 (#57)     | ✓        | ✓       | ✓         | ✗           | ✓       | ✓             | ✓     | ✓       |
| SAML IdP / SP            | v1.0.0 IdP (#55) | ✓        | ✓       | ✓         | ✗           | partial | partial (OEL) | ✓     | ✓       |
| LDAP / Active Directory  | partial (#60)    | ✓        | ✓       | ✓         | ✓ (backend) | ✗       | ✗             | ✓     | partial |
| SCIM provisioning        | v1.0.0 (#56)     | ✓        | ✓       | ✓         | ✗           | partial | partial (OEL) | ✓     | ✓       |
| IdP-initiated login      | later (#58)      | ✓        | ✓       | ✓         | ✗           | partial | ✗             | ✓     | ✓       |

Since 2026-11-18, `v1.0.0` includes a SAML 2.0 IdP for SP-initiated SSO, SCIM 2.0 provisioning, a read-only LDAP / AD user source (sync on `develop`, admin UI #78) and upstream IdPs brokered through `qiick/laravel-oidc` (#57, #70–#72). IdP-initiated login, dynamic client registration and DPoP stay in `Later`. qiick apps can still talk to Keycloak, Zitadel and others through `qiick/laravel-oidc` when those are preferred.

---

## 5. Operations

| Feature                             | Signaculum   | Keycloak      | Zitadel       | Authentik | Authelia      | Logto   | Ory       | Auth0 | Clerk   |
| ----------------------------------- | ------------ | ------------- | ------------- | --------- | ------------- | ------- | --------- | ----- | ------- |
| Docker / Compose image              | ✓            | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Health endpoints                    | ✓            | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Metrics (Prometheus, …)             | v1.0.0 (#68) | ✓             | ✓             | ✓         | ✓             | partial | ✓         | ✓     | partial |
| Backup guidance (keys + DB)         | ✓ (R1 docs)  | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| Signing-key rotation command        | ✓            | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | ✓     | ✓       |
| Named rate limits                   | ✓ (S6)       | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | ✓     | ✓       |
| High availability / clustering      | later (#61)  | ✓             | ✓             | ✓         | partial       | partial | ✓         | ✓     | ✓       |
| Upgrade path (`:upgrade` / migrate) | ✓            | ✓             | ✓             | ✓         | ✓             | ✓       | ✓         | n/a   | n/a     |
| OpenID conformance in CI            | ✓ (S7)       | ✓ (certified) | ✓ (certified) | ✓         | ✓ (certified) | partial | ✓ (Hydra) | ✓     | partial |

---

## Gap disposition (Signaculum)

### In beta (`v1.0.0-beta.1`) — small parity gaps

Already in the design or checklist: OIDC core (code+PKCE, discovery, JWKS rotation, userinfo, refresh), RP-initiated + back-channel logout, `amr`/`acr`/`prompt`/`max_age`, local accounts with TOTP/passkeys/recovery/email verify/password policy/sessions/self-service, admin users/clients/orgs/invitations, activity log, rate limits, Docker/health/upgrade, conformance CI.

**New proposals** (issues labelled `needs-decision`, `priority:P2` until the maintainer confirms):

| Gap                                             | Issue | Why parity needs it                                                                                                  |
| ----------------------------------------------- | ----- | -------------------------------------------------------------------------------------------------------------------- |
| Token revocation RFC 7009 (advertised + tested) | #49   | **Shipped:** `/oauth/revoke`, advertised in discovery.                                                               |
| Token introspection RFC 7662                    | #50   | **Shipped:** `/oauth/introspect` for resource servers.                                                               |
| Client credentials grant                        | #51   | **Shipped** — opt-in on confidential apps; discovery advertises the grant; no `id_token` on this flow.               |
| Brute-force lockout                             | #52   | **Shipped:** per-account lockout after repeated failures, with an admin unlock.                                      |
| Editable email templates                        | #53   | **Shipped:** Settings → Email templates (verification / reset / invitation), en/it/de defaults, preview, fallback.   |
| Account data export                             | #54   | **Shipped:** Settings → Profile → export your account data as JSON.                                                  |
| Impersonation                                   | #66   | **Shipped:** instance admins can act as a user for support, with an audit entry and a visible banner to stop.        |
| Per-organisation branding                       | #67   | **Shipped:** organisation owners and admins set a logo and colours shown on login and consent for that organisation. |

### In `v1.0.0` (positioning of 2026-11-18)

| Gap                                  | Issue              | Status on `develop`                                                                                                                                  |
| ------------------------------------ | ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| PAR (RFC 9126)                       | #62                | **Shipped.**                                                                                                                                         |
| Device authorization flow (RFC 8628) | #64                | **Shipped**, opt-in per application.                                                                                                                 |
| Front-channel logout                 | #65                | **Shipped, best-effort.** Third-party cookie blocking makes iframes unreliable, so back-channel logout stays the contract with `qiick/laravel-oidc`. |
| LDAP / Active Directory              | #60, #77, #78      | Read-only sync **shipped**; admin settings UI #78 open.                                                                                              |
| SAML 2.0 IdP (SP-initiated)          | #55, #73, #74      | Not started.                                                                                                                                         |
| SCIM 2.0 provisioning                | #56, #75, #76      | Not started.                                                                                                                                         |
| Upstream IdPs and social login       | #57, #70, #71, #72 | Not started; brokered through `qiick/laravel-oidc`.                                                                                                  |
| Prometheus metrics                   | #68                | Not started.                                                                                                                                         |

### Later (milestone `Later`, each `needs-decision`)

| Gap                         | Issue | Why later                                                                                                    |
| --------------------------- | ----- | ------------------------------------------------------------------------------------------------------------ |
| IdP-initiated login         | #58   | SP-initiated flows only in v1; IdP-initiated login is the classic unsolicited-response risk.                 |
| Dynamic client registration | #59   | Admin-registered clients only in v1; the 2026-11-18 decision puts it in scope, the order is not decided yet. |
| HA clustering               | #61   | Ops-heavy; single-node Compose is enough for a self-hosted v1.                                               |
| DPoP (RFC 9449)             | #63   | Sender-constrained tokens; no qiick relying party needs them for v1.                                         |

### Never (for Signaculum’s product intent)

| Gap                                              | Why never (as a goal)                                                                           |
| ------------------------------------------------ | ----------------------------------------------------------------------------------------------- |
| Front-channel logout as the primary logout story | Third-party cookies make it unreliable; back-channel is the contract with `qiick/laravel-oidc`. |

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
