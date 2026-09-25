# Phase 1: 07 — Module Guard Architecture

**Document Status:** Architecture Specification (Documentation Only — No Code Changes)
**Phase:** Phase 1 — Target Architecture Specification
**Reference:** `REQUIREMENTS.md` §3, §5, `DECISIONS.md` §5, §7, `docs/01-audit/05-LICENSING-MODULE-AUDIT.md`, `06-LICENSING-SECURITY-AUDIT.md`

---

## 1. Purpose

The Global License Guard (`06`) answers one binary question: "does this installation have any valid license right now?" The Module Guard answers a second, independent question, only for routes belonging to an optional module: "does the *current license's entitlement set* include this specific module?" Both must pass. A license that is valid but does not include `booking` must still block every Booking route even though the Global Guard passes.

This is `MISSING` in its entirety today — Phase 0 found **zero** calls to `EntitlementService::canAccess()` (the one function that already exists to answer exactly this question) anywhere in `plugins/forms/`, `plugins/membership/`, or `plugins/booking/` (`MOD-02`, `MOD-03`, `MOD-04`).

---

## 2. Guard Placement — Every Execution Path

`REQUIREMENTS.md` §5 and `DECISIONS.md` §9 both require enforcement at every layer a module can be reached from, explicitly rejecting "menu item hidden" as sufficient. Mapped against the codebase's actual entry points (verified per-module in `05-LICENSING-MODULE-AUDIT.md` §3):

| Layer | Forms | Membership | Booking | Guard Call Site |
| :--- | :--- | :--- | :--- | :--- |
| **Admin controller entry** | `admin/index.php`, `form_edit.php`, `submissions.php`, `contacts.php`, `spam_log.php`, `webhooks.php` | `admin/index.php`, `members.php`, `plans.php`, `subscriptions.php`, `perks.php`, `analytics.php`, `settings.php` | `admin/index.php`, `calendar.php`, `appointments.php`, `services.php`, `providers.php`, `schedules.php`, `customers.php`, `settings.php` | Immediately after each file's existing `Auth::require(); Auth::requirePerm(...)` pair — same call-site convention, one additional line: `ModuleGuard::require('forms')` (or `membership`/`booking`) |
| **Public routes** | `/forms/<slug>` via `public/router.php` | `/member?view=plans`, `/member?view=onboarding` via `public_routes` hook | `/book`, `/book/*` via `public/router.php` | At route dispatch, before the route handler is invoked — one call at the router's dispatch point per plugin, not per individual route registered |
| **Customer portal** | N/A (no customer-facing surface) | `/member/membership/*` via `customer/portal_router.php` → `plugins/membership/public/router.php` | `customer/book.php` | Same principle — one call at the portal router's per-plugin dispatch point |
| **API** | MCP handler (`FormsMcpHandler.php`) | N/A (no direct `api_v1_routes` registration found in Phase 0 audit) | `/api/v1/booking` via `ApiRouter` (`api_v1_routes` hook) | At the point each plugin registers its API routes, or at `ApiRouter::handle()` dispatch if entitlement can be resolved generically from the route's owning module — see §3 |
| **AJAX** | N/A specific to Forms beyond admin AJAX already covered above | N/A | N/A | Covered by Admin controller entry above; no module has a distinct AJAX surface outside its admin scripts per Phase 0's audit |
| **Service/internal call** | `FormsAPI::submit()` | `MembershipAPI::createSubscription()` | `BookingAPI::createAppointment()` | `RECOMMENDED`, not `REQUIRES VERIFICATION`-blocking: add the same guard check at the top of these service methods too, as defense-in-depth against any future internal caller (a hook listener, a future CLI tool) that invokes the service directly without going through a route. `06-LICENSING-SECURITY-AUDIT.md` finding 2.5 explicitly calls this vector out. |
| **Background/cron** | N/A (no cron hook found for Forms) | `frequent_cron` listener (unspecified handler in Phase 0 audit) | `sendReminders()` on `frequent_cron` | See §5 — checked once at the top of the listener callback, not per-item inside its loop |
| **Stripe webhook** | N/A | N/A | `stripe_webhook_event` listener | `RECOMMENDED`: guard the *effect* (do not create/modify a booking record) rather than the webhook receipt itself — a webhook must still be acknowledged (HTTP 200) to Stripe even if the installation's booking entitlement has since lapsed, to avoid Stripe retry storms; the guard should cause the handler to log-and-skip its booking-specific side effects, not reject the HTTP request |

---

## 3. Guard Implementation Shape

`RECOMMENDED` shape, consistent with the codebase's existing `Auth::requirePerm(string $perm): void` convention (throws/exits with an HTTP error on failure, returns silently on success):

```text
ModuleGuard::require(string $moduleKey): void
    — reads the CURRENT verified license entitlement set (client-side
      cache, see 10) and either returns silently or renders a 403 /
      "module not included in your license" page and exits.

ModuleGuard::isEntitled(string $moduleKey): bool
    — same check, boolean return, for conditional UI rendering (menu
      items, dashboard widgets) — presentational use only, never a
      substitute for the ::require() call at the actual entry point.
```

This mirrors the existing, already-correct pattern where `EntitlementService::canAccess()` (boolean) and a hypothetical `::requireAccess()` (throwing) would serve different callers — today only the boolean form exists, and even that is only called from the dashboard's presentational stat cards, never from an enforcement point (`MOD-01`). The target adds the throwing form and, critically, calls it from every location in §2's table.

**Relationship to `EntitlementService`:** `EntitlementService::canAccess(int $tenantId, string $featureKey)` already implements correct remote-vs-legacy authority resolution (`EXISTING`, verified directly — see `04-ENTITLEMENT-ARCHITECTURE.md` and `10-CLIENT-LICENSING-DATABASE-DESIGN.md` for how its `tenantId` parameter is treated going forward). `ModuleGuard::require()` is a thin wrapper around this existing function that adds the "throw/exit on false" enforcement behavior — it is not a replacement for `EntitlementService`, it is the missing caller.

---

## 4. Disabled-Module Behavior on Direct Access

For each of Forms, Membership, and Booking, when accessed without the required entitlement:

| Access Path | Response |
| :--- | :--- |
| Admin controller (authenticated, RBAC-permitted, but not entitled) | HTTP 403, rendered page: "This module is not included in your current license." No data returned, no partial rendering of the underlying page shell. |
| Public route (unauthenticated visitor hitting `/book/...` on a Booking-unentitled installation) | HTTP 404 (not 403) — matches the Central Server's own anti-enumeration convention (`LicensingAPI`'s uniform 404 for invalid requests) and avoids confirming to an anonymous visitor that a Booking *feature exists but is disabled*, which is marginally more information than a plain "page not found." `RECOMMENDED`, not `REQUIRES VERIFICATION` — this is a minor hardening choice, not a requirement-driven one. |
| API route | HTTP 403 with a machine-readable error body (e.g. `{"error": "module_not_entitled", "module": "booking"}`) — APIs are consumed by programs, not browsers, so a clear machine-readable reason is more useful here than the anti-enumeration concern that applies to public anonymous routes. |
| Customer portal | Same as admin controller — the customer is at least identifiable (an authenticated `customers` session), so a clear "not available" message is appropriate, matching how `Auth::requireCustomer()` already redirects unauthenticated customers to a login page rather than a generic 404. |
| Background/cron listener | No HTTP response involved — the listener simply returns early without performing its module-specific side effect (§2's Booking `sendReminders()` example: no reminder email is sent; nothing is logged as an error, since this is expected, routine behavior for an unentitled installation, not a fault). |

---

## 5. Cron/Background Guard Detail

Per `06-GLOBAL-LICENSE-GUARD.md` §5 and `08-LICENSING-MIGRATION-RISKS.md` §2.6: the Module Guard check inside a `frequent_cron`/`daily_cron` listener must happen **once, at the top of the listener callback**, not per-record inside whatever loop the listener runs. This matters for two reasons: (1) it is cheaper — one entitlement check per cron tick instead of one per appointment/reminder; (2) it correctly models the actual requirement, which is "this *module* is disabled," not "this specific record is disabled" — there is no requirement for partial/per-record module suppression. `RECOMMENDED` implementation note, not itself architecturally load-bearing.

---

## 6. Relationship to RBAC Permissions

Module Guard checks and RBAC permission checks (`Auth::requirePerm('booking.view')` etc.) are independent and both required — this mirrors the Global License Guard / Auth relationship in `01-TARGET-ARCHITECTURE.md` §4. A user with `booking.manage` permission but whose installation's license does not include `booking` must still be blocked; conversely, a valid `booking` entitlement grants nothing to a user who lacks `booking.view` permission. Today, RBAC is the *only* check performed (`MOD-02` through `MOD-04`); the target adds the Module Guard as a second, independent, mandatory gate — it does not replace or weaken RBAC (`AUTH-05`: "Auth and Entitlement services are completely decoupled" is resolved by adding the missing caller, not by merging the two systems into one).

---

## 7. Extensibility

As established in `04-ENTITLEMENT-ARCHITECTURE.md` §5, adding a fourth module (e.g. `editor`) requires one new `ModuleGuard::require('editor')` call per entry point in a table shaped like §2's, using the exact same `ModuleGuard` API — no change to the Guard's own implementation. This document's design is deliberately generic over `moduleKey` for this reason; nothing here is Forms/Membership/Booking-specific beyond the entry-point inventory in §2, which is expected to grow, not be redesigned, when a fourth module is licensed.
