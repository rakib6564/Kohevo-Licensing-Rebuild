# D1: should central Forms, Booking and Membership require a plan?

Prepared 2026-10-17. The source tree is `fix/licensing-module-guard` `08f3b99` on top of `origin/main` `ba4c9a5`.

## Recommended policy

**Gate each tenant on the central server by its own platform plan, exactly as `08f3b99` does. Do not exempt central. Platform-admin screens stay outside the gate. Before deploying, give each central tenant that uses these modules a plan that includes them; that includes the operator's own tenant.**

The gating rules:

- A tenant can reach a Forms, Booking or Membership page, public route, API call or MCP tool only if all of these hold:
  - the plugin is active;
  - the tenant's licence status is `trial`, `active` or `none`;
  - the tenant has a plan in `tenant_profiles.plan_id` that includes the module;
  - the tenant's `entitlement.<module>.enabled` toggle has not been set to `0`.
- If the check fails, the visitor gets the guard's response: 403 on admin pages, 404 on public routes, a JSON 403 from the API, and a silently skipped effect for hooks and webhooks.
- CLI, cron and migrations keep the existing bypass.
- `admin/tenants.php`, `admin/licenses.php`, `admin/plans.php` and `admin/platform-admins.php` check `Auth::requirePlatformAdmin()` and never call `EntitlementService`. They are unaffected.

## Evidence

| Question | Evidence |
|---|---|
| Is central multi-tenant with commercial plans? | Yes. Migration `0015_platform_plans.php` seeds four plans:<br>• Free: `forms`<br>• Starter: `forms`, `booking`, `media-library`<br>• Professional: adds `coaching`, `membership`, `multilang-translate`<br>• Enterprise: adds `stripe-payment`<br>`0016_tenant_plan_assignment.php` adds `tenant_profiles.plan_id` as nullable with no default. Central has `admin/tenants.php`, `plans.php` and `licenses.php`. |
| What does central's own entitlement model say about a tenant without a plan? | `02-licensing/src/Services/Licensing/EntitlementService.php`: "A tenant with no plan assigned is NOT entitled to anything beyond what core (non-plugin) admin pages already provide." `tests/integration/EntitlementServiceTest.php` pins this behaviour: no plan means `forms` is denied, a plan without `forms` denies it, and a suspended or revoked licence denies even an entitled feature. |
| Is the central server meant to be exempt? | No code or document says so. The same test file pins the only exemption, for platform-admin pages ("platform administrators are never subject to this check"), and nothing exempts tenant-level module pages. Exempting central would make the four seeded plans and the `plan_entitlements` table meaningless for its own tenants. |
| What's the boundary between central and client installations? | `docs/02-architecture/02-CENTRAL-LICENSING-DOMAIN.md` and `04-ENTITLEMENT-ARCHITECTURE.md`: central issues licences, and a licence's module grants are what a **client installation** (`01-client`) enforces through `ModuleGuard`, which reads the signed `remote_license_cache`. Central's tenants are a separate, local plan system (Phase 1E). The same `ModuleGuard` class works in both trees because it only calls `EntitlementService::canAccess()`, and each tree resolves that call through its own authority. |
| Do the bundled plugins expect a guard? | `d23f4e0` ("bundle all commercial and platform modules in central") copied Forms, Booking and Membership with their guard calls: 60 calls in 56 production files: 35 Booking files, 9 Forms, 11 Membership and 1 `mcp-gateway/ReportingMcpTools.php`. This was recounted on 2026-10-17 with comments excluded; one grep hit in `BookingMcpHandler.php` is a comment. The full list is in `test-logs/central-callsites.txt`. The class itself was never copied, so before `08f3b99` every one of those entry points ended with `Class "ModuleGuard" not found`. That failed closed, but as a 500. |
| Does enforcement behave as intended? | Tested on 2026-10-17 by giving test tenant 1 a temporary `tenant_profiles` row, which was deleted afterwards (log in `test-logs/d1-probe.log`):<br>• Enterprise plan: all 21 Booking/Forms tests that previously failed now pass (7/7, 3/3, 8/8, 2/2, 3/3, 1/1), and so does `CentralModuleGuardTest` (3/3).<br>• Free plan: `forms` is allowed; `booking` and `membership` are denied. The Booking admin pages return 403.<br>• Turning off the Forms toggle denies `forms` as well. |

## Security and operational implications

| | Gate per plan (recommended) | Exempt central (rejected) |
|---|---|---|
| Authorisation | Each tenant gets only what its plan sells. Denial fails closed. | Any central tenant gets Forms, Booking and Membership regardless of plan. This is an entitlement bypass for paying tiers and contradicts `EntitlementServiceTest`. |
| Operator tenant | Needs a plan that covers the modules, for example Enterprise. A one-time assignment in `admin/tenants.php`. | Works with no assignment. |
| Deploy risk | Without that assignment, the operator's Forms, Booking and Membership pages return 403. Today they return 500, so nothing that currently works breaks. Public forms and booking pages for a tenant with no plan return 404. | None in the short term, but every plan below Enterprise is silently over-provisioned. |
| Webhooks and hooks | For an unentitled tenant, booking emails, calendar sync and the Stripe effect are skipped silently by `allows()`. | They always run. |
| Tests | The 21 central Booking/Forms tests need a tenant with an entitled plan. Recommended fix: those 6 files seed an Enterprise plan for the test tenant and remove it in `finally`, which exercises real enforcement. **Do not** copy the client's `SLATE_TESTING` probe bypass, because that would hide the gate in central tests. | Tests pass with no setup. |

## ModuleGuard review (`08f3b99`)

| Check | Result |
|---|---|
| Is the class really needed? | Yes. Base `02-licensing`: loading `config.php` and then calling `class_exists('ModuleGuard')` returns false, and there are 60 calls in 56 files (above). |
| Namespace and autoloading | `Slate\Services\Licensing\ModuleGuard` at `02-licensing/src/Services/Licensing/ModuleGuard.php`. Central's PSR-4 autoloader in `src/autoload.php` maps `Slate\` to `src/`. The unqualified alias `class_alias(\Slate\Services\Licensing\ModuleGuard::class, 'ModuleGuard')` was added in `src/compat/aliases.php`, matching `01-client/src/compat/aliases.php:47`. |
| Matches the client class? | The code is byte-identical to `01-client/src/Services/Licensing/ModuleGuard.php`. The only difference is a 9-line docblock note for the central tree. |
| Dependencies exist in central? | `EntitlementService::canAccess()`, `current_tenant_id()`, `env()` (`config.php:37`), `slate_render_error()` (`includes/error_page.php:18`), and `Slate\Kernel\Http\ApiRouter::respondError` are all present. |
| Fails closed? | Yes. `require()` returns 403, `requirePublic()` 404, `requireApi()` a JSON 403. `allows()` returns false unless bypassed, and the bypass applies only to CLI without `REQUEST_METHOD`, `SLATE_TESTING` or `SLATE_MIGRATING`, or `APP_ENV=testing`. None of these can be set from a request. In central, `SLATE_TESTING` is defined only by the CLI runner `tests/run-dynamic-modules.php`; no production file and no probe fixture defines it. |
| `01-client` changed? | No. `git diff origin/main 08f3b99 -- 01-client` is empty. |
| Behaviour change beyond adding the class | Yes, and it's intended: central Forms, Booking, Membership and MCP-reporting entry points move from **500** to **the plan-based answer** (200, 403 or 404). The `APP_ENV=testing` bypass now also applies in central. Check that production `.env` never sets `APP_ENV=testing`; it is not set in the current test `.env`. |
| Null, invalid or inactive plan | Reproduced on 2026-10-17 (`test-logs/d1-matrix.log`):<br>• no `tenant_profiles` row, `plan_id` NULL, or `plan_id` 999999 (no such plan): forms, booking and membership are all **denied**;<br>• Free allows forms only; Starter allows forms and booking; Professional and Enterprise allow all three;<br>• Enterprise with the forms toggle off denies forms.<br>Test data was restored (0 rows before and after). **Open question (low):** `PlanService::entitlementsFor()` ignores `platform_plans.is_active`, so an inactive plan still grants its modules to tenants already on it. That looks intended (inactive meaning "not offered to new tenants") but isn't documented. |
| Call-site layer review | Admin pages call `require()` at the top, before any work. Public routes call `requirePublic()` (404). The API handler calls `requireApi()`. MCP handlers check `allows()` per tool prefix (`slate_booking_`, `slate_forms_`, `slate_membership_`). Hooks, calendar sync and reporting check `allows()` and skip their side effect. No call site was found at the wrong layer. No Booking, Forms or Membership entry point was found without a guard: the 56 central files match the client's guarded plugin files exactly. |
| Tests | `CentralModuleGuardTest` has 3 cases: the alias, `isEntitled` equals `canAccess` with the toggle respected, and the admin pages no longer fatal. Licensing unit 426/430 (the base failures) and the CI subset 11/11 on that branch alone. |

## Addendum (final pass, 2026-10-17): how a tenant gets a plan

`EntitlementService` reads `tenant_profiles.plan_id`, but **nothing in the product writes that column**:
- `admin/tenants.php` has no plan field;
- `TenantService::create()` leaves it NULL;
- `admin/licenses.php` stores a plan on the *licence* (`licenses.plan_id`), and entitlement checks never read that.

Git history shows no plan-assignment screen ever existed for tenants. Migration `0016` added the column, and only tests set it.

Under the recommended D1 policy, every central tenant would therefore be denied Forms, Booking and Membership, with no supported fix. `fix/licensing-module-guard` now adds:
- `bin/assign-tenant-plan.php <tenant> <plan|none>`: refuses inactive plans and records `tenant.plan_assigned`;
- `bin/check-tenant-plans.php`: a preflight that exits 2 if an active tenant would be denied. Its only write is the `last_validated_at` stamp every licence check makes.

`CentralTenantPlanCliTest` covers both.

**Open product decisions:**
- (R1) A plan selector on the Tenants screen, or `EntitlementService` falling back to the licence's plan.
- (R2) Whether an inactive plan should keep granting its modules to tenants already on it. The current code says yes, which matches architecture doc 09's "retire from future use".

### Admin access matrix (real pages, super-admin, `CentralModuleGuardTest`)

| Tenant plan | Forms admin | Booking admin | Membership admin |
|---|---|---|---|
| none / NULL | 403 | 403 | 403 |
| unknown `plan_id` (999999) | 403 | 403 | 403 |
| Free | 200 | 403 | 403 |
| Starter | 200 | 200 | 403 |
| Professional | 200 | 200 | 200 |

Making `ModuleGuard::allows()` always return true causes this test to fail (3/5).

## Addendum (release-check, 2026-10-17)

- **R1 is resolved.** The Tenants screen now has a Plan card (`2866452`): platform admins only, CSRF-checked, audit-logged, only active plans accepted. The list warns about active tenants that would be refused a module. The CLI uses the same `TenantService::assignPlan()`. `TenantPlanAdminTest` covers it (5/5). The licence-plan fallback (option b) was not implemented: it would silently grant modules from a licence record that entitlement checks have never read.
- **R2 is implemented as recommended and still needs your confirmation.** A tenant already on a retired plan keeps it (re-saving is a no-op) and keeps its modules; new assignments of a retired plan are refused. `TenantPlanAdminTest` case 3 pins this, so changing the policy will fail that test.
- **`APP_ENV=testing`** used to bypass ModuleGuard on web requests as well as on CLI. Now it counts only on CLI (`d77b076` central, `8db616f` client), proven against PHP's built-in web server.
- `CentralModuleGuardTest` now has 6 cases (`d77b076` added the web-request case); making `allows()` always true fails 3 of them.
