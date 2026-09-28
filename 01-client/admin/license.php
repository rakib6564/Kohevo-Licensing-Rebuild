<?php
/**
 * Slate — License (Phase 8 client License UI; Phase 6 recovery screen).
 *
 * The one admin-reachable surface explicitly whitelisted from the Global
 * License Guard (docs/02-architecture/06-GLOBAL-LICENSE-GUARD.md §2, §9):
 * without it, a locked installation would have no way back to a licensed
 * state short of direct database access. Requires Auth but never License:
 * these are independent, stacked checks (06 §9), so this file must never
 * call slate_license_guard()/slate_license_gate() on itself.
 *
 * Phase 8 turns the recovery screen into the client-facing License page:
 * status, plan, expiry and optional-module entitlements for THIS
 * installation only. Everything shown comes from
 * Slate\Services\Licensing\LicenseStatusPresenter, a read-only view over
 * the same trusted state the Global License Guard and ModuleGuard use —
 * this page decides nothing about validity or entitlement itself.
 *
 * Authorization (server-side, stacked on Auth):
 *   - viewing        → settings.view
 *   - changing key   → settings.edit (POST, CSRF-protected)
 * The installer's first admin holds the Super Admin role, which passes both.
 * Neither is a Guard bypass: the whitelist entry for this exact path is
 * unchanged, and no other page becomes reachable through it.
 *
 * Two shells, one body: while the installation is usable, the page renders
 * inside the normal admin chrome (admin/partials/header.php). While the
 * Guard is locking, it keeps the standalone shell — the normal chrome's
 * navigation points at pages the Guard is actively blocking, and this
 * screen must render even when everything else is locked. Same precedent
 * install.php and error_page.php use: slate_ui_emit_css() alone.
 *
 * Never rendered: the raw license key (the input is never echoed back),
 * the installation ID, signatures, or any license-server setting.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Licensing\LicenseStatusPresenter;
use Slate\Services\Licensing\SlateLicenseCacheStore;

Auth::require();
Auth::requirePerm('settings.view');

$canManageLicense = Auth::can('settings.edit');

require_once dirname(__DIR__) . '/includes/installer_flow.php';
require_once dirname(__DIR__) . '/plugins/licensing/client/RemoteLicenseClient.php';

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif (!$canManageLicense) {
        http_response_code(403);
        $error = 'You do not have permission to change the license key.';
    } else {
        $licenseKeyInput = installer_normalize_license_key((string) ($_POST['license_key'] ?? ''));
        if ($licenseKeyInput['error'] !== null) {
            $error = $licenseKeyInput['error'];
        } else {
            $serverUrl = env('LICENSE_SERVER_URL', '');
            $publicKey = env('LICENSE_SERVER_PUBLIC_KEY', '');
            $product   = env('LICENSE_PRODUCT', '');
            $installId = \Slate\Services\Installation\InstallationService::currentInstallationId();

            if ($serverUrl === '' || $publicKey === '' || $product === '' || $installId === null) {
                $error = 'Licensing is not configured for this build. Contact your provider.';
            } else {
                try {
                    $store  = new SlateLicenseCacheStore((int) TENANT_ID);
                    $client = new RemoteLicenseClient([
                        'server_url'  => $serverUrl,
                        'public_key'  => $publicKey,
                        'product'     => $product,
                        'license_key' => $licenseKeyInput['value'],
                        'install_id'  => $installId,
                        'domain'      => installer_domain_from_url(SLATE_URL),
                        'app_version' => SLATE_VERSION,
                    ], $store);

                    $result = $client->checkInDetailed();
                    if ($result['ok']) {
                        // Persist the key that just verified so the unattended
                        // check-in cron (bin/license-check.php, whitelisted from
                        // this same Guard — 06 §5.1) can keep renewing this
                        // installation's state without an admin re-entering it
                        // every time. Reuses the installer's own idempotent
                        // .env writer; writes nothing else.
                        $envPath = SLATE_ROOT . '/.env';
                        $envBody = (string) file_get_contents($envPath);
                        $envBody = installer_set_env_line($envBody, 'LICENSE_KEY', $licenseKeyInput['value']);
                        if (@file_put_contents($envPath, $envBody) !== false) {
                            @chmod($envPath, 0640);
                        }

                        AuditLog::record('license.recovery_recheck', Auth::user()['email'] ?? '');
                        $success = 'License verified. This installation is now unlocked.';
                    } else {
                        slate_log('License recovery re-check failed: ' . ($client->lastFailure() ?? 'unknown'), 'warning');
                        $error = $result['reason'] === 'network'
                            ? 'Could not reach the licensing server. Check your connection and try again.'
                            : 'This license key could not be validated. Double-check the key and try again.';
                    }
                } catch (\Throwable $e) {
                    slate_log('License recovery re-check failed: ' . $e->getMessage(), 'error');
                    $error = 'This license key could not be validated. Double-check the key and try again.';
                }
            }
        }
    }
    unset($licenseKeyInput);
}

// Current state for display — read AFTER any re-check above, from the
// same trust sources the Guard and ModuleGuard use.
$license = LicenseStatusPresenter::current();

$licenseDate = static function (?string $value): string {
    if ($value === null) return '';
    $ts = strtotime($value);
    if ($ts === false) return '';
    return class_exists('I18n') ? I18n::localDate('M j, Y', $ts) : date('M j, Y', $ts);
};
$badgeClass = static fn(string $tone): string => match ($tone) {
    'success' => 'badge-active',
    'warning' => 'badge-warning',
    'danger'  => 'badge-danger',
    default   => 'badge-inactive',
};

$renderLicenseBody = static function () use ($license, $error, $success, $canManageLicense, $licenseDate, $badgeClass): void {
    $expiryText = '—';
    if ($license['show_details']) {
        // Phase 9: the exact UTC instant when known — expiry and grace
        // boundaries are enforced to the second, in UTC.
        $expiryText = $license['expires_label']
            ?? ($license['expires_at'] !== null ? $licenseDate($license['expires_at']) : 'No expiry date');
    }
    $verifiedText = $license['last_verified_at'] !== null ? $licenseDate($license['last_verified_at']) : '—';
    ?>
<style>
.lic-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.lic-grid > .card { margin: 0; }
.lic-status-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
.lic-status-head .badge { font-size: 12px; padding: 4px 10px; }
.lic-facts { display: grid; grid-template-columns: max-content 1fr; gap: 10px 20px; margin: 16px 0 0; }
.lic-facts dt { color: var(--muted); font-size: 13px; }
.lic-facts dd { margin: 0; font-weight: 600; font-size: 13.5px; }
.lic-modules { list-style: none; margin: 0; padding: 0; }
.lic-modules li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.lic-modules li:last-child { border-bottom: 0; }
.lic-module-name { display: inline-flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13.5px; }
.lic-module-mark { width: 18px; text-align: center; color: var(--muted); }
.lic-module-mark.is-on { color: var(--success); }
.lic-foot { font-size: 12.5px; color: var(--muted); margin: 12px 0 0; }
.lic-action { margin-top: 16px; }
@media (max-width: 860px) { .lic-grid { grid-template-columns: 1fr; } }
</style>

<div class="page-header">
    <div>
        <h1>License</h1>
        <p class="page-header-sub">The commercial license for this installation.</p>
    </div>
</div>

<?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= e($success) ?></div><?php endif; ?>
<?php if ($license['locked']): ?>
    <div class="alert alert-warning"><span>This installation is currently <strong>locked</strong>. <?= e($license['summary']) ?></span></div>
<?php endif; ?>
<?php if ($license['notice'] !== null): ?><div class="alert alert-info"><?= e($license['notice']) ?></div><?php endif; ?>
<?php if ($license['banner'] !== null):
    // Phase 9 — pre-expiry warning / commercial grace. This page is the
    // authoritative detailed view, so the admin-chrome banner is suppressed
    // here (admin/partials/header.php) and this one is shown instead. ?>
    <div class="alert <?= $license['banner']['tone'] === 'danger' ? 'alert-error' : 'alert-warning' ?>" role="status" data-license-banner="<?= e($license['banner']['phase']) ?>">
        <span><strong><?= e($license['banner']['title']) ?>.</strong> <?= e($license['banner']['message']) ?></span>
    </div>
<?php endif; ?>

<div class="lic-grid">
    <section class="card" aria-labelledby="lic-status-h">
        <div class="card-header"><h2 id="lic-status-h">Status</h2></div>
        <div class="lic-status-head">
            <span class="badge <?= e($badgeClass($license['tone'])) ?>" data-license-state="<?= e($license['state']) ?>"><span class="dot"></span> <?= e($license['label']) ?></span>
        </div>
        <p class="text-muted" style="margin:0"><?= e($license['summary']) ?></p>
        <dl class="lic-facts">
            <dt>Plan</dt>
            <dd data-license-plan><?= e($license['plan'] ?? '—') ?></dd>
            <dt><?= $license['state'] === 'grace' || $license['state'] === 'expired' ? 'Expired' : 'Expires' ?></dt>
            <dd data-license-expiry><?= e($expiryText) ?></dd>
            <?php if ($license['grace_ends_label'] !== null): ?>
            <dt><?= $license['locked'] ? 'Grace period ended' : 'Grace period ends' ?></dt>
            <dd data-license-grace-ends><?= e($license['grace_ends_label']) ?></dd>
            <?php endif; ?>
            <?php if ($license['time_remaining'] !== null): ?>
            <dt><?= $license['state'] === 'grace' ? 'Grace remaining' : 'Time remaining' ?></dt>
            <dd data-license-remaining><?= e($license['time_remaining']) ?></dd>
            <?php endif; ?>
            <dt>Last verified</dt>
            <dd><?= e($verifiedText) ?></dd>
        </dl>
    </section>

    <section class="card" aria-labelledby="lic-modules-h">
        <div class="card-header"><h2 id="lic-modules-h">Optional modules</h2></div>
        <ul class="lic-modules">
            <?php foreach ($license['modules'] as $module):
                $on = $module['state'] === 'enabled'; ?>
                <li data-module="<?= e($module['key']) ?>" data-module-state="<?= e($module['state']) ?>">
                    <span class="lic-module-name">
                        <span class="lic-module-mark<?= $on ? ' is-on' : '' ?>" aria-hidden="true"><?= $on ? '&#10003;' : '&ndash;' ?></span>
                        <?= e($module['label']) ?>
                    </span>
                    <span class="badge <?= $on ? 'badge-active' : 'badge-inactive' ?>"><?= e($module['state_label']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="lic-foot">Core features — dashboard, users and site settings — are included with every valid license.</p>
    </section>
</div>

<section class="card lic-action" aria-labelledby="lic-key-h">
    <div class="card-header"><h2 id="lic-key-h">License key</h2></div>
    <?php if ($canManageLicense): ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field">
                <label class="field-label" for="license_key">Enter a license key to activate or re-verify this installation</label>
                <input type="text" id="license_key" name="license_key" required
                       <?= $license['locked'] ? 'autofocus' : '' ?>
                       spellcheck="false" placeholder="Enter your license key">
                <p class="field-hint">For security, the current license key is never displayed.</p>
            </div>
            <button type="submit" class="btn btn-primary">Verify &amp; re-check</button>
        </form>
    <?php else: ?>
        <p class="text-muted" style="margin:0">Only an administrator with permission to change settings can update the license key.</p>
    <?php endif; ?>
</section>
    <?php
};

if (!$license['locked']) {
    $pageTitle  = 'License';
    $currentNav = 'license';
    require __DIR__ . '/partials/header.php';
    if (function_exists('slate_breadcrumbs')) {
        slate_breadcrumbs([['label' => 'License']]);
    }
    $renderLicenseBody();
    require __DIR__ . '/partials/footer.php';
    return;
}

require_once dirname(__DIR__) . '/includes/ui_components.php';
?>
<!doctype html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>License — <?= e(defined('SLATE_URL') ? SLATE_URL : 'Kohevo') ?></title>
<link rel="icon" href="<?= e(slate_favicon_url()) ?>">
<?php slate_ui_emit_css(); ?>
</head>
<body>
<div style="max-width:880px;margin:48px auto;padding:0 20px">
    <?php $renderLicenseBody(); ?>

    <p class="mt-3"><a href="<?= e(SLATE_URL) ?>/admin/">&larr; Back to admin</a>
        &nbsp;|&nbsp;
        <a href="<?= e(SLATE_URL) ?>/admin/logout.php?csrf=<?= e(csrf_token()) ?>">Log out</a></p>
</div>
</body>
</html>
