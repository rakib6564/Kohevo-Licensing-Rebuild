# Cross-tree parity of the changed files (2026-10-18)

**Scope.** Every file under `01-client/` or `02-licensing/` that the eight PR branches change relative to `origin/main` `ba4c9a5`. Audit documents are excluded. The comparison was run on the combined tree `integration/release-check-highs`, which contains all eight branches; its code is `5e4963c`.

**Method.** Each file was compared with `cmp` between the two trees. Where the copies differ, the number of differing lines was counted both on the combined tree and on `main`, to show whether this work widened or narrowed the gap. The full-repository parity baseline is still `01-client/audit/kohevo-cross-tree-parity.md`.

## Totals

| Result | Files |
|---|---|
| Identical in both trees | 37 |
| Present in both trees, but different | 25 |
| Client only | 21 |
| Licensing only | 11 |

## Files that differ, and why

| File (relative to each tree) | Differing lines on `main` → now | Explanation |
|---|---|---|
| `src/Services/Licensing/ModuleGuard.php` | 183 → 13 | Gap **narrowed**: central now has the client's class (`08f3b99`). The remaining lines are the docblock on how central tests are entitled. Bypass rules are identical, including the CLI-only `APP_ENV` check (`8db616f` client, `d77b076` central). |
| `admin/tenants.php` | 2 → 72 | Central only: the Tenants › Plan card and the "would be refused a module" warning (`2866452`). The client has no platform plans; its entitlements come from the signed licence. |
| `src/Services/Tenancy/TenantService.php` | 0 → 55 | Central only: `assignPlan()` and plan columns in `find()`/`list()` (`2866452`). Same reason. |
| `lang/fr.php` | 50 → 58 | The `tenant_plan*` strings exist only in central (`2866452`). `scripts/check-i18n.php` reports 0 missing keys in both trees. |
| `src/compat/aliases.php` | 8 → 9 | Central gains the `ModuleGuard` alias the client already had. |
| `tests/fixtures/admin-page-probe.php`, `admin-page-post-probe.php` | 79 → 87, 84 → 98 | Central probes take the `platformAdmin` and `tenantOverride` arguments its plan tests need. |
| `tests/fixtures/sapi-guard-probe.php` | new → 36 | Each tree's probe calls its own entry point. The client probe also has the `?mode=bypass` path (`49c1fd4`), needed because the client licence guard answers before ModuleGuard. |
| `tests/integration/Booking*Test.php` (5 files), `FormsPdfTokenLiveTest.php`, `PlatformAdminGateTest.php`, `PluginActivationAuthorizationTest.php`, `EntitlementServiceTest.php`, `PlatformIdentityWhiteLabelTest.php` | 0 → 5–77 | Central versions run as an entitled tenant (`slate_test_entitle_current_tenant()`, Professional plan) or assert plan behaviour. The client entitles through the signed licence cache, so these lines don't apply there. |
| `tests/integration/LicenseGateTest.php` | 253 → 269 | Central fixtures now seed trusted cache rows (`5462c1e`); the client copy already did. |
| `tests/integration/DashboardMetricsTest.php` | 16 → 46 | The client copy seeds a trusted licence for its plan-section case (`e9a1bdf`). The central copy keeps its 2 failures (R5). |
| `plugins/mcp-gateway/CoreMcpTools.php`, `McpGatewayAPI.php` | 12 → 12, 186 → 186 | **Unchanged gap.** The N2 change (`3862f1c`) was applied identically to both trees; the differing lines are the same pre-existing ones. |
| `includes/helpers.php`, `includes/ui_components.php`, `admin/partials/header.php` | 5 → 5, 72 → 72, 41 → 41 | Unchanged gap. The new helpers (`slate_seal_posted_secrets`, `slate_reencrypt_envelope`) are byte-identical in both trees. |

## Files changed in one tree only

**Client only (21):**
- **Studio Builder:** code and tests for N1, N3, N4 and N5. Studio exists only in the client.
- **Client licence guard:** `includes/license_guard.php` (`8db616f`) and `AppEnvTestingBypassTest`.
- **Security integration tests:** `RekeySecretsCliTest`, `SettingsEncryptFirstTest`, `SecretHandlingGapsTest`, `StripeWriteOnlySecretTest`, `FormsCaptchaSecretSaveTest`.
- **Phase 10 tests:** `Phase10SynchronizationTest`, `Phase10ZAutoRefreshTest` and their helper file `tests/support/phase10.php` (`234b4d6`).
- **Unit tests:** `Phase3RemoteAuthorityTest`, `StudioBuilderPaymentBlocksTest`, `StudioBuilderSprint6ThemeBuilderTest`, `StudioBuilderSprint8WidgetSdkTest`.
- **Implementation prompt:** `Claude/kohevo-complete-system-implementation-prompt.md`.

**Licensing only (11):**
- **Plan tooling:** `bin/check-tenant-plans.php`, `bin/assign-tenant-plan.php`, `PlanCoverage`.
- **Test helpers:** `tests/support/tenant-plan.php`, `tests/support/licensing-plugin.php`.
- **Plan and licensing tests:** `CentralModuleGuardTest`, `CentralTenantPlanCliTest`, `TenantPlanAdminTest`, `CentralLicensingFoundationTest`, `DynamicModuleSystemTest`, `LicensingPluginSchemaTest`.

### Parity gap in test coverage (P-01, Medium)

The five security integration tests listed above exist only in the client tree. The code they exercise is **byte-identical** in central:
- `bin/rekey-secrets.php`
- `admin/settings.php`, `admin/oauth_callback.php`, `includes/SmtpOAuth.php`
- the Stripe, Forms, Booking, Backups and MCP AI settings pages

So the behaviour is the same, but central has no integration test of its own for those paths. Central's unit suite does cover the shared helpers (`SealPostedSecretsTest`, `ReencryptEnvelopeTest`).

**Recommendation:** copy the five tests to `02-licensing/tests/integration/` in a follow-up PR, adjusting the probes and the entitled-tenant setup as the central Booking/Forms tests do.

## Conclusion

No security **code** change was applied to only one tree where both trees have the code (test coverage gap P-01 aside):
- the secret sealing helpers, rekey CLI, MCP allow-list and `APP_ENV` rule are the same in both trees;
- every remaining difference is either central-only plan management, a test harness difference, or a gap that already existed on `main` and this work left unchanged.
