# Kohevo Complete System Implementation Prompt for AI Agents and Developers

> **Current status, 2026-10-18.** The text after this block is **historical**: it was written for `audit/settings-secrets-hardening` alone. The current work is eight PRs against `origin/main` `ba4c9a5`, none merged:
>
> | PR | Branch | What it holds |
> |---|---|---|
> | #102 | `fix/secret-handling-gaps` | SMTP OAuth secrets, Multilang translate key, AI base URL (SSRF). Merge first. |
> | #103 | `fix/licensing-module-guard` | Central `ModuleGuard`, per-plan gating (D1, approved), Tenants › Plan selector, plan CLIs, `APP_ENV` CLI-only |
> | #104 | `audit/settings-hardening-next` | Encrypt-first saves, `APP_SECRET` rotation (`bin/rekey-secrets.php`), test isolation, audit reports. Merge after #102. |
> | #105–#109 | `fix/studio-post-content-xss`, `fix/mcp-settings-allowlist`, `fix/studio-template-delete`, `fix/studio-theme-published-conditions`, `fix/studio-admin-permissions` | The five High findings N1–N5 |
>
> **Start here:** `01-client/audit/integration-review/README.md`, then `10-final-release-readiness.md`, `12-findings-register.md` and `13-studio-builder-gap-report.md`.
>
> **Open work, in priority order** (IDs from `12-findings-register.md`):
> 1. **Owner decisions:**
>    - R2: retired plans keep their modules for tenants already on them. This is implemented and pinned by `TenantPlanAdminTest`, but still needs confirmation.
>    - R5: restore or retire the central dashboard sections removed by `0d48565`.
> 2. **Studio Builder competing layout values and responsive gaps:**
>    - CL-01: page width is inert.
>    - CL-02 / W-F13: flex/grid props and `style.layout` duplicate each other.
>    - CL-03 / W-F4: per-device alignment has no media query.
>    - CL-04: sizes are not responsive.
>    - W-F12: two hide mechanisms.
> 3. **Theme Builder:**
>    - TB-04: 404 template never served.
>    - TB-05 / TB-07: search query not passed, then cached.
>    - TB-06: condition types that never match.
>    - TB-08: single templates published as pages.
>    - TB-09: candidate LIMIT applied before filtering.
>    - TI-04: `applyTemplate` copies conditions.
> 4. **Settings:**
>    - ST-02: saved but unused settings.
>    - ST-03: booking admin emails have no recipient.
>    - ST-04: `booking.cap.*` has two owners.
>    - ST-05: keys read but never written.
>    - S-12, S-13, S-14: unvalidated URL and colour settings.
> 5. **Tests:**
>    - P-01: copy the five client-only security integration tests to `02-licensing`.
>    - R4: add both trees' `tests/unit/run.php` and `tests/integration/run.php` to CI, as separate jobs with separate databases.
>
> **Rules learned in this work** (they extend §2):
> - **Seal before writing.** Every secret a form posts goes through `slate_seal_posted_secrets()` (or `SmtpOAuth::sealSecrets()`) before **any** write. If sealing fails, nothing on the form is saved.
> - **No new bypasses.** `APP_ENV=testing` counts only on CLI. Never add a guard bypass that a web request can reach.
> - **Plan gating.** Central modules are gated per tenant plan. Platform-admin pages are never gated. Nothing assigns a plan automatically.
> - **MCP tools.** A tool that writes settings must use an allow-list with the admin page's validators, never a deny pattern.
> - **Studio templates.** Theme Builder selection reads published settings, never `pages.settings_json` drafts. Admin pages must call the Studio application services (permission, audit, cache) rather than writing rows directly.
> - **Test independence.** A test file must not use helpers defined in another test file; put shared helpers in `tests/support/` and load them with `require_once`. Snapshot and restore every row a test changes.
> - **Running suites.** Run client and licensing integration suites one at a time: client Phase 10 tests reset the licensing test tables.
> - **Studio admin-page probes.** These need a signed `studio-builder` licence cache and the `LICENSE_*` environment (see `StudioTemplateDeleteTest::stdel_entitle()`).

**Target Architecture:** Dual-Tree (`01-client/` and `02-licensing/`)\
**Core Technologies:** PHP 8.2+ (PHP 8.5 compatible), MySQL 8+, React/TypeScript, Node.js v20+\
**Security Standard:** Zero-Trust Cryptographic Authority, Fail-Closed Encryption, Write-Only Secrets

---

## 1. Mission & Persona

You are an expert full-stack systems engineer working on **Kohevo**, an enterprise multi-tenant website builder and business management platform. Your mission is to maintain, extend, refactor, and harden the Kohevo codebase while strictly adhering to its architectural principles, security boundaries, and multi-tenant isolation guarantees.

---

## 2. Non-Negotiable Core Rules

1. **Preserve User Work & Prototypes:** Never reset, discard, or overwrite untracked files or branches (`prototype-14islands/`, `prototype-juanmora/`, `feat/*`).
2. **Dual-Tree Integrity:** Maintain `01-client/` and `02-licensing/` as paired trees.
   - `01-client/` is the customer runtime with Studio Builder and local license cache.
   - `02-licensing/` is the central licensing authority with master Ed25519 signing.
   - Shared core files and plugins must be updated symmetrically in both trees.
3. **Write-Only Secrets:** Sensitive credentials (Stripe keys, OAuth secrets, CAPTCHA secrets, Twilio tokens, AI API keys, SMTP passwords) must never be emitted into HTML, JavaScript, logs, or URL parameters.
   - Render inputs as `<input type="password" value="">`.
   - Display a status badge if configured.
   - Preserve existing secrets on blank submission.
   - Require explicit checkboxes or actions for audited secret removal.
4. **Fail-Closed Encryption:** Wrap all calls to `slate_encrypt_secret()` in `try/catch` blocks. If `APP_SECRET` is missing or encryption fails, abort execution immediately with an error flash. Never fall back to plaintext.
5. **Canonical Settings Ownership:** Every configuration value must have exactly one authoritative save handler. Do not permit dual-write settings across different tabs or forms.
6. **Strict Input Bounding & Allow-Lists:** Clamp numeric inputs (`min..max`) on the server. Never trust client constraints. Validate string configurations against strict allow-lists (e.g. permalinks, MIME types, locale codes).
7. **Widget & Block SSR Parity:** Every Studio Builder block must implement both a client canvas preview and a server-side PHP renderer with identical semantics, context-aware escaping, and error fallbacks.
8. **Cryptographic Integrity:** Client checkout surfaces must use HMAC-SHA256 signatures (`slate_sign`) to guarantee price and currency integrity.
9. **Tenant Isolation:** All database queries, audit logs, file uploads, and session overrides must be strictly scoped to the active tenant.
10. **Evidence-Backed Verification:** Never claim a feature works or a test passes without executing the test harness and verifying exit codes.

---

## 3. Secret Handling Reference Implementation

When creating or editing any form control handling a sensitive credential, follow this exact pattern:

```php
// 1. Determining existing configuration state
$existingEncryptedSecret = Database::setting('my_plugin_secret');
$isSecretConfigured = !empty($existingEncryptedSecret);

// 2. Handling Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_capability('my_plugin.manage');

    $secretInput = trim($_POST['my_plugin_secret'] ?? '');
    $clearSecret = !empty($_POST['my_plugin_clear_secret']);

    if ($clearSecret) {
        Database::setSetting('my_plugin_secret', null);
        AuditLog::record('my_plugin.secret_cleared');
    } elseif ($secretInput !== '') {
        try {
            $encrypted = slate_encrypt_secret($secretInput);
            if ($encrypted === null) {
                throw new \RuntimeException('Encryption failed');
            }
            Database::setSetting('my_plugin_secret', $encrypted);
            AuditLog::record('my_plugin.secret_updated');
        } catch (\Throwable $e) {
            flash_error('Failed to encrypt secret. Verify APP_SECRET configuration.');
            redirect_back();
            exit;
        }
    }
    // Note: If $secretInput === '' and !$clearSecret, existing secret is untouched.
}
```

In the HTML template:
```html
<div class="form-group">
    <label for="my_plugin_secret">API Secret Key</label>
    <div class="input-secret-wrapper">
        <input type="password" id="my_plugin_secret" name="my_plugin_secret" autocomplete="new-password" placeholder="<?php echo $isSecretConfigured ? '••••••••••••••••' : 'Enter secret key'; ?>">
        <?php if ($isSecretConfigured): ?>
            <span class="badge badge-success">Configured</span>
            <label class="clear-checkbox">
                <input type="checkbox" name="my_plugin_clear_secret" value="1"> Revoke / Clear
            </label>
        <?php endif; ?>
    </div>
</div>
```

---

## 4. Studio Builder Widget Implementation Pattern

When developing a new Studio Builder block or custom plugin widget:

1. **Register the Widget:** Declare the block in `StudioWidgetRegistry` with typed props:
```php
StudioWidgetRegistry::register('my-widget', [
    'title' => 'My Widget',
    'category' => 'content',
    'icon' => 'star',
    'schema' => [
        'title' => ['type' => 'string', 'default' => 'Default Title'],
        'columns' => ['type' => 'integer', 'default' => 3, 'min' => 1, 'max' => 6],
    ],
    'renderer' => MyWidgetRenderer::class,
]);
```

2. **Implement Server-Side Renderer (SSR):**
```php
namespace Kohevo\Module\StudioBuilder\Render\Block\ModuleRenderers;

use Kohevo\Module\StudioBuilder\Render\Block\BlockRendererInterface;

class MyWidgetRenderer implements BlockRendererInterface
{
    public function render(array $block, array $context): string
    {
        $props = $block['props'] ?? [];
        $title = htmlspecialchars($props['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $columns = max(1, min(6, (int)($props['columns'] ?? 3)));

        return sprintf(
            '<div class="my-widget my-widget-cols-%d" data-block-id="%s"><h3>%s</h3></div>',
            $columns,
            htmlspecialchars($block['id'] ?? '', ENT_QUOTES, 'UTF-8'),
            $title
        );
    }
}
```

3. **Secure Checkout Triggers:** If rendering payment elements, sign payloads using `slate_sign`:
```php
$signature = slate_sign($priceId . ':' . $amount . ':' . $currency, APP_SECRET);
```

---

## 5. Mandatory Verification Commands

Before concluding any implementation or refactoring task, execute these verification commands:

```bash
# 1. Syntax check all modified PHP files
php -l path/to/modified_file.php

# 2. Run Client Unit Suite (must achieve 100% pass)
php 01-client/tests/unit/run.php

# 3. Run Licensing Unit Suite (must achieve 100% pass)
php 02-licensing/tests/unit/run.php

# 4. Run System Settings Audit Regression Suite
php 01-client/tests/unit/run.php SystemSettingsAuditFixesTest
php 02-licensing/tests/unit/run.php SystemSettingsAuditFixesTest

# 5. Run Smoke Tests on both trees
php 01-client/tests/smoke.php
php 02-licensing/tests/smoke.php

# 6. Run Studio Builder UI Tests (when UI files are modified)
cd 01-client/plugins/studio-builder/ui && npm test

# 7. Check Git Working Tree Cleanliness
git status --short
```

---

## 6. Checklist for Adding New Features

- [ ] Evaluated impact on both `01-client` and `02-licensing`.
- [ ] Confirmed single canonical owner for every newly introduced setting.
- [ ] Implemented write-only handling and fail-closed encryption for any sensitive credential.
- [ ] Added server-side bounds clamping (`min`, `max`, enum validation).
- [ ] Context-aware HTML sanitization applied on all output strings.
- [ ] Added automated test cases in `tests/unit/`.
- [ ] Verified that existing tests continue to pass without regressions.
