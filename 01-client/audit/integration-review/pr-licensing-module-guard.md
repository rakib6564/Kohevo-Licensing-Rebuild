## fix(licensing): central ModuleGuard, per-plan gating, plan assignment on the Tenants screen

Branch `fix/licensing-module-guard`, based on `origin/main` `ba4c9a5`: 6 commits, 19 files, +957/−4, all in `02-licensing`.

| Commit | What it does |
|---|---|
| `08f3b99` | Ports `ModuleGuard` into the central tree, aliased like `01-client`. |
| `f5154f5` | Central module tests run as an entitled tenant; adds the read-only plan preflight `bin/check-tenant-plans.php`. |
| `188808b` | Adds `bin/assign-tenant-plan.php`. |
| `fd592d3` | The shared test tenant gets Professional, not Enterprise (Enterprise carries `white_label`, which broke later signature tests in one process). |
| `2866452` | Tenants › Edit › **Plan**: assign, change or remove a tenant's plan; a warning on the Tenants list for active tenants that would be refused a module. |
| `d77b076` | `APP_ENV=testing` no longer switches off ModuleGuard on web requests (CLI only). |

### Why

Commit `d23f4e0` bundled Forms, Booking and Membership into central, but not the guard they call: **60 calls in 56 central files**. On `main` every one of those pages, routes, API calls, MCP tools and hooks fails with `Class "ModuleGuard" not found` (a 500).

### Policy (decision D1, approved by the owner on 2026-10-18)

Central tenants are gated by **their own platform plan**; central gets no exemption. This uses central's existing, tested `EntitlementService`:

- **Access rule:** the plugin is active, the licence status is trial, active or none, the tenant's plan includes the module, and the tenant's toggle isn't off.
- **Platform-admin pages** (Tenants, Licences, Plans, platform admins) stay outside the gate, so a platform admin can always fix a plan.
- **When denied:** 403 on admin pages, 404 on public routes, JSON 403 from the API; hooks skip their side effect.

### Plan assignment

`EntitlementService` reads `tenant_profiles.plan_id`. Until now nothing in the admin UI set it. This PR adds:

- **Tenants › Edit › Plan** (`admin/tenants.php`, `_action=assign_plan`): platform admins only, CSRF-checked, written through `TenantService::assignPlan()`, audit-logged as `tenant.plan_assigned` (from, to, via). Only active plans are offered or accepted; a tenant's current retired plan is shown as "(Inactive)" and re-saving it is a no-op.
- **Warning** on the Tenants list (`PlanCoverage`): active tenants that would be refused Forms, Booking or Membership, with a link to their Plan card.
- **CLI:** `bin/check-tenant-plans.php` (exit 2 if anyone would be refused) and `bin/assign-tenant-plan.php <tenant> <plan|none>`, both on the same service.

Nothing is assigned automatically. **Before deploying**, follow `audit/integration-review/11-runbooks.md` §1: run the preflight, then assign plans, starting with the operator's tenant.

**Retired plans (decision R2):** tenants already on a retired plan keep its modules; new assignments are refused. This matches the architecture doc's "retire from future use". Confirm, or ask for revocation.

### Tests

| Test | Result |
|---|---|
| `CentralModuleGuardTest` | 6/6. Real admin pages: no plan → 403; Free → Forms 200, Booking/Membership 403; Professional → all 200; unknown plan → 403. A built-in web server request with `APP_ENV=testing` is still refused. |
| `TenantPlanAdminTest` | 5/5. Assign/change/remove with 3 audit entries; unknown, non-numeric and inactive plans refused; retired current plan is a no-op and keeps access; non-platform admin 403; bad CSRF changes nothing; warning appears and clears. |
| `CentralTenantPlanCliTest` | 2/2. |
| Central Booking/Forms suites | 21/21 (0/21 on `main`), as an entitled tenant restored at shutdown; no `SLATE_TESTING` in probes, no production bypass. |
| Mutations | `allows()` always true, `APP_ENV` bypass on web, inactive plans accepted, platform-admin gate removed: all caught. |

Combined-tree results with the other two PRs: `audit/integration-review/04-test-results.md`.

### Rollback

Revert the PR. Central Forms, Booking and Membership go back to failing with a 500. Stored plan assignments are harmless after a revert.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
