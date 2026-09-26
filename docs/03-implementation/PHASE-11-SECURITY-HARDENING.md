# Phase 11 — Security Hardening

**Status:** Implemented — awaiting Antigravity verification
**Scope rule:** audit first; fix only confirmed gaps; no protocol redesign. Locked
behaviors (1 License → 1 Installation, signed installation-bound state, 7-day
offline tolerance, 7-day commercial grace, central authority, Global License
Guard, Module Guard, API contract) are unchanged.

---

## 1. Existing protections confirmed (Phase 9/10 — unchanged)

| Protection | Where |
| :--- | :--- |
| Ed25519 signature verified at write time and re-verified on every read; only signed bytes are trusted; stored columns must equal the signed payload | `SlateLicenseCacheStore::readTrustState()`, `save()` |
| Installation binding: signed `installation_id` must `hash_equals` the local identity, write- and read-time | `RemoteLicenseClient.php:187-190`, `SlateLicenseCacheStore.php:104,123` |
| Monotonic `checked_at`: an older signed payload is refused (`stale_response`) under a `FOR UPDATE` row lock | `SlateLicenseCacheStore.php:209-211` |
| Effective `fetched_at` = `min(local write time, signed checked_at)` — a database edit can only age a snapshot | `SlateLicenseCacheStore.php:132` |
| Fail-closed Guard; exact `SCRIPT_NAME` whitelist; no request-path bypass found | `includes/license_guard.php` |
| Central admin: `Auth::require()` + `requirePerm('licensing.manage')`, POST-only + CSRF on every state change; installation revoke checks license ownership; check-in checks installation↔license | `02-licensing/plugins/licensing/admin/*`, `InstallationService`, `LicensingAPI` |

Pinned by `Phase11SecurityHardeningTest` ("Phase 11 existing: …").

**Replay:** a genuine signed payload replayed into an empty cache (MITM or DB write
access) is trusted no longer than 7 days after its *signed* `checked_at` — the
same exposure the approved offline tolerance already accepts. Not a separate
vulnerability; it becomes unbounded only in combination with clock rollback (§4 A).

## 2. Confirmed vulnerabilities and fixes

| ID | Sev | Finding | Fix |
| :--- | :--- | :--- | :--- |
| V1 | P1 | Booking entry points checked "plugin active", never the entitlement: `booking/public/pay-intent.php`, `prereq.php`, `message.php`, `gcal-webhook.php`, `customer/book.php` (the last named in 07 §2). Nothing deactivates a plugin when its entitlement is removed, so this is the normal post-downgrade state. | Module Guard at each entry, per 07 §4: public pages 404; pay-intent JSON 404; gcal webhook still `200 ok` but no sync (07 §2 webhook rule); customer portal 403 after `requireCustomer()` (Membership portal convention) |
| V2 | P2 | Booking/Forms/Membership content blocks rendered an unentitled module's public surface on any page | `renderContentBlock()` returns `''` unless `ModuleGuard::allows()` |
| V3 | P3 | MCP `slate_business_report` read unentitled modules' data | Unentitled module sections are `null` (same as not installed) |
| E | P1 (availability) | Under a `/subdir/` deployment (the default `APP_URL`), `SCRIPT_NAME` carries the base path, so the whitelist never matched: a locked install could not reach login, the License page, or cron (whose 403 also leaked lock state before the secret check). Fail-closed, not a bypass; fixed with explicit approval. | `slate_license_guard_strip_base()` removes only the exact configured `APP_URL` path segment before the whitelist match |

## 3. Audited, no change

- **Installation ID:** canonical `/^[a-f0-9]{32}$/` applied consistently at every boundary; every production `RemoteLicenseClient` caller passes the already-validated `InstallationService::currentInstallationId()`. The regex is duplicated as private constants, but no copy is inconsistent; no new validator added.
- **`APP_ENV=testing` bypass:** locked decision D19.
- **`.env` line injection via recovery key:** blocked — a key containing a newline fails the central hash lookup before `.env` is written.
- **Central admin (all require `licensing.manage`, no auth/CSRF/IDOR gap):** legacy `admin/installs.php` `set_status` / `admin/install.php` `expires_at` bypass the lifecycle state machine and events; `set_modules`/`update` allowed on revoked licenses and audited only in `AuditLog`; `revoke_installation` writes no license event; `LicenseService::requireStatus()` reads without `FOR UPDATE` (concurrent-admin race); single-tenant assumption of the central install is documented, not enforced. Recorded for a later lifecycle/audit pass.
- **Coaching / backups cron** run while locked: not licensed modules (by design).

## 4. Deferred — architectural decisions (not implemented)

**A. Clock rollback (08 §7, F-P9-02).** "Now" is always PHP `time()`, passed as
a parameter to `CommercialLicenseWindow::evaluate()`; `fetched_at` is the local
clock (capped by signed `checked_at`); `verified_at` is MySQL `NOW()` and unused
for decisions; there is no persisted high-water mark or monotonic clock, and no
time state survives a PHP restart except these columns. A negative age
(`now < fetched_at`) is currently treated as fresh. No robust offline mitigation
exists: an operator controlling both clock and database can freeze time inside
the 7-day window. Candidate for a future decision: lock when `now < fetched_at`
(threshold-free; detects rollback to before the last check-in, not a freeze).
**Decision: deferred, documented.**

**B. Server-side replay window (R9).** The central server never reads the
request's `checked_at`. A tolerance window needs a chosen value, would reject
clients with skewed clocks, and cannot stop a replayer while requests are
unsigned (R8 deferred). **Decision: deferred with R8.**

**C. Rate limiting (R10).** The client IP is `REMOTE_ADDR` only; proxy headers
are never trusted and no trusted-proxy model exists, so an app-level IP limiter
would collapse every client behind a CDN/load balancer into one bucket;
`install_id` is attacker-chosen. License-key brute force is infeasible (80-bit).
Residual: the legacy check-in path writes a `licensing_checkins` row per request
carrying a valid legacy key, with no retention. **Decision: rate limiting at the
infrastructure layer (reverse proxy / WAF) in front of `POST /licensing/check`.**

**D. Signing-key rotation (R11).** One Ed25519 keypair; client trusts a single
`LICENSE_SERVER_PUBLIC_KEY`; no key ID in envelope or payload. The client ignores
unknown envelope/payload fields, so the smallest future-compatible extension is
client-side only: accept a list of trusted public keys and try each — no wire or
central change, Phase 10 compatible. **Decision: deferred, documented;
verification behavior unchanged.**

## 5. Tests

- `01-client/tests/integration/Phase11SecurityHardeningTest.php` (15 tests), with
  fixtures `phase11-surface-probe.php` and `phase11-content-block-probe.php`.
  Every fix test was confirmed to fail against the pre-fix code.
- Runner: `php 01-client/tests/run-phase11-security.php` (Phase 11 + Phase 3–10
  licensing suites + touched Booking suites; 170/170). Added to CI.
- `tests/fixtures/mcp-tool-call-probe.php`: `require` → `require_once` so it also
  works when the mcp-gateway plugin is active.
