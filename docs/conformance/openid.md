# OpenID Foundation conformance (OpenID Provider)

Signaculum is tested as an **OpenID Provider (OP)** with the self-hosted [OpenID Foundation conformance suite](https://gitlab.com/openid/conformance-suite). This page records which test plans apply, the latest results, how to re-run them, and what formal certification would take. The scripts, plan configs and Compose overlays live in [`conformance/`](../../conformance/README.md); the CI job is [`.github/workflows/oidc-conformance.yml`](../../.github/workflows/oidc-conformance.yml).

The relying-party side (`qiick/laravel-oidc`) has its own page: `docs/conformance/openid.md` in `qiick-io/laravel-oidc`.

## Which plans apply

| Certification profile   | Suite plan (variant)                                                                                                    | Applies? | Why                                                                         |
| ----------------------- | ----------------------------------------------------------------------------------------------------------------------- | -------- | --------------------------------------------------------------------------- |
| Basic OP                | `oidcc-basic-certification-test-plan[server_metadata=discovery][client_registration=static_client]`                     | yes      | authorization code flow, the only interactive flow in v1                    |
| Config OP               | `oidcc-config-certification-test-plan`                                                                                  | yes      | discovery and JWKS                                                          |
| RP-Initiated Logout OP  | `oidcc-rp-initiated-logout-certification-test-plan[response_type=code][client_registration=static_client]`              | yes      | `end_session_endpoint`                                                      |
| Back-Channel Logout OP  | `oidcc-backchannel-rp-initiated-logout-certification-test-plan[response_type=code][client_registration=static_client]`  | yes      | `backchannel_logout_supported`                                              |
| Front-Channel Logout OP | `oidcc-frontchannel-rp-initiated-logout-certification-test-plan[response_type=code][client_registration=static_client]` | yes      | `frontchannel_logout_supported` (#65)                                       |
| Dynamic OP              | `oidcc-dynamic-certification-test-plan`                                                                                 | no       | no dynamic client registration in v1                                        |
| Form Post OP            | `oidcc-formpost-*-certification-test-plan`                                                                              | no       | `response_modes_supported` is `["query"]`; `form_post` is not in the design |
| Implicit / Hybrid OP    | `oidcc-implicit-*`, `oidcc-hybrid-*`                                                                                    | no       | v1 is authorization code only                                               |
| Session Management OP   | `oidcc-session-management-certification-test-plan`                                                                      | no       | `check_session_iframe` is not implemented                                   |

## Results (2026-11-27, suite `release-v5.1.43`, `develop` at `44b4523`)

Two runs: the nightly CI run ([run 45986044504](https://github.com/qiick-io/signaculum-core/actions/runs/45986044504)) and a local run against the app served by Flock over HTTPS.

| Plan                    | Modules | Passed | Review | Skipped | Warning | Failed | Result                       |
| ----------------------- | ------- | ------ | ------ | ------- | ------- | ------ | ---------------------------- |
| Basic OP                | 35      | 25     | 5      | 4       | 1       | 0      | pass with one warning        |
| Config OP               | 1       | 1      | 0      | 0       | 0       | 0      | pass (local only, not in CI) |
| RP-Initiated Logout OP  | 11      | 3      | 8      | 0       | 0       | 0      | pass, needs review           |
| Back-Channel Logout OP  | 2       | 2      | 0      | 0       | 0       | 0      | pass                         |
| Front-Channel Logout OP | 2       | 2      | 0      | 0       | 0       | 0      | pass (local only, not in CI) |

- **Warning:** `oidcc-scope-profile`: userinfo does not return every `profile` scope claim (OIDC Core 5.4). Signaculum has no value for `middle_name`, `nickname`, `profile`, `website`, `gender`, `birthdate` or `zoneinfo`, and leaves `picture` out when the user has no avatar. Tracked in [#303](https://github.com/qiick-io/signaculum-core/issues/303).
- **Review:** these modules pass automatically up to a page the suite cannot judge itself: an error page (unregistered `redirect_uri`, missing `response_type`, invalid `id_token_hint`, unregistered `post_logout_redirect_uri`), the second login of `prompt=login` and `max_age=1`, and the logged-out page. `conformance/browser-runner.py` uploads a screenshot of each page. For certification, a person checks those screenshots before submitting.
- **Skipped:** `oidcc-scope-address`, `oidcc-scope-phone` and `oidcc-scope-all` (scopes not offered) and `oidcc-unsigned-request-object-supported-correctly-or-rejected-as-unsupported` (Signaculum answers `request_not_supported`, which the spec allows).
- The local run had one module, `oidcc-server-client-secret-post`, interrupted once when the browser runner stopped on the consent page. A re-run of that module alone (`--rerun 1:30`) passed, and it passes in CI. The browser-runner flake is tracked in [#304](https://github.com/qiick-io/signaculum-core/issues/304).

## Re-running the plans

### In CI

`oidc-conformance` runs on every push to `develop`, every night at 02:17 UTC, and on `workflow_dispatch` (Actions → oidc-conformance → Run workflow). The exported plan zips, the browser-runner log and the server logs are uploaded as the `oidc-conformance-results` artifact.

### Locally against Flock (HTTPS)

Prerequisites: Docker, a Flock (or Herd / Valet) site serving this checkout over HTTPS, PHP 8.4. Use your own worktree's database only.

1. In `.env` set `DB_DATABASE` to this checkout's `database/database.sqlite`, plus `OIDC_REQUIRE_PKCE=false`, `OIDC_REQUIRE_NONCE=false`, `TRUSTED_PROXIES=*` and `MAIL_MAILER=array`. Revert these lines afterwards; never relax PKCE or nonce outside a conformance run.
2. `php artisan migrate --force && php artisan passport:keys --force`, then seed the user and clients with `CONFORMANCE_ALIAS`, `CONFORMANCE_SUITE_PUBLIC_URL=https://nginx:8443` and `CONFORMANCE_BACKCHANNEL_BASE_URL=https://localhost:8443` exported (`php conformance/seed.php`; the values are in the workflow's `env`). The front-channel plan needs one more client whose `frontchannel_logout_uri` is `https://nginx:8443/test/a/<alias>-frontchannel/frontchannel_logout`.
3. Clone the suite at the tag in the workflow and build it in a container, with a named volume for the Maven cache:
   `docker run --rm -v oidf-m2:/root/.m2 -v "$PWD":/src -w /src maven:3-eclipse-temurin-21 mvn -B -q -DskipTests package`.
4. Start `mongodb nginx server chrome` from the suite's `docker-compose-localtest.yml`, plus an overlay that maps the Flock host name to `host-gateway` (`extra_hosts`) in `server`, `nginx`, `chrome` and a `python:3.12-slim` runner service. The runner installs `httpx pyparsing selenium` and runs `conformance/browser-runner.py` with `SIGNACULUM_BASE_URL=https://<site>.test` next to `scripts/run-test-plan.py --export-dir … <plan> <config>`.
5. Back-channel logout is delivered by the queue. Run `php -d curl.cainfo=<bundle> artisan queue:work`, where `<bundle>` is the system CA bundle plus the suite's self-signed certificate (`openssl s_client -connect 127.0.0.1:8443 -showcerts`). Do not add the certificate to the host trust store.
6. Summarise with `python3 conformance/check-results.py --results-dir <export-dir>`.

`conformance/docker-compose.local.yml` is the Docker-only alternative that runs Signaculum in a container instead of Flock.

## CI job proposal

Today's job already runs on standard GitHub-hosted runners (`ubuntu-latest`, free for this public repository), about 18 minutes per run. It is not a required check, and it should stay optional: the suite pulls `selenium/standalone-chromium:latest` and a Maven build, so an upstream change can break it without any change here. The proposal for a follow-up PR (workflow changes go in their own commit):

1. **Add the two plans that pass locally**: `oidcc-config-certification-test-plan` and `oidcc-frontchannel-rp-initiated-logout-certification-test-plan`. Add the front-channel client to `conformance/seed.php` and a `configs/frontchannel.json` / `configs/config.json`, following the existing configs.
2. **Keep the triggers** (push to `develop`, nightly `schedule`, `workflow_dispatch`), and **do not add it to the required checks**. A red nightly run opens or updates a `[conformance]` issue instead of blocking merges.
3. **Fail on `FAILED` and `WARNING`, not on `REVIEW`**: `check-results.py` already treats `REVIEW` and `SKIPPED` as non-blocking. Count warnings separately, so that a new warning is visible in the job summary.
4. **Pin the browser image by digest** instead of `latest`, and bump `CONFORMANCE_SUITE_REF` (now `release-v5.1.43`; `release-v5.1.45` exists) in a Dependabot-style PR of its own.

## Formal certification (not started)

Nothing has been registered or paid. What it would take:

- **Run on the hosted suite.** Certification packages are exported with **Publish for certification** on each plan. The documented path is `https://www.certification.openid.net/`, which needs Signaculum on a public HTTPS URL (a dedicated test instance, never production). Whether exports from a self-hosted suite are accepted is not documented: ask `certification@oidf.org` first.
- **Choose the profiles**: Basic OP, Config OP, RP-Initiated Logout OP, Back-Channel Logout OP and Front-Channel Logout OP. A certification run must use the production settings, so it runs with `OIDC_REQUIRE_PKCE=true` and `OIDC_REQUIRE_NONCE=true`. Check whether the suite's modules that send no PKCE or no nonce still pass when the OP refuses them before submitting.
- **Review evidence**: check every `REVIEW` screenshot, and fix or explain the `oidcc-scope-profile` warning.
- **Fee** (OpenID Foundation fee schedule, read 2026-11-27): OpenID Connect certification costs **USD 3,500 per deployment for non-members** and **USD 700 for members**. One payment covers every OpenID Connect profile of that deployment within a calendar year. OP and RP are separate payments. Certifications do not expire, and a different protocol endpoint is a different deployment.
- **Open-source waiver**: under the Open-Source Project Certification Policy, one fee waiver per project per year covers OP and/or RP. Conditions: the requester is an OpenID Foundation member in good standing, none of the project's primary maintainers are paid for their work on it, and all relevant profiles of the specification are submitted at once. Any further request within a year costs USD 100. Each request is decided case by case.
- **Submission**: one zip per plan, a payment code (or waiver), the form at `submissions.openid.net`, and a Declaration of Conformance signed through DocuSign by the maintainer.

Sources: <https://openid.net/certification/>, <https://openid.net/certification/fees/>, <https://openid.net/how-to-submit-your-certification-request/>, <https://openid.net/certification/open-source-project-certification-policy/>, <https://openid.net/certification/what-is-self-certification-faq/>.
