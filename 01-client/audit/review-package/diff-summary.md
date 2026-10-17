# Diff summary and commit plan

**Baseline:** `1ae3847cb88860d13e59b20307d36972f7006038` (HEAD of `feat/field-value-dropdown`, KOHEVO-STUDIO checkout). Every change below is uncommitted on top of it.
**Patches:** `audit-changes.patch` is the tracked-file diff (`git diff --binary` against HEAD). `new-files.patch` adds the new files that belong in the commit. Applying both to `1ae3847` reproduces the proposed change set.

## Tracked files: 45 modified

| Area | Files (both trees unless noted) | What changed | Origin |
|---|---|---|---|
| Stripe settings | `plugins/stripe-payment/admin/settings.php` | Secret key and webhook secret are write-only (empty inputs, "Configured" badge, blank keeps the stored value). Encryption fails closed for new secrets. Verification is skipped when the secret is unchanged and was already verified. Legacy plaintext is re-encrypted on save. | Audit, plus a **correction in this pass** (`$keepStored`) |
| Forms editor | `plugins/forms/admin/edit.php` | CAPTCHA secret is write-only, blank keeps it, and an explicit "Clear saved secret" checkbox removes it. | Audit |
| Backups / Booking / MCP | `plugins/{backups,booking}/admin/settings.php`, `plugins/mcp-gateway/admin/ai-settings.php` | `try/catch` around `slate_encrypt_secret()`. The secret that fails to encrypt is skipped and an error is flashed. Backup retention is clamped to 1..365. | Audit |
| Core settings | `admin/settings.php` | Permalink allow-list. `start_of_week` limited to 0..6. The System tab no longer writes `timezone`; it now shows a read-only value with a link. Font and radius hints updated. **02-licensing only:** also adds the client-only `default_language_studio_hint` field hint. That hint is out of scope and inert, and the reviewer may drop it. | Audit |
| Brand tokens | `includes/ui_components.php` | `slate_brand_accent_emit()` also emits `--font-sans`/`--font-display` and the `--radius*` scale from `brand_font_family` and `brand_border_radius`. | Audit |
| Coaching | `plugins/coaching/admin/settings.php` | `AuditLog::record('coaching.settings_updated')` on save. | Audit |
| Identity | `src/Services/Identity/IdentityStore.php` | Token `expires_at` is now stamped with `slate_db_time()`, so the clock matches `consumeToken()`. | Audit |
| Admin header | `admin/partials/header.php` | Name falls back to email, then to "Administrator". | Audit |
| Studio renderer (client only) | `src/Module/StudioBuilder/Render/Block/ModuleRenderers/PaymentButtonRenderer.php` | Adds `public static ?bool $stripeReadyOverride`. This is a test seam, **not** the "null-coalescing guard" the earlier summary described. | Audit |
| Docs | `plugins/forms/README.md`, `plugins/booking/README.md` | Wording | Audit |
| French strings | `lang/fr.php` | Adds 11 entries (client) and 12 (licensing) for strings the audit introduced. Without them `scripts/check-i18n.php` fails CI with 23 missing entries. | **This pass** |
| Probe fixtures (client only) | `tests/fixtures/admin-page-probe.php`, `admin-page-post-probe.php` | `session_write_close()` before rebinding the session id. Adds a session `name`. Adds an unlock-plugins flag, which this pass moved to `argv[6]` in the POST probe because `argv[5]` is `tenantOverride`. | Audit, plus a **correction in this pass** |
| Integration tests (client only) | `ContactSeederTest`, `CustomerAuthParityTest`, `DashboardMetricsTest`, `EntitlementServiceTest`, `PlatformAdminGateTest`, `PlatformIdentityWhiteLabelTest`, `PluginActivationAuthorizationTest` | Clock alignment. Phase 13 remote-cache seeding. Plugin unlock. This pass restored the anchored `^STATUS` match in the two POST helpers. | Audit, plus a **correction in this pass** |
| Unit tests | `Phase3RemoteAuthorityTest` (client), `Phase7SlateKohevoIdentityTest`, `SessionLockTest` + probe (both), `StudioBuilderPaymentBlocksTest`, `StudioBuilderSprint8WidgetSdkTest` (client) | `installation_id` in the payload. Archive assertions made conditional (vacuous). Longer lock hold. Uses the override seam. Load-order fixes. | Audit |

Each test hunk is classified (genuine fix / retired expectation / weakened) in `verification-matrix.md`.

## New files

| File | Class | In commit? |
|---|---|---|
| `01-client/archive/.htaccess`, `02-licensing/archive/.htaccess` | Security sentinel. Required by `PluginLoaderArchiveBoundaryTest`. | **Yes** |
| `01-client/tests/unit/SystemSettingsAuditFixesTest.php`, `02-licensing/tests/unit/SystemSettingsAuditFixesTest.php` | Audit tests. Tests 1–4 and 6 were rewritten in this pass to check the shipped source. | **Yes** |
| `01-client/tests/integration/StripeWriteOnlySecretTest.php` | New in this pass: end-to-end Stripe write-only and re-encryption test. | **Yes** |
| `01-client/audit/kohevo-*.md`, `kohevo-settings-widget-inventory.csv` | Audit reports. Verification notes were added in this pass. | **Yes** (docs) |
| `01-client/Claude/kohevo-complete-system-implementation-prompt.md` | Implementation guidance | **Yes** (docs) |
| `01-client/audit/review-package/*` (except the ZIP) | Review evidence | **Yes** (docs) |
| `01-client/audit/Kohevo_Complete_System_Plugin_Widget_Audit_Final_Bundle.zip` | Generated artifact | **No.** Kept on disk; checksum is in `test-results.md`. |
| `01-client/composer.lock`, `02-licensing/composer.lock`, `*/vendor/` | Local environment (`composer install`) | **No** |
| `prototype-14islands/`, `prototype-juanmora/` | Pre-existing user work | **No, never** |

## Commit plan (prepared, not executed)

The work must not be committed onto `feat/field-value-dropdown`, which carries an unrelated feature. Create a dedicated branch from the same HEAD. Uncommitted changes carry over to the new branch.

```bash
cd ~/Downloads/Fahim/KOHEVO-STUDIO
git switch -c audit/settings-secrets-hardening
git add -- $(git diff --name-only)
git add -- 01-client/archive/.htaccess 02-licensing/archive/.htaccess \
  01-client/tests/unit/SystemSettingsAuditFixesTest.php 02-licensing/tests/unit/SystemSettingsAuditFixesTest.php \
  01-client/tests/integration/StripeWriteOnlySecretTest.php \
  01-client/audit/kohevo-complete-system-audit.md 01-client/audit/kohevo-cross-tree-parity.md \
  01-client/audit/kohevo-option-disposition.md 01-client/audit/kohevo-settings-widget-inventory.csv \
  01-client/audit/kohevo-widget-test-matrix.md 01-client/Claude/kohevo-complete-system-implementation-prompt.md
git add -- $(git ls-files --others --exclude-standard 01-client/audit/review-package)
git diff --cached --check && git diff --cached --stat
git commit -F 01-client/audit/review-package/commit-message.txt
```

Because the branch is created at `1ae3847`, the PR targets `main` and will also include the Field value dropdown commit, which is not yet on `origin/main`. To keep the PR to audit-only work, either merge `feat/field-value-dropdown` first, or rebase the audit branch onto `origin/main` before opening the PR (`git rebase --onto origin/main 1ae3847`). The prepared commit message and PR body are `commit-message.txt` and `pr-description.md`.
