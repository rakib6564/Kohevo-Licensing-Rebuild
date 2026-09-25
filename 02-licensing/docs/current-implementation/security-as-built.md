# Current Implementation — Security (As-Built)

**Status:** Living reference · **Describes:** the security controls implemented
today. Everything here is a [load-bearing behavior](load-bearing-behaviors.md) —
**do not regress it** during the refactor.

The current baseline is genuinely solid. This is the inventory of what exists, so
Phase 1+ preserves each control while the code around it moves.

---

## 1. Authentication

- **Password hashing:** bcrypt via `password_hash` with **auto-rehash**
  (`password_needs_rehash` on login → upgrades cost transparently).
- **Two flows** (admin `users`, customer `customers`) with the same hardening.
- **Throttling/lockout:** per-client-IP failed-attempt counting in a lazy
  `login_attempts` table; both flows lock out (defaults ~10 attempts / 15 min,
  from Security settings).
- **Enumeration mitigation:** the no-such-user path runs a **dummy
  `password_verify`** so response timing doesn't reveal account existence.
- **Email verification is NOT a login gate** — unverified customers log in (soft
  banner). `email_verified` must never be used as authorization.
- **SMTP OAuth** support (`includes/SmtpOAuth.php`, `admin/oauth_callback.php`).

## 2. Authorization (RBAC)

- Permissions are `<domain>.<action>` keys; roles bundle them per tenant.
- **Super Admin (role/user id 1) short-circuits** to all permissions and is
  read-only in the editor.
- Permission set = **union of core keys + active-plugin manifest keys**
  (`Auth::knownPermissions()`), checked via `Auth::can()` / `Auth::requirePerm()`
  at the top of each admin page.
- **Self-protection guards:** cannot demote/suspend/delete yourself or the last
  Super Admin.
- Gap: flat role→permission only (no resource/ownership scoping yet) — a target
  ([../10-Security/authorization-rbac.md](../10-Security/authorization-rbac.md)).

## 3. Session handling

- PHP sessions started in `config.php` (`Auth::startSession()`).
- **Idle timeout** enforced (`session_timeout_minutes` Security setting).
- **Force-HTTPS + HSTS** when `force_https` is on: 301 to https + `Strict-Transport-
  Security: max-age=31536000; includeSubDomains` (skipped on CLI/pre-install).
- Cookies over HTTPS when forced; logout requires a CSRF token (below).

## 4. CSRF

- Token-based, **timing-safe compare**, on state-changing POSTs.
- **Logout now requires + verifies a CSRF token** (was previously a bare GET —
  fixed).

## 5. XSS protection

- Consistent output escaping via the `e()` helper across admin, portal, and
  storefront templates.
- Raw output is the explicit exception, not the default.
- **SVG upload disabled** (script-carrying vector) for logos/media.

## 6. SQL-injection prevention

- **PDO prepared statements throughout** (`Database` with emulation **off**).
- No string-concatenated SQL with user input in the reviewed paths; the query
  helpers (`query/row/rows/value/insert/update/delete`) parameterize.

## 7. CSP & security headers

- **HSTS** emitted under force-HTTPS (§3).
- `.htaccess` provides baseline hardening + `mod_headers`.
- **No strict Content-Security-Policy is set today** — a known gap; the target
  ([../10-Security/README.md](../10-Security/README.md)) adds CSP + the full header
  set (X-Frame-Options, X-Content-Type-Options, Referrer-Policy).

## 8. Encryption

- **Secrets encrypted at rest** with **AES-256-GCM** (`enc:v1:` envelope) via
  `slate_encrypt_secret()` / `slate_decrypt_secret()`, keyed by `APP_SECRET`.
- Legacy plaintext secrets are read transparently and **re-encrypted on next
  save**.
- **Stripe publishable key stays plaintext by design** (it's public); secret +
  webhook keys are encrypted.

## 9. File-upload security

- **MIME validation** on uploads (real content check, not just extension).
- Upload directories get a **PHP-off `.htaccess`**; `data/`, `db/`, `docs/`,
  `includes/` get `Require all denied` (root rules don't match under a `/subdir/`
  deploy).
- `route.php` is a rewrite **trampoline** keeping `/includes/` 403.
- Booking uploads (`uploads/booking/`) get PHP-off `.htaccess` + real MIME check.

## 10. Rate limiting

- **Login** throttling per IP (§1).
- **Public form submissions** rate-limited per IP (~5/min) + honeypot.
- No general per-route API rate limiter yet (no REST API yet).

## 11. Outbound-request safety (SSRF)

- **Forms webhooks** (and the `migrate-images` CLI) refuse non-http(s) schemes and
  **private/loopback/link-local/reserved IPs**, and **pin curl to the vetted IP**
  (DNS-rebinding-safe).

## 12. Payment & data-integrity safety

- Captured amount **reconciled** vs rebuilt order total (mismatch → `on-hold`).
- Charges ledger **unique keys** on `(tenant_id,payment_intent_id)` /
  `(tenant_id,session_id)` (no double-insert on concurrent webhooks).
- `return.php` bound to buyer `shop_sid`; booking `/book/done` verifies
  `metadata.booking_appt_id`.
- Stripe webhook rejects **too-old and future-dated** timestamps.
- Atomic gift-card debit; atomic coupon `max_uses`; slot engine `SELECT … FOR
  UPDATE`.
- Shop order numbers from auto-increment id; order pages view-token-protected
  (anti-enumeration).

## 13. Other hardening

- **Open-redirect guard:** `slate_safe_redirect_target()` rejects `//evil.com` /
  external `next=` on both login pages.
- **CSV formula-injection** neutralized on exports (shop products, form
  submissions).
- **Tenant isolation** on sensitive plugin paths (e.g. shop variant CRUD verifies
  parent product tenant; shipping-flat-rate scoped) — but **general tenant scoping
  is manual** and inconsistent (the main open risk, §14).

## 14. Known security gaps (open)

1. **Manual tenant scoping** — a forgotten `AND tenant_id = ?` is a cross-tenant
   leak (the highest-value gap; closed by the target Repository, Phase 1).
2. **No CSP** and partial security-header coverage.
3. **No 2FA** (the dead toggle was removed).
4. **Flat RBAC** — no resource/ownership-scoped authorization.
5. **Debug scripts in tree** (`_*.php`, `admin/diag.php`, `opcache-reset.php`,
   `repair-settings.php`) — production-hygiene liability.
6. **`.env` on disk** with live secrets (git-ignored; keep out of ZIPs).

---

## What Phase 1+ must preserve

Every control in §1–§13 is preserved through the refactor; §14 gaps are addressed
per [../10-Security](../10-Security/). Auth/RBAC move into `Slate\Services\Auth`/
`\Rbac` behind aliases (Phase 1/3); the manual-scoping gap closes when queries move
to the tenant-scoped Repository. **No security behavior regresses without an ADR.**

---

## Related

- [gotchas-and-preservation-notes.md](gotchas-and-preservation-notes.md) · [load-bearing-behaviors.md](load-bearing-behaviors.md)
- Target: [../10-Security/README.md](../10-Security/README.md) · [../10-Security/authentication.md](../10-Security/authentication.md) · [../10-Security/authorization-rbac.md](../10-Security/authorization-rbac.md)
