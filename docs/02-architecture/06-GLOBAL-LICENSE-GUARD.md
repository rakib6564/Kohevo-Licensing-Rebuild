# Phase 1: 06 — Global License Guard

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §5, `DECISIONS.md` §7, §8, `docs/01-audit/03-LICENSING-AUTH-AUDIT.md`, `06-LICENSING-SECURITY-AUDIT.md`, `08-LICENSING-MIGRATION-RISKS.md` §2.5, §2.6

---

## 1. What Is Blocked

Without a valid license (accounting for the warning and grace windows defined in `08-EXPIRY-GRACE-OFFLINE.md`), the Guard blocks:

- All Core routes: Dashboard, Admin/User, Site Settings (`admin/index.php`, `admin/users.php`, `admin/roles.php`, `admin/settings.php`, and every other direct admin script not on the whitelist)
- All Optional-module routes, admin and public (`plugins/forms/`, `plugins/membership/`, `plugins/booking/` — admin, public, customer portal, and API surfaces)
- All API routes (`api/v1.php` / `ApiRouter`)
- All AJAX endpoints (`admin/notifications-poll.php`, `admin/notifications-read.php`, `admin/editor-preview.php`, etc.)
- The customer portal (`customer/portal_router.php`)
- Application-level background hooks that perform module work (`frequent_cron`, `daily_cron` listeners registered by plugins) — see §5

This is `TARGET`; today only `index.php` and `public.php` invoke any gate at all (`06-LICENSING-SECURITY-AUDIT.md` finding 2.10), and even that gate is bypassed by any authenticated admin (finding 2.7) or by simply omitting licensing env vars (finding 2.12).

## 2. What Remains Accessible

Per `REQUIREMENTS.md` §5 ("Only the minimum routes/services required for installation, licensing, activation, and recovery of the licensing state may remain accessible") and `08-LICENSING-MIGRATION-RISKS.md` §2.5's deadlock warning, the whitelist is:

| Surface | Reason | Status |
| :--- | :--- | :--- |
| `install.php` (until `.installed` exists) | Cannot license what has not been installed | `MISSING` — must be added explicitly, was never a licensing concern before |
| Static assets (`/assets/*`, CSS/JS/images/fonts) | The lock screen itself and the installer need to render | `MISSING` — currently assets have no gate at all (which is currently *fine* by accident, since nothing gates them; the Guard must preserve this openness rather than newly restrict it) |
| The client's outbound check-in call target is irrelevant here — that is the client *calling out*, not an inbound route | N/A | N/A |
| A "License Locked" recovery screen (e.g. `admin/license.php` equivalent) reachable to a logged-in admin, allowing them to view status, re-enter/update a license key, and trigger a manual re-check | This is the "recovery" surface `REQUIREMENTS.md` §5 explicitly carves out — without it, a locked installation has no way to become unlocked short of direct database access | `MISSING` — does not exist today in any form |
| `admin/login.php` (the login form itself, not what it leads to) | An admin must be able to authenticate in order to reach the License Locked recovery screen at all — authentication and licensing are independent gates (`06` §1's flow: Guard → Auth → Module Guard), and blocking login would make the recovery screen unreachable | `MISSING` — today login is reachable, but this must be an explicit whitelist decision going forward, not an accident of "the gate just isn't called yet" |
| `admin/logout.php` | An admin who is currently logged in under an unprivileged (non-`licensing`-capable) account when the system locks must still be able to log out in order to re-authenticate as an account that *can* reach the License Locked recovery screen — without this, such an admin is stuck logged in as an account that can do nothing and cannot switch identity | `MISSING` — resolves Finding F-07 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md` (`P3 — LOW`); omitted from an earlier draft of this whitelist |
| The license refresh/check-in cron trigger | Must keep running even while locked, so a renewal on the Central Server can be picked up and lift the lock without waiting for a human to intervene | `MISSING`, and explicitly flagged as a risk in `08-LICENSING-MIGRATION-RISKS.md` §2.6 |
| `cron.php`'s `CRON_SECRET` verification itself | The secret check happens before any licensing concern and must not be gated (it is the entry point's own authentication, unrelated to product licensing) | `EXISTING`, unaffected |
| Automated test execution and migration-runner execution (§3a) | A fail-closed Guard bootstrapped from `config.php` would otherwise crash every PHPUnit run and every `db/migrate.php` invocation against an empty test/CI database, which is not a licensing concern at all | `MISSING` — resolves Finding F-05 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md` (`P2 — MEDIUM`) |

**Everything else is blocked.** This is a deliberately short, explicit whitelist (`DECISIONS.md` §8: "This lock must be enforced globally rather than separately implemented only on selected pages") rather than a blocklist, because a blocklist silently fails open for anything the implementer forgot to add — exactly the failure mode Phase 0 found repeatedly (`06-LICENSING-SECURITY-AUDIT.md` findings 2.1–2.5).

## 3. Route / Controller / Service / API / AJAX / Background Behavior

The client codebase has no central front-controller or middleware pipeline — it is a flat collection of direct PHP entry points (`01-LICENSING-CURRENT-STATE.md` §2.2, §3, verified directly against `config.php`). This constrains *where* the Guard can be invoked to one of two realistic options, both consistent with the codebase's existing conventions:

**Option A — Guard call added to `config.php` itself**, since every entry point (`admin/*.php`, `public.php`, `index.php`, `api/v1.php`, `customer/*.php`, `cron.php`) already begins with `require_once .../config.php` (`EXISTING`, verified). A single `slate_license_guard()` call placed near the end of `config.php` (after `Database`, `Auth`, `PluginLoader::boot()` are available, since the Guard needs all three) would apply to literally every entry point automatically, with the whitelist (§2) implemented as an early-return inside that function based on the current script path (`$_SERVER['SCRIPT_NAME']` / `basename(__FILE__)` equivalent, the same technique `slate_maintenance_gate()`/`slate_license_gate()` already use for their own scope decisions).

**Option B — Guard call added individually to each entry-point category** (mirroring how `Auth::require()` is currently called individually in every admin script), which is more consistent with the codebase's existing "each script declares its own requirements" convention but is exactly the pattern that produced today's gap (`MOD-01` through `MOD-05`: it is trivial to add a new admin script or API route and simply forget to add the check, which is precisely what happened with every optional module).

`RECOMMENDED`: **Option A**. A single mandatory choke point in `config.php` cannot be "forgotten" by a future developer adding a new admin page, the same way `Auth::startSession()` and `PluginLoader::boot()` already cannot be forgotten because they are not something each script opts into. This directly addresses `06-LICENSING-SECURITY-AUDIT.md` finding 2.10 ("Missing Global Middleware / Central Gate") at its root cause rather than patching each current entry point individually and leaving the same gap open for the next one added in Phase 7+.

This is a `RECOMMENDED` implementation strategy, not a locked decision — `15-PHASE-1-DECISIONS.md` records it as open for confirmation, since it is the kind of cross-cutting change `CLOUD-AGENT-RULES.md` §3 asks to flag rather than assume.

## 3a. Testing and Migration Execution-Context Bypass

`TARGET`, `LOCKED` — resolves Finding F-05 of `16-PHASE-1-ANTIGRAVITY-REVIEW.md` (`P2 — MEDIUM`, "Global Guard Choke Point Lacks Test & Migration Bypasses"). Option A's single choke point in `config.php` (§3) is correct, but applied naively it introduces a new failure mode that does not exist today: `config.php` is also the bootstrap for PHPUnit test runs and for `db/migrate.php`'s schema-migration runner, both of which routinely execute against a database that has no `licensing_installations`/`remote_license_cache` row at all (a fresh test database, or a migration being applied for the first time). Under strict fail-closed (§7), `slate_license_guard()` would treat that absence as "no valid license" and lock — crashing every test run and every deployment migration step, neither of which is a commercial-licensing concern.

The fix is a narrow, explicit, non-production execution-context exception, checked *before* any license-state lookup:

```php
function slate_license_guard(): void {
    if (defined('SLATE_MIGRATING') || defined('SLATE_TESTING') || env('APP_ENV') === 'testing') {
        return; // not a licensing decision — this request never represents a real deployment's runtime traffic
    }
    // ... normal fail-closed license evaluation follows, unchanged
}
```

This is deliberately **not** a general-purpose escape hatch:

- `SLATE_MIGRATING` and `SLATE_TESTING` are PHP constants `define()`d only by the migration runner's and the test harness's own bootstrap scripts respectively — they are not derived from any request-controllable input (query string, header, cookie, POST body), so an attacker cannot set them by crafting a request.
- `APP_ENV=testing` is read from `.env`/environment configuration, the same trust boundary every other environment-dependent behavior in this codebase already relies on (`force_https`, debug-mode toggles) — not from anything a request can influence.
- None of the three bypasses apply to `APP_ENV=production` or `APP_ENV=development` — a production or ordinary dev deployment gets the full fail-closed Guard exactly as specified in §7, with no exception.

This bypass is analogous in spirit to the offline-tolerance exception `08-EXPIRY-GRACE-OFFLINE.md` §4 already carves out of fail-closed — both are bounded, deliberately-scoped relaxations, not a reintroduction of the "unconfigured means unrestricted" bug (§7) they exist alongside. See §7 for how this fits the Guard's overall fail-closed posture.

## 4. Installer Access

Covered in `05-INSTALLATION-ACTIVATION.md` §9. Summarized: whitelisted only until `.installed` exists; once installed, `install.php` reverts to its `EXISTING` self-protecting behavior (halts if `.installed` present) — the Guard does not need to duplicate that protection, only avoid interfering with it.

## 5. Cron Behavior

Two different concerns must not be conflated (this is itself an instance of the "don't confuse commercial grace with network tolerance" principle from `REQUIREMENTS.md` §10.3, applied to background jobs):

1. **The license refresh check-in itself** (`01-client/bin/license-check.php` → `RemoteLicenseClient::checkIn()`) — must run **unconditionally**, regardless of current lock state, because it is the mechanism by which a lock gets lifted after renewal. `MISSING` today as an explicit whitelist rule (today it runs unconditionally too, but only because *nothing* gates `cron.php` at all — the target must preserve "always runs" deliberately, not accidentally, once the Guard exists).
2. **Plugin background hooks** (`frequent_cron`, `daily_cron` listeners — e.g. Booking's `sendReminders()`) — must be silenced when the license is fully locked, and specifically must be silenced per-module when that module's *entitlement* is missing even if the license itself is otherwise valid (e.g., a license without the `booking` entitlement must not send booking reminder emails even while Core remains accessible). This is `MISSING` today (`MOD-05`: "Booking background cron sweeps run without entitlement check") and is addressed by the Module Guard (`07-MODULE-GUARD-ARCHITECTURE.md` §5), not the Global Guard — the Global Guard's job is only the binary "is there any valid license at all" check; per-module suppression is a Module Guard concern layered on top.

This resolves `08-LICENSING-MIGRATION-RISKS.md` §2.6 ("Background Job Zombie Execution Risk") directly.

## 6. CLI Behavior

`ASSUMPTION`: no CLI entry points beyond `cron.php` currently exist in the audited codebase that would need distinct Guard treatment (`REQUIRES VERIFICATION` — Phase 0's audit did not explicitly enumerate a `bin/` directory beyond `license-check.php`; if other CLI scripts exist under `01-client/bin/`, each should be classified against §2's whitelist individually before Phase 6 implementation). `bin/license-check.php` itself is the license-refresh mechanism from §5.1 and is whitelisted by definition — it is not "a CLI script that needs the Guard's permission to run," it is *part of* the Guard's own supporting machinery. A future migration-runner CLI (`db/migrate.php`) is covered separately and unconditionally by the `SLATE_MIGRATING` bypass (§3a), not by this per-script classification process — it does not need to be individually added to §2's whitelist because it never reaches the Guard's whitelist check at all.

## 7. Fail-Closed Behavior

This is the single most important correction relative to the current system. Today, `slate_license_gate()` fails **open** in two distinct ways (`06-LICENSING-SECURITY-AUDIT.md` finding 2.12, `03-LICENSING-AUTH-AUDIT.md` §5.2):

```php
// Current: unconfigured installs pass through unrestricted
if ($cached === null) {
    if (!$remoteMode) return; // explicit legacy/unconfigured path
    ...
}
```

and (finding 2.7, `03-LICENSING-AUTH-AUDIT.md` §5.1):

```php
// Current: any authenticated admin bypasses the gate entirely
if (class_exists('Auth') && Auth::check()) return;
```

**Target:** both of these must be removed as blanket bypasses.

- **Unconfigured means locked, not unrestricted.** An installation with no licensing configuration present (which, per the new installer in `05`, should not be reachable for a *new* installation at all — Step 4 blocks progress without one) must default to FULL LOCK, not full access. Given the target installer removes any path to "installed but never licensed," this scenario should only arise from local/dev environments deliberately running without the Guard configured, or from a corrupted local state — either way, `REQUIREMENTS.md` §5's "no valid license → fully locked" is unconditional and admits no "unless unconfigured" exception.
- **Admin sessions no longer bypass the Guard.** An authenticated admin is still subject to the Global License Guard exactly like any other request — the *only* concession `REQUIREMENTS.md` §5 implies is necessary is that the admin must still be able to **log in** (§2, whitelisted) and reach the **recovery screen** (§2, whitelisted) to fix the licensing problem themselves. This is a narrow, explicit whitelist entry, not a role-based bypass of the entire Guard — directly resolving `AUTH-02`.

Fail-closed also governs the Guard's own error handling: if the Guard cannot determine license state at all (e.g., a database exception while reading the local cache), the target behavior is to **lock**, not to catch-and-pass-through the way `slate_license_gate()`'s current `catch (\Throwable $e)` block does when `remoteConfigured()` is false (`error_page.php` lines 233-240). There are exactly two narrow, deliberate, separately-justified exceptions to this — neither is a general exception-swallowing pass-through, and both are bounded to non-production-traffic scenarios or a specifically time-boxed window, never to "we're not sure, so let it through":

1. The offline/network-tolerance window itself (`08-EXPIRY-GRACE-OFFLINE.md` §4) — a bounded relaxation for a real production installation that has a genuinely-verified, not-yet-stale signed state to fall back on.
2. The testing/migration execution-context bypass (§3a) — applies only when `SLATE_TESTING`/`SLATE_MIGRATING`/`APP_ENV=testing` is true, none of which describe real end-user production traffic.

## 8. Bypass Prevention

Cross-referencing every bypass vector Phase 0 identified in `06-LICENSING-SECURITY-AUDIT.md` §2, and how the target Guard design closes each:

| Vector | Phase 0 Finding | Target Closure |
| :--- | :--- | :--- |
| Direct URL access (admin scripts) | 2.1 | Guard invoked from `config.php` (§3), applies before any admin script's own logic runs |
| Direct route access (optional modules) | 2.2 | Same — Global Guard blocks first; Module Guard (`07`) additionally required for these specific routes |
| Direct API calls | 2.3 | Guard applies to `api/v1.php` same as any other entry point under Option A |
| Direct AJAX endpoints | 2.4 | Same |
| Controller/service invocation | 2.5 | Out of Global Guard's scope by design — this is what the Module Guard's *service-layer* placement addresses, see `07` §2; the Global Guard only protects entry points, not every internal function call, which matches how `Auth::require()` already works (it protects entry points, not arbitrary internal methods) |
| Manipulated request parameters / legacy compat env flags | 2.6 | The target Guard reads only the verified local license state (`10-CLIENT-LICENSING-DATABASE-DESIGN.md`), never an environment flag that changes *authority mode*; whether `LICENSE_COMPAT_MODE` is retained at all for the new architecture is addressed in `13-MIGRATION-STRATEGY.md` / `14-BACKWARD-COMPATIBILITY.md` |
| Admin session pass-through | 2.7 | Removed, §7 above |
| Local database tampering of cached state | 2.8 | Not the Global Guard's job directly — solved by making the cache tamper-evident (`10` §3) so the Guard reads a value it can trust; this is `RECOMMENDED` layered with the Guard, not a Guard responsibility alone |
| UI hiding / disabled menu items | 2.9 | Explicitly rejected as a security mechanism throughout this document set (`DECISIONS.md` §7) — UI state is presentational only |
| Missing global middleware | 2.10 | §3, Option A |
| Background operations | 2.11 | §5 |
| Fail-open unconfigured state | 2.12 | §7 |
| Test/migration/CLI deadlock (Finding F-05) | `16-PHASE-1-ANTIGRAVITY-REVIEW.md` F-05 | §3a — bounded, non-request-controllable bypass constants |
| Admin unable to log out to switch accounts while locked (Finding F-07) | `16-PHASE-1-ANTIGRAVITY-REVIEW.md` F-07 | §2 whitelist, `admin/logout.php` |

## 9. Interaction with the License Locked Recovery Screen

The recovery screen (§2) is itself protected by `Auth::require()` (an unauthenticated visitor must not be able to attempt to re-key a license — this would let anyone probe license validity) but is explicitly whitelisted from the Global License Guard itself, since its entire purpose is to be reachable *while* the Guard would otherwise block everything else. This is not a contradiction: the Guard's whitelist (§2) and the Auth layer (`01-TARGET-ARCHITECTURE.md` §4's second box) are independent, stacked checks — the recovery screen requires Auth but not License; every other admin route requires both.
