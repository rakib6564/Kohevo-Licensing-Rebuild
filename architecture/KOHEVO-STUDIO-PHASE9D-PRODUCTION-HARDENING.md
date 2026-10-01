# Kohevo Studio — Phase 9D: production hardening

Scope: observability, security-denial visibility, runtime/deployment requirements, web-server parity and
public-response hardening. No schema change, no new environment variable, no new logging framework, no change to
the canonical document, routing, locale or SEO contracts of 9A–9C.

## 1. Observability

- `Runtime\StudioRequestId` — one opaque random id (64 bits, hex) per PHP request, minted lazily. Never derived from
  client input (an inbound `X-Request-Id` is ignored), never a tenant/page/user/time identifier.
- `Runtime\StudioLog` — builds one line per failure and writes it through the existing `slate_log()`:

  `req=<id> studio.<component>.<action> failed: <Class> at <file>:<line> [code= reason= msg=] [<< <previous> …]`

  Class + **basename**:line of where it was raised; the previous-exception chain (bounded) so a wrapper such as
  `StudioRenderException` no longer hides the real cause; `code`/`reason`/`msg` only for Studio's own exceptions (authored
  text, cleaned and capped); `sqlstate=` for `PDOException`. Messages of any other exception are never logged (they can
  carry SQL, paths, row data). Labels are reduced to an identifier charset (no log-line forgery).
- Every former `slate_log('… ' . get_class($e))` call site in Studio now uses it: public runtime, authoring API, MCP
  adapter + handler, builder shell / preview / canvas, provider binding, dynamic slots. A unit test fails if a Studio
  file logs a class name or exception message directly again.
- Newly logged (were silent): a Studio exception that maps to 500 in the API, MCP adapter, builder shell, preview and
  canvas.
- Client side: a Studio **error** response carries `X-Request-Id` (public 500, authoring errors, every failed API
  response). The JSON body contract is unchanged. Cacheable successes never carry it.
- Operator workflow: `grep 'req=<id>' data/slate.log`.

## 2. Security-denial auditing

Audited (the platform `AuditLog`, action `studio.denied.<code>`), from `StudioAuthoringApi::handle()` *after* the
response is decided — never inside a Studio transaction (`AuditLog::record` can implicitly commit):

| Denial | Code | Extra meta |
| --- | --- | --- |
| signed-in user lacks permission / tenant scope | `authorization_error` | — |
| tenant not entitled to Studio | `entitlement_error` | — |
| CSRF token invalid, or cross-site fetch | `csrf_error` | `reason` = `token` \| `cross_site` |
| API rate limit | `rate_limited` | — |

Meta is exactly `status, method, action, request_id` (+ `reason`, + `suppressed_since_last`). No body, header, token,
cookie or document content. Throttled per class per 60 s per session (`StudioDenialAudit`), with the skipped count on
the next row, so a refused client cannot grow the audit table at request rate.

Intentionally **not** audited: 401 (anonymous volume, no actor), 404 / 405 / 413 / 415, validation 422, concurrency
409 (client mistakes, not security signals), read-only preview/canvas refusals (403/404 on GET), and public 404s
(anti-enumeration: Studio must not behave differently for a page that is not public).

MCP / AI policy denials needed nothing new: `McpGatewayAPI::dispatch()` already audits every blocked attempt and every
failed call (`mcp-gateway.<tool>.failed`), including Studio's missing-scope, tenant-scope, rate-limit and
publish-not-offered refusals.

## 3. Public response headers

- `Content-Language` = the tenant's public locale (`StudioPublicLocale::resolve()` — Phase 9A), on HTML pages and their
  304s, never from the visitor; omitted (not malformed) if the value is not a plain language tag. Not on
  `robots.txt` / `sitemap.xml`.
- `Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()` on public HTML only. Studio
  output cannot contain script/iframe/embed/media (`FieldSchema`), so nothing it renders uses these.
- **CSP deferred.** A safe policy cannot be specified: Studio emits an inline `<style>` (theme tokens, no nonce) and
  `style=` attributes, and multilang-translate's output buffer injects an inline `<script>`/`<style>` switcher *after* Studio
  renders, so neither `script-src` nor `style-src` could be nonce-ed or tightened without breaking the language switcher
  or tenant theming. Framing is already controlled by `frame-ancestors 'self'` + `X-Frame-Options` (`config.php`).

## 4. Session cookie vs public cache

`config.php` starts the PHP session on every web request, so a first-time visitor to a public Studio page received
`Set-Cookie: SLATE_SID` on a `Cache-Control: public, no-cache` response (verified against a real PHP web server).
`StudioHttpResponder::sendPublic()` now removes exactly the session cookie (other cookies untouched) before sending any
public response (200, 304, 500, SEO files). Studio's public output is anonymous by contract, so it never needs it; the
authoring endpoints are unchanged and keep their session. Session handling is otherwise untouched.

## 5. Runtime / deployment requirements (repository evidence)

- PHP floor is **8.2**: `src/Tenancy/ScopeContext.php` (both apps) is a `final readonly class` — a parse error on 8.1.
  CI runs 8.3. Every document now says 8.2+; none claims 8.1.
- CI asserts `mbstring` (and pdo_mysql, curl, json, openssl, sodium) right after `setup-php`, in the job that runs the
  Studio suites, with an actionable `::error::`. No polyfill was added.
- Nginx: `plugins/*/ui` needs a deny rule (Nginx ignores `ui/.htaccess`) — documented. The `INSTALL.md` deny regex was
  unterminated (`…|docs|Claude)`), which would 403 public Studio slugs such as `/docs-foo` or `/database`; it now ends
  `(/|$)` like `NGINX-SETUP.md`.
- `APACHE-SETUP.md` embeds the shipped `.htaccess` files verbatim (they had drifted: `.well-known`, `api/v1`).
- `/robots.txt` and `/sitemap.xml` reach `public.php` on Apache and Nginx; no static copy may exist (a real file wins);
  the bare slugs `robots`/`sitemap` are reserved and `.` is not a slug character.
- `02-licensing/config.php` `SLATE_URL` no longer defaults to a hard-coded live host (same fail-closed rule as 9B).

## 6. Verified but not changed

`display_errors` is forced off; `X-Forwarded-Proto` is used only for the HTTPS decision (secure cookie, HSTS);
client IP comes from `REMOTE_ADDR` in Auth; `APP_SECRET` empty fails closed through `slate_app_secret()`; `TENANT_ID`
defaults to 0 on the client. `APP_ENV=testing` is a locked test bypass (D19): documented as never-for-production.

## 7. Deferred (recorded, not done)

- Licence signature verification falls back to `hash_hmac(sha256, payload, publicKey)` when ext-sodium is missing —
  forgeable by anyone holding the public key (flagged by the repo's own anti-drift HMAC rule). Docs now say sodium is
  required; failing closed is a licensing-behaviour change for a decision. **Resolved after Phase 9D:** every HMAC
  path was removed and sodium is enforced (see CHANGELOG, Unreleased → Security).
- Nginx guide has no equivalent of the Apache `api/v1` → `api/v1.php` rewrite (untested here; not Studio).
- `FormsAPI::clientIp()` trusts `CF-Connecting-IP` / `X-Forwarded-For` (forms plugin, spoofable).
- Release zips still ship `plugins/studio-builder/ui/` (sources); now denied on both servers, could also be excluded
  from the package.
- Anonymous public hits still create a server-side session file (cookie no longer sent); avoiding the session start
  for public Studio pages is a platform change.
- CSP, hreflang, JSON-LD, localized content, public rate limiting, slug redirects, SEO-field denylist, media
  invalidation — unchanged (Phase 10 / later).
