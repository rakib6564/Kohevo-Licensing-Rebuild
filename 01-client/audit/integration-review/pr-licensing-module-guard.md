## fix(licensing): central ModuleGuard, per-tenant plan enforcement, plan tooling

Branch `fix/licensing-module-guard`, based on `origin/main` `ba4c9a5`: 3 commits, 13 files, +594, all in `02-licensing`.

| Commit | What it does |
|---|---|
| `08f3b99` | Ports `ModuleGuard` into the central tree, aliased like `01-client`. |
| `f5154f5` | Tests run as an entitled tenant; adds the read-only plan preflight. |
| `188808b` | Adds `bin/assign-tenant-plan.php`. |

### Why

Commit `d23f4e0` bundled Forms, Booking and Membership into central, but not the guard they call. That's **60 calls in 56 central files**: Booking 35, Forms 9, Membership 11, and `mcp-gateway/ReportingMcpTools.php`. On `main`, every one of those pages, routes, API calls, MCP tools and hooks fails with `Class "ModuleGuard" not found` (a 500).

### Policy (decision D1)

Central tenants are gated by **their own platform plan**, and central gets no exemption. This matches central's existing, tested `EntitlementService`:

- **Access rule:** a tenant gets a module only if the plugin is active, its licence status is trial, active or none, its plan includes the module, and its toggle for the module isn't off.
- **Platform-admin pages** (tenants, licences, plans, platform admins) stay outside the gate, as `EntitlementServiceTest` already pins.
- **Responses when denied:** 403 on admin pages, 404 on public routes, a JSON 403 from the API, and hooks silently skip their side effect.

### Important: plan assignment

No screen sets `tenant_profiles.plan_id`, which is the column `EntitlementService` reads:
- the Tenants screen has no plan field;
- `admin/licenses.php` stores the plan on the licence, and entitlement checks never read that.

So this PR adds `php bin/assign-tenant-plan.php <tenant> <plan|none>`. It refuses inactive plans and records an audit-log entry. **Before deploying:**
1. Run `php bin/check-tenant-plans.php`. It exits with code 2 if any active tenant would be denied.
2. Assign plans, including to the operator tenant.

A plan selector in the admin UI is a separate decision.

### Tests

| Test | Result |
|---|---|
| `CentralModuleGuardTest` | 5/5. Real admin pages as super-admin: no plan → 403 on all three; Free → Forms 200, Booking and Membership 403; Starter → Membership 403; Professional → all 200; unknown `plan_id` → 403. Making `allows()` return `true` fails it (3/5). |
| `CentralTenantPlanCliTest` | 2/2. Both CLIs, the audit-log entry, and refusals. |
| The 6 central Booking/Forms files | Now give the test tenant the Enterprise plan through `tests/support/tenant-plan.php` and restore it at shutdown. No `SLATE_TESTING` in probes; no production change. Result: 21/21 (previously 0/21). |

The full combined-tree results are in the audit review (`04-test-results.md`).

### Not covered here

- A plan selector on the Tenants screen.
- Whether an inactive plan should keep granting its modules. Today it does, which matches "retired from new assignments".

🤖 Generated with [Claude Code](https://claude.com/claude-code)
