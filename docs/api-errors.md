# API error codes and response language

Signaculum follows the fleet standard in `knowledge/conventions/standards-register.md` (rows
_API errors_, _JSON for machine doors_, _Response language_, _Translations_). The base format is
Laravel's own JSON error rendering, with one extra field: a stable `snake_case` `error` code.
RFC 9457 was considered and not adopted.

## Codes

| Code                 | HTTP status                                      | Meaning                                                             |
| -------------------- | ------------------------------------------------ | ------------------------------------------------------------------- |
| `bad_request`        | 400 (any unlisted 4xx)                           | Malformed or otherwise unacceptable request.                        |
| `validation_failed`  | 422                                              | Validation failed; the `errors` object holds one message per field. |
| `unauthenticated`    | 401                                              | No valid credentials were presented.                                |
| `forbidden`          | 403                                              | The authenticated principal may not do this.                        |
| `not_found`          | 404                                              | Route or model not found.                                           |
| `method_not_allowed` | 405                                              | The HTTP method is not supported on this route.                     |
| `too_many_requests`  | 429                                              | Rate limit exceeded; `Retry-After` and `X-RateLimit-*` are kept.    |
| `server_error`       | 500 (any unlisted 5xx, or an unmapped exception) | Unexpected server fault.                                            |

Codes are values of `App\Enums\ApiErrorCodeEnum`. They are wire contract and never change.
`docs/api-errors.md` and the `api_errors.<code>` keys in `lang/{en,it,de}.json` must contain every
case; `tests/Feature/Errors/ErrorStandardCoverageTest.php` fails when one is missing.

## Response shape

Laravel renders the response; the single `Exceptions::render()` hook in `bootstrap/app.php` only
adds `error` to the final JSON. `message` and `errors` are exactly Laravel's, including nested
field keys in dot notation, and `message` uses the negotiated locale through Laravel's
translator. `error` is an opaque code and is never translated.

```json
{
    "message": "The email field is required.",
    "errors": { "email": ["The email field is required."] },
    "error": "validation_failed"
}
```

A response that already has an `error` key is never decorated, so the RFC 6750 codes
`invalid_token`, `insufficient_scope` and `ip_not_allowed` keep their exact body and
`WWW-Authenticate` challenge. Unmapped application exceptions become `server_error`; adding a new
`App\Exceptions` class requires a code in `ApiErrorCodeEnum::applicationExceptionCodes()`.

With `APP_DEBUG=false` a 500 contains no `trace`, `file`, `line` or `exception`.

## Scope

The standard applies to the product REST API (`api/*`) and `/mcp`. Those paths always render
JSON, even with no `Accept` header, so a client never receives an HTML login redirect.

OAuth/OIDC endpoints are **not** decorated: `/oauth/authorize`, `/oauth/token`, `/oauth/userinfo`,
`/oauth/introspect`, `/oauth/revoke`, `/oauth/par`, `/oauth/end-session`, and
`/.well-known/openid-configuration` / `/.well-known/jwks.json`. They keep RFC 6749/6750 error
bodies and the existing bearer challenge. `tests/Feature/Errors/ApiErrorRenderingTest.php`
verifies this.

## Language

`SetLocaleMiddleware` sends `Content-Language` on every web and API response.

- API and MCP: `Accept-Language` (RFC 9110 q-values via Symfony `getPreferredLanguage()`), then
  the authenticated principal's saved locale, then the instance default. An unsupported or
  absent header falls through; it never causes a 406.
- Web: signed-in user's locale, then `locale` cookie, then the instance default.

Framework language files are published with `php artisan lang:publish`; `lang/en/*`, `lang/it/*`
and `lang/de/*` must have the same keys, including `validation.php` for translated validation
messages. Mail uses Laravel's `HasLocalePreference` on the user.
