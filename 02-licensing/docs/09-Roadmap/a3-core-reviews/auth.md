# Design Review — `Auth`

**Source:** `includes/Auth.php` (744 lines) · **Callers:** 160 files · **No code.**

Security-critical and the clearest **SRP violation** of the four — seven
responsibilities in one static class. Migrate **whole** now (behavior-identical);
split in Phase 3.

---

## 1. Current implementation

**Responsibilities (7)**
1. **Session lifecycle** — `startSession`, `enforceIdleTimeout` (idle-timeout drop).
2. **Admin authentication** — `attemptLogin`, `logout`, `check`, `user`, `userId`,
   `roleId`, `isSuperAdmin`, `require`.
3. **Authorization / RBAC** — `can`, `requirePerm`, `invalidatePermCache`,
   `corePermissions`, `knownPermissions` (+ per-request `$permCache`).
4. **Login throttling** — `loginBlockedSeconds`, `recordLoginFailure`,
   `clearLoginFailures`, `throttleConfig`, `clientIp`, `dummyVerify`,
   `ensureLoginAttemptsSchema`, `lastLoginWasThrottled`.
5. **Customer authentication** — `attemptCustomerLogin`, `customer`, `customerId`,
   `requireCustomer`, `logoutCustomer`, `registerCustomer`.
6. **Customer email verify + password reset** — `sendCustomerVerification`,
   `verifyCustomerEmail`, `sendCustomerPasswordReset`, `resetCustomerPassword`.
7. **Customer token management** — `ensureCustomerTokenSchema`, `issueCustomerToken`,
   `consumeCustomerToken` (SHA-256, single-use, TTL'd).

**Public API (high-traffic subset)** — `can` (118), `require` (113), `requirePerm`
(109), `isSuperAdmin` (44), `customer` (19), `customerId` (12), `user` (10),
`userId` (8), plus the login/registration/token methods.

**Internal dependencies**
- `Database::` (login queries, throttle table, tokens, settings) — **already migrated**.
- `Hook::doAction` (`user_logged_in/out`, `customer_logged_in`, `customer_registered`,
  `customer_email_verified`) — **already migrated**.
- `AuditLog::record` (customer register/verify/reset) — **already migrated**.
- `Mailer::send` (verification + reset emails) — **already migrated**.
- Globals: `e()`, `current_tenant_id()`, `slate_log()`; PHP `password_*`, `session_*`,
  `filter_var`, `hash`, `random_bytes`; consts `PASSWORD_DEFAULT`,
  `FILTER_VALIDATE_EMAIL`, `PHP_SESSION_NONE`, `SLATE_URL`.
- **No global class instantiations** (no `new PDO`, etc.) → mechanical to qualify.

**External callers** — 160 files. Virtually every `admin/*.php` opens with
`Auth::require()` + `Auth::requirePerm(...)`; plugins gate with `Auth::can(...)`;
customer portal uses the customer + token methods.

**Bootstrap order** — `config.php` calls `Auth::startSession()` right after loading
includes; `startSession()` → `enforceIdleTimeout()` → `Database::setting('session_timeout_minutes')`
(so Database must be live — it is, being required earlier). This is the earliest
DB-touching auth path.

**Lifecycle** — static, per-request. State: `$permCache` (role→perms),
`$customerTokenSchemaChecked`, `$loginAttemptsSchemaChecked`, `$lastLoginThrottled`,
`$dummyHash`. Two lazy `CREATE TABLE IF NOT EXISTS` paths (`login_attempts`,
`customer_auth_tokens`).

---

## 2. Coupling analysis

- **Who depends on it:** 160 files — the entire admin surface, the customer portal,
  and most plugins (authorization gates).
- **What it depends on:** Database, Hook, AuditLog, Mailer (all migrated), plus
  session superglobals and PHP crypto.
- **Circular dependency risk:** one **soft cycle** — `Auth::registerCustomer/verify/…`
  call `AuditLog::record`, and `AuditLog::record` calls `Auth::userId()` (guarded by
  `class_exists('Auth')`). Resolved lazily at call time via global aliases; **no
  load-order problem** (both are aliased globals). Worth noting, not blocking.
- **Hidden assumptions:**
  - **Throttling keys on `REMOTE_ADDR` only** (never `X-Forwarded-*`) — a security
    decision that must not change.
  - **`dummyVerify()` timing-equalisation** on the no-such-user path — preserving the
    enumeration mitigation depends on this staying on the exact code path.
  - `enforceIdleTimeout` drops **auth state** but keeps the session (so CSRF tokens
    survive on the login page) — subtle; must be preserved.
  - Password reset / registration return values are **deliberately non-committal**
    (don't leak account existence) — behavior, not a bug.
  - Super Admin (`role_id === 1`) short-circuits `can()` — never queries
    `role_permissions`.
- **Global state:** five static properties (caches + flags + dummy hash). All
  per-request, safe; the `$permCache` is exactly what a future Rbac service owns.

---

## 3. Migration strategy

- **Namespace / location:** `Slate\Services\Auth\Auth` → `src/Services/Auth/Auth.php`.
  *(Migrate whole — do not split; see SRP note.)*
- **Qualification:** `Database::` → `\Database::`, `Hook::` → `\Hook::`,
  `AuditLog::` → `\AuditLog::`, `Mailer::` → `\Mailer::`. `\Throwable` already
  qualified. Everything else is global functions/constants/superglobals (fallback).
  No `new <GlobalClass>` to qualify. Pattern is identical to `Media` (multi-class
  static qualification).
- **Alias strategy:** `class_alias(\Slate\Services\Auth\Auth::class, 'Auth');`. All
  160 callers keep `Auth::…`.
- **Bootstrap considerations:** `Auth::startSession()` runs very early in
  `config.php`. Since `aliases.php` is loaded before the `Auth::startSession()` call
  and `class_alias` autoloads the new class, the global `Auth` is ready when
  `config.php` calls it. Confirm the alias line is present **before** any `Auth::`
  use — it is (aliases load in the core-includes block, before session start).
- **Backward compatibility:** total; signatures unchanged; session keys
  (`slate_user`, `slate_customer`, `slate_last_activity`) unchanged, so **live
  sessions survive** the deploy.
- **Risk assessment:** **MEDIUM.** Code change is mechanical, but the surface is
  security-critical (sessions, throttling, password paths) and broad (160 callers).
  Risk is in *missing a `\` qualification*, which lint + a targeted grep catch.
- **Rollback strategy:** `git revert` the single commit. No schema/data change (the
  lazy `CREATE TABLE IF NOT EXISTS` behaviour is byte-identical). Existing sessions
  remain valid after revert.

---

## 4. Verification plan

- **Existing smoke coverage:** `class_exists('Auth')` is asserted; `config.php`
  boot (which calls `Auth::startSession`) runs in smoke — so a broken Auth breaks
  smoke immediately.
- **Additional checks required:**
  - Resolve check: `Auth` → `Slate\Services\Auth\Auth`.
  - `Auth::corePermissions()` and `Auth::knownPermissions()` return the expected
    groups (exercises `\Database::rows` on `plugins`).
  - `Auth::can()` on a non-logged-in principal returns `false`; super-admin
    short-circuit path returns `true` (unit-style with a faked session array).
  - `Auth::loginBlockedSeconds('admin')` runs (exercises the lazy `login_attempts`
    schema + `\Database`).
- **Runtime verification:** perform a **real admin login** in the browser and load a
  permission-gated page; perform a **customer login**; trigger a **password-reset
  email** (goes through `\Mailer` + `\AuditLog` + token issue) and confirm the
  emailed link verifies. Confirm **throttling** by exceeding the attempt cap and
  seeing the lockout.
- **Edge cases:** unknown-user login (must still `dummyVerify`), idle-timeout drop,
  reused/expired token (must fail), unverified customer can still log in (behavior
  preserved), pre-install path (no `settings` → `enforceIdleTimeout` bails).
- **Plugin compatibility:** every plugin admin page calls `Auth::require`/`requirePerm`/`can`
  — smoke boots active plugins; additionally load one gated page from booking,
  shop, and membership admin.

---

## 5. Future evolution (Architecture v1.0)

- **Service Container:** `Auth` becomes an injected `Authenticator` service (static
  facade retained via alias for BC).
- **RBAC → policy engine:** responsibility #3 moves to `Slate\Services\Rbac` with a
  single `can(principal, permission, resource?)` decision point supporting
  resource/ownership scoping ([10-Security](../../10-Security/authorization-rbac.md)).
- **Identity/Contacts (Phase 2):** responsibilities #5–#7 (customer auth, tokens,
  verification/reset) move onto the unified `contacts`/`identities` model; the
  customer becomes a `Contact` with an auth identity, not a `customers` row.
- **Notifications:** verification/reset emails become queued `NotificationChannel`
  sends instead of synchronous `Mailer::send`.
- **Tenant Context:** login queries and throttling scope via `TenantContext` instead
  of manual `current_tenant_id()`.
- **Capability Contracts:** `Slate\Contracts\Identity\IdentityStore` and an
  `Authenticator` contract with pluggable providers (password, OAuth, magic-link,
  TOTP).

### SRP note — **VIOLATION (7 responsibilities)**
Document now, split in **Phase 3** (never during A3):
- `Slate\Services\Auth` — Authenticator: session + admin/customer login + throttling.
- `Slate\Services\Rbac` — policy engine: `can`/`requirePerm`/`corePermissions`/`knownPermissions`.
- **Identity** — customer records, tokens, verify/reset (Phase 2 unification).
Splitting during the namespace move would break behavior-identical, one-commit
discipline and risk the security paths. Migrate as one aliased class; the split is a
deliberate later step with its own reviews + tests.

---

## Recommendation

Migrate **third** (after `Database`). Mechanical qualification like `Media`, but give
it a **full runtime pass** (real admin + customer login, reset email, throttling)
rather than smoke-only, because the affected paths are security-critical.
