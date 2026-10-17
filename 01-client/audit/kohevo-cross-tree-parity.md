# Kohevo cross-tree parity (01-client vs 02-licensing)

> **Regenerated 2026-10-17** against `origin/main` (`ba4c9a5`) for every file this branch changes or adds. Method: for each path present in both trees, either the files are byte-identical after the change, or the `git diff` deltas are compared.

## Production files changed in both trees

| Path (under each tree) | Parity |
|---|---|
| `admin/settings.php` | Identical file. The licensing copy also gained the client's guarded `default_language_studio_hint` line. That line is out of scope, but inert there because `StudioBuilder` does not exist in that tree. |
| `admin/partials/header.php` | Same change |
| `includes/ui_components.php` | Same change |
| `plugins/stripe-payment/admin/settings.php` | Identical file |
| `plugins/forms/admin/edit.php` | Identical file |
| `plugins/forms/lib/FormsSpamGuard.php` | Identical file |
| `plugins/backups/admin/settings.php` | Identical file |
| `plugins/booking/admin/settings.php` | Identical file |
| `plugins/coaching/admin/settings.php` | Identical file |
| `plugins/mcp-gateway/admin/ai-settings.php` | Identical file |
| `plugins/forms/README.md`, `plugins/booking/README.md` | Identical files |
| `src/Services/Identity/IdentityStore.php` | Identical file |
| `archive/.htaccess` (new) | Identical file |
| `lang/fr.php` | Different by design: 12 new keys in the client, 13 in licensing (the extra one is `default_language_studio_hint`, which the client already had). `scripts/check-i18n.php` passes for both trees. |

## Client-only production change

| Path | Why client-only |
|---|---|
| none | `PaymentButtonRenderer.php` no longer differs from `origin/main`: the audit's test seam was removed. |

## Tests

| Path | Parity |
|---|---|
| `tests/unit/SystemSettingsAuditFixesTest.php`, `tests/unit/FormsCaptchaSecretEncryptionTest.php`, `tests/unit/Phase7SlateKohevoIdentityTest.php`, `tests/unit/SessionLockTest.php` + probe | Identical in both trees |
| `tests/integration/ContactSeederTest.php`, `CustomerAuthParityTest.php` | Identical in both trees. The client's clock-skew fixture fix was mirrored to licensing on 2026-10-17, which fixed 3 licensing failures. |
| `tests/integration/StripeWriteOnlySecretTest.php`, `FormsCaptchaSecretSaveTest.php` | Client only: they drive the admin pages through the client probe fixtures. The Stripe and Forms code they exercise is byte-identical in licensing. |
| `tests/fixtures/admin-page{,-post}-probe.php`, `PlatformAdminGateTest`, `PluginActivationAuthorizationTest`, `EntitlementServiceTest`, `PlatformIdentityWhiteLabelTest`, `DashboardMetricsTest` | Changed in the client only. The licensing copies are unchanged, and only `DashboardMetricsTest` fails there; that failure also occurs on `origin/main` (see `review-package/test-results.md`). |
| `Phase3RemoteAuthorityTest`, `StudioBuilderPaymentBlocksTest`, `StudioBuilderSprint8WidgetSdkTest` | Client only: no licensing counterpart |

## Pre-existing parity defect found (not fixed)

`02-licensing/plugins/booking/BookingApiHandler.php:19`, `02-licensing/plugins/booking/BookingAPI.php:2368` and `02-licensing/plugins/forms/public/router.php:19` (along with other Forms admin files) call `ModuleGuard`. That class exists only in `01-client/src/Services/Licensing/ModuleGuard.php`. In the licensing tree those code paths fail with `Class "ModuleGuard" not found`, which accounts for the Booking API 500s and the Forms router failure in the licensing integration results. The same failure occurs on `origin/main`.
