<?php
/**
 * Licensing — single License detail (Phase 2/3 schema): entitlements,
 * lifecycle actions, installation binding, and audit/event history.
 *
 * Thin admin layer over the already-implemented, already-tested domain
 * services (LicenseService, InstallationService, PlanService — see
 * tests/integration/CentralLicensingFoundationTest.php). Every lifecycle
 * precondition (docs/02-architecture/03-LICENSE-LIFECYCLE.md §3/§5) is
 * enforced by those services, not by this file — the buttons below are
 * shown contextually for UX only; the service call underneath is what
 * actually rejects an invalid transition (docs/00-project/DECISIONS.md §7:
 * "UI/menu hiding is not considered security").
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/LicensingAPI.php';
require_once dirname(__DIR__) . '/ModuleCatalog.php';
require_once dirname(__DIR__) . '/PlanService.php';
require_once dirname(__DIR__) . '/LicenseService.php';
require_once dirname(__DIR__) . '/InstallationService.php';

Auth::require();
Auth::requirePerm('licensing.manage');
LicensingAPI::ensureSchema();

function license_load(int $id): ?array {
    return Database::row(
        "SELECT l.*, c.name AS client_name, p.name AS product_name, p.slug AS product_slug, pl.name AS plan_name
           FROM licensing_licenses l
           JOIN licensing_clients c ON c.id = l.client_id
           JOIN licensing_products p ON p.id = l.product_id
           LEFT JOIN licensing_plans pl ON pl.id = l.plan_id
          WHERE l.id = ?", [$id]
    );
}

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$license = $id > 0 ? license_load($id) : null;
if (!$license) { http_response_code(404); echo 'License not found.'; exit; }

// Keep `status` truthful before rendering or acting on it — Renew is only
// reachable from a *stored* status of 'expired' (LicenseService::renew()),
// and this admin page is the primary place that transition actually gets
// persisted rather than waiting on the once-daily sweep (docs/02-architecture/
// 03-LICENSE-LIFECYCLE.md §2; LicenseService::syncExpiry()).
if (in_array($license['status'], ['active', 'trial'], true)) {
    LicenseService::syncExpiry($id);
    $license = license_load($id);
}

$pageTitle  = $license['label'] ?: ('#' . $id);
$currentNav = 'licensing-licenses';
$flash      = null;
$selectionCatalog = ModuleCatalog::selectionCatalog();
$actorId = Auth::userId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('licensing_csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        try {
            if ($action === 'update') {
                // expires_at is deliberately NOT editable here — it is a
                // lifecycle field, and every change to it must go through
                // LicenseService::renew()/extend() below so the transition
                // is validated against the license's current status and
                // recorded as an audited event (docs/02-architecture/
                // 03-LICENSE-LIFECYCLE.md §6). A direct field edit would let
                // a Revoked license's expiry be silently rewritten with no
                // audit trail and no lifecycle-precondition check.
                Database::update('licensing_licenses', [
                    'label'            => trim((string) ($_POST['label'] ?? '')),
                    'warning_days'     => max(0, (int) ($_POST['warning_days'] ?? 7)),
                    'grace_days'       => max(0, (int) ($_POST['grace_days'] ?? 7)),
                    'activation_limit' => max(1, (int) ($_POST['activation_limit'] ?? 1)),
                ], 'id = ?', [$id]);
                AuditLog::record('licensing.license_updated', (string) $id);
                $flash = ['type' => 'success', 'msg' => __('saved', 'Saved.')];
            } elseif ($action === 'set_modules') {
                $rawModules = $_POST['modules'] ?? [];
                if (!is_array($rawModules)) {
                    throw new \InvalidArgumentException(__('licensing_invalid_modules', 'Invalid module selection.'));
                }
                LicenseService::setModules($id, $rawModules);
                $desired = LicenseService::modules($id);
                AuditLog::record('licensing.license_modules_updated', (string) $id, ['modules' => $desired]);
                $flash = ['type' => 'success', 'msg' => __('licensing_modules_updated', 'Entitlements updated.')];
            } elseif ($action === 'bind_installation') {
                // Mirrors docs/02-architecture/03-LICENSE-LIFECYCLE.md §5: a
                // first-time activation attempt against a license that isn't
                // Unactivated/Trial/Active (Suspended, Revoked, Expired, or
                // Cancelled) has no defined meaning and must be rejected.
                // InstallationService::activate() enforces this itself now
                // (LicenseService::assertActivatable()) — this check is kept
                // only so the admin sees a clear message instead of a
                // generic one from the service layer.
                LicenseService::assertActivatable($license);
                $installationId = strtolower(trim((string) ($_POST['installation_id'] ?? '')));
                $domain = trim((string) ($_POST['domain'] ?? ''));
                if (!preg_match('/^[a-f0-9]{32}$/', $installationId)) {
                    throw new \InvalidArgumentException(__('licensing_invalid_installation_id', 'Installation ID must be exactly 32 lowercase hex characters.'));
                }
                if ($domain === '') {
                    throw new \InvalidArgumentException(__('licensing_domain_required', 'Domain is required.'));
                }
                // domain is validated/normalized inside InstallationService
                // (InstallationService::insertActiveInstallation()) — never
                // trust a normalized value computed here.
                InstallationService::activate($id, [
                    'installation_id' => $installationId,
                    'domain'          => $domain,
                ], $actorId);
                AuditLog::record('licensing.installation_bound', (string) $id, ['installation_id' => $installationId]);
                $flash = ['type' => 'success', 'msg' => __('licensing_installation_bound', 'Installation bound.')];
            } elseif ($action === 'reset_installation') {
                // Same lifecycle precondition as bind_installation above —
                // previously missing here, which let a suspended/revoked/
                // expired license be rebound via reset_installation even
                // though bind_installation already blocked it.
                LicenseService::assertActivatable($license);
                $installationId = strtolower(trim((string) ($_POST['installation_id'] ?? '')));
                $domain = trim((string) ($_POST['domain'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if (!preg_match('/^[a-f0-9]{32}$/', $installationId)) {
                    throw new \InvalidArgumentException(__('licensing_invalid_installation_id', 'Installation ID must be exactly 32 lowercase hex characters.'));
                }
                if ($domain === '' || $reason === '') {
                    throw new \InvalidArgumentException(__('licensing_reset_required', 'Domain and a reason are required to reset a binding.'));
                }
                InstallationService::reset($id, [
                    'installation_id' => $installationId,
                    'domain'          => $domain,
                ], $actorId, $reason);
                AuditLog::record('licensing.installation_reset', (string) $id, ['installation_id' => $installationId, 'reason' => $reason]);
                $flash = ['type' => 'success', 'msg' => __('licensing_installation_reset', 'Installation binding reset — the previous installation is preserved in history as superseded.')];
            } elseif ($action === 'revoke_installation') {
                $installationId = (int) ($_POST['installation_id'] ?? 0);
                // $id (the license this page is viewing) is passed as the
                // expected owner — InstallationService::revoke() rejects and
                // modifies nothing if $installationId actually belongs to a
                // different license (cross-license IDOR), before any audit
                // event is recorded.
                InstallationService::revoke($installationId, $id);
                AuditLog::record('licensing.installation_revoked', (string) $id, ['installation_row_id' => $installationId]);
                $flash = ['type' => 'success', 'msg' => __('licensing_installation_revoked', 'Installation binding revoked.')];
            } elseif ($action === 'suspend') {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') throw new \InvalidArgumentException(__('licensing_reason_required', 'A reason is required.'));
                LicenseService::suspend($id, $actorId, $reason);
                $flash = ['type' => 'success', 'msg' => __('licensing_suspended', 'License suspended.')];
            } elseif ($action === 'unsuspend') {
                LicenseService::unsuspend($id, $actorId, trim((string) ($_POST['reason'] ?? '')) ?: null);
                $flash = ['type' => 'success', 'msg' => __('licensing_unsuspended', 'License unsuspended.')];
            } elseif ($action === 'revoke') {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') throw new \InvalidArgumentException(__('licensing_reason_required', 'A reason is required.'));
                LicenseService::revoke($id, $actorId, $reason);
                $flash = ['type' => 'success', 'msg' => __('licensing_revoked', 'License revoked.')];
            } elseif ($action === 'renew') {
                $newExpires = trim((string) ($_POST['new_expires_at'] ?? ''));
                if ($newExpires === '') throw new \InvalidArgumentException(__('licensing_expiry_required', 'A new expiry date is required.'));
                LicenseService::renew($id, LicenseService::parseDate($newExpires), $actorId, trim((string) ($_POST['reason'] ?? '')) ?: null);
                $flash = ['type' => 'success', 'msg' => __('licensing_renewed', 'License renewed.')];
            } elseif ($action === 'extend') {
                $newExpires = trim((string) ($_POST['new_expires_at'] ?? ''));
                if ($newExpires === '') throw new \InvalidArgumentException(__('licensing_expiry_required', 'A new expiry date is required.'));
                LicenseService::extend($id, LicenseService::parseDate($newExpires), $actorId, trim((string) ($_POST['reason'] ?? '')) ?: null);
                $flash = ['type' => 'success', 'msg' => __('licensing_extended', 'License extended.')];
            } elseif ($action === 'refresh') {
                LicenseService::refresh($id, $actorId);
                $flash = ['type' => 'success', 'msg' => __('licensing_refreshed', 'License state re-validated (no change).')];
            }
        } catch (\PDOException $e) {
            // MUST be caught before \RuntimeException below — PDOException
            // extends RuntimeException in PHP, so without this dedicated,
            // narrower catch coming first, a raw database failure (e.g. the
            // `uniq_installation_identity` unique-key violation from
            // reusing an installation_id, or any other constraint failure)
            // would fall into the \RuntimeException handler and — since its
            // message is never the literal 'activation_limit' — have its
            // raw SQLSTATE/constraint message shown to the admin. Never
            // display $e->getMessage() here; log it instead.
            slate_log('Licensing admin action "' . $action . '" failed for license #' . $id . ': ' . $e->getMessage(), 'error');
            // SQLSTATE 23000 = integrity constraint violation. A safe,
            // specific-but-generic message for the one case worth naming
            // (duplicate installation_id) — never the underlying
            // constraint/table/column name itself.
            $flash = ['type' => 'error', 'msg' => $e->getCode() === '23000'
                ? __('licensing_duplicate_installation_id', 'That installation ID is already in use — choose a different one.')
                : __('licensing_action_failed', 'That action could not be completed.')];
        } catch (\InvalidArgumentException $e) {
            // Deliberately thrown by our own validation/domain code with an
            // already-safe, human-readable message — fine to show as-is.
            $flash = ['type' => 'error', 'msg' => $e->getMessage() ?: __('licensing_action_failed', 'That action could not be completed.')];
        } catch (\RuntimeException $e) {
            // InstallationService::activate() throws a bare 'activation_limit'
            // code (not a sentence) for this one specific, expected case.
            // Anything else reaching this handler is unexpected — treat it
            // the same as \Throwable below rather than showing its raw
            // message (a non-PDO RuntimeException could still originate
            // from library code with an unsafe message).
            if ($e->getMessage() === 'activation_limit') {
                $flash = ['type' => 'error', 'msg' => __('licensing_activation_limit_reached', 'This license has already reached its activation limit.')];
            } else {
                slate_log('Licensing admin action "' . $action . '" failed for license #' . $id . ': ' . $e->getMessage(), 'error');
                $flash = ['type' => 'error', 'msg' => __('licensing_action_failed', 'That action could not be completed.')];
            }
        } catch (\Throwable $e) {
            // Never surface a raw exception message here — it can carry
            // SQLSTATE codes, table/column names, or other database
            // internals (docs/01-audit/06-LICENSING-SECURITY-AUDIT.md).
            // Full detail goes to the existing server-side log instead.
            slate_log('Licensing admin action "' . $action . '" failed for license #' . $id . ': ' . $e->getMessage(), 'error');
            $flash = ['type' => 'error', 'msg' => __('licensing_action_failed', 'That action could not be completed.')];
        }
        $license = license_load($id);
    }
}

$modules = LicenseService::modules($id);
$activeInstallation = InstallationService::active($id);
$installationHistory = InstallationService::history($id);
$events = LicenseService::events($id);
$eventActorIds = array_filter(array_unique(array_column($events, 'actor_id')));
$actorNames = [];
if ($eventActorIds) {
    foreach (Database::rows('SELECT id, name FROM users WHERE id IN (' . implode(',', array_map('intval', $eventActorIds)) . ')') as $u) {
        $actorNames[(int) $u['id']] = $u['name'];
    }
}

$statusTone = [
    'unactivated' => 'muted', 'trial' => 'info', 'active' => 'active', 'expired' => 'warning',
    'suspended' => 'warning', 'revoked' => 'inactive', 'cancelled' => 'inactive',
];
$tone = $statusTone[$license['status']] ?? 'muted';
// Same rule LicenseService::assertActivatable() enforces server-side — used
// here only to decide whether the Bind/Reset forms are even shown
// (docs/00-project/DECISIONS.md §7: hiding is UX, not the actual gate).
$canBindOrReset = in_array($license['status'], LicenseService::ACTIVATABLE_STATUSES, true);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('licensing_nav_dashboard', 'Licensing'), 'href' => plugin_url('licensing', 'admin/index.php')],
    ['label' => __('licensing_nav_licenses', 'Licenses'), 'href' => plugin_url('licensing', 'admin/licenses.php')],
    ['label' => $license['label'] ?: ('#' . $id)],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e($license['label'] ?: ('#' . $id)) ?> <span class="badge badge-<?= e($tone) ?>"><?= e(ucfirst($license['status'])) ?></span></h1>
        <p class="page-header-sub"><?= e($license['client_name']) ?> · <?= e($license['product_name']) ?><?= $license['plan_name'] ? ' · ' . e($license['plan_name']) : '' ?></p>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_details', 'Details')) ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="label"><?= __('licensing_label', 'Label') ?></label>
                <input type="text" id="label" name="label" maxlength="190" value="<?= e((string) $license['label']) ?>">
            </div>
            <div class="field">
                <label class="field-label"><?= __('licensing_expires', 'Expires') ?></label>
                <p class="field-static"><?= $license['expires_at'] ? e(substr($license['expires_at'], 0, 10)) : e(__('licensing_never', 'Never')) ?></p>
                <p class="field-help"><?= __('licensing_expires_via_lifecycle', 'Change this using Renew or Extend below — every change to it is validated and audited there.') ?></p>
            </div>
        </div>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label" for="warning_days"><?= __('licensing_warning_days', 'Warning days') ?></label>
                <input type="number" id="warning_days" name="warning_days" min="0" step="1" value="<?= (int) $license['warning_days'] ?>">
            </div>
            <div class="field">
                <label class="field-label" for="grace_days"><?= __('licensing_grace_days', 'Grace days') ?></label>
                <input type="number" id="grace_days" name="grace_days" min="0" step="1" value="<?= (int) $license['grace_days'] ?>">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="activation_limit"><?= __('licensing_activation_limit', 'Activation limit') ?></label>
            <input type="number" id="activation_limit" name="activation_limit" min="1" step="1" value="<?= (int) $license['activation_limit'] ?>">
        </div>
        <button class="btn btn-primary" type="submit"><?= e(__('save', 'Save')) ?></button>
    </form>
    <p class="field-help mt-2">
        <?= __('licensing_issued', 'Issued') ?>: <?= e($license['issued_at']) ?> ·
        <?= __('licensing_starts', 'Starts') ?>: <?= e($license['starts_at']) ?>
        <?php if ($license['revoke_reason']): ?> · <?= __('licensing_revoke_reason', 'Revoke reason') ?>: <?= e($license['revoke_reason']) ?><?php endif; ?>
    </p>
</section>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_entitlements', 'Entitlements')) ?></h2></div>
    <p class="text-muted"><?= __('licensing_core_always_on', 'Core is always included and cannot be disabled:') ?> <?= implode(', ', LicenseService::CORE_MODULE_KEYS) ?></p>
    <?php
    $planRestricted = !empty($license['plan_id']) && PlanService::hasModuleRestrictions((int) $license['plan_id']);
    $planAllowed = $planRestricted ? PlanService::modules((int) $license['plan_id']) : ModuleCatalog::v1CommercialKeys();
    ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="set_modules">
        <div class="mcp-checkbox-row">
            <?php foreach ($selectionCatalog as $key => $catItem): ?>
                <?php if ($catItem['selectable']):
                    $allowedByPlan = in_array($key, $planAllowed, true);
                ?>
                    <label <?= !$allowedByPlan ? 'class="text-muted" style="opacity:0.65;cursor:not-allowed;"' : '' ?>>
                        <input type="checkbox" name="modules[]" value="<?= e($key) ?>"
                            <?= in_array($key, $modules, true) ? 'checked' : '' ?>
                            <?= !$allowedByPlan ? 'disabled aria-disabled="true"' : '' ?>>
                        <?= e($catItem['display_name']) ?>
                        <?php if (!$allowedByPlan): ?>
                            <span class="badge badge-inactive" style="font-size:10px;padding:1px 6px;">Not in plan</span>
                        <?php endif; ?>
                    </label>
                <?php else: ?>
                    <label class="text-muted" style="opacity:0.65;cursor:not-allowed;" title="<?= e($catItem['description']) ?>">
                        <input type="checkbox" disabled aria-disabled="true" value="<?= e($key) ?>">
                        <?= e($catItem['display_name']) ?>
                        <span class="badge badge-inactive" style="font-size:10px;padding:1px 6px;"><?= e((string) $catItem['badge']) ?></span>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <p class="field-help"><?= __('licensing_entitlements_catalog_hint', 'Supporting infrastructure (Stripe Payment) is automatically enabled on client installs when Membership or Booking is granted. Editor and Content are future modules (Not V1 / Future) and cannot be granted.') ?></p>
        <button class="btn btn-primary mt-2" type="submit"><?= e(__('save', 'Save')) ?></button>
    </form>
</section>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_installation', 'Installation')) ?></h2></div>
    <?php if ($activeInstallation): ?>
        <div class="mcp-grid">
            <div><strong><?= __('licensing_domain', 'Domain') ?></strong><p><?= e($activeInstallation['domain']) ?></p></div>
            <div><strong><?= __('licensing_installation_id', 'Installation ID') ?></strong><p style="font-family:ui-monospace,monospace;font-size:.8rem;"><?= e($activeInstallation['installation_id']) ?></p></div>
            <div><strong><?= __('licensing_first_activated', 'First activated') ?></strong><p><?= e($activeInstallation['first_activated_at']) ?></p></div>
            <div><strong><?= __('licensing_last_seen', 'Last seen') ?></strong><p><?= $activeInstallation['last_seen_at'] ? e($activeInstallation['last_seen_at']) : e(__('licensing_never', 'Never')) ?></p></div>
        </div>
        <div class="mcp-grid mt-2">
            <?php if ($canBindOrReset): ?>
            <details>
                <summary class="btn btn-sm"><?= e(__('licensing_reset_bindings', 'Reset (rebind to a new installation)')) ?></summary>
                <form method="post" class="mt-2" onsubmit="return confirm(<?= e(json_encode(__('licensing_reset_confirm', 'This supersedes the current installation and binds a new one. The old one is preserved in history. Continue?'))) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="reset_installation">
                    <div class="field"><label class="field-label"><?= __('licensing_installation_id', 'New installation ID (32 hex chars)') ?></label><input type="text" name="installation_id" maxlength="32" pattern="[a-f0-9]{32}" required></div>
                    <div class="field"><label class="field-label"><?= __('licensing_domain', 'New domain') ?></label><input type="text" name="domain" maxlength="190" required></div>
                    <div class="field"><label class="field-label"><?= __('licensing_reason', 'Reason') ?></label><input type="text" name="reason" maxlength="255" required placeholder="Server migration"></div>
                    <button class="btn btn-secondary" type="submit"><?= e(__('licensing_reset_bindings', 'Reset binding')) ?></button>
                </form>
            </details>
            <?php endif; ?>
            <form method="post" onsubmit="return confirm(<?= e(json_encode(__('licensing_revoke_installation_confirm', 'Revoke this installation binding? It will stop being treated as active but the activation slot is not freed — use Reset for that.'))) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="revoke_installation">
                <input type="hidden" name="installation_id" value="<?= (int) $activeInstallation['id'] ?>">
                <button class="btn btn-danger" type="submit"><?= e(__('licensing_revoke_installation', 'Revoke this binding')) ?></button>
            </form>
        </div>
        <?php if (!$canBindOrReset): ?>
            <p class="field-help mt-2"><?= e(__('licensing_reset_unavailable', 'Reset is unavailable while this license is in this status.')) ?></p>
        <?php endif; ?>
    <?php elseif ($canBindOrReset): ?>
        <p class="text-muted"><?= e(__('licensing_no_installation', 'No installation is currently bound to this license.')) ?></p>
        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('licensing_bind_confirm', 'Bind this installation to the license? This is normally done automatically by the client\'s first check-in.'))) ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="bind_installation">
            <div class="mcp-grid">
                <div class="field"><label class="field-label"><?= __('licensing_installation_id', 'Installation ID (32 hex chars)') ?></label><input type="text" name="installation_id" maxlength="32" pattern="[a-f0-9]{32}" required></div>
                <div class="field"><label class="field-label"><?= __('licensing_domain', 'Domain') ?></label><input type="text" name="domain" maxlength="190" required></div>
            </div>
            <button class="btn btn-secondary" type="submit"><?= e(__('licensing_bind_installation', 'Bind installation')) ?></button>
        </form>
    <?php else: ?>
        <p class="text-muted"><?= e(__('licensing_no_installation_and_unavailable', 'No installation is bound, and this license\'s status does not allow binding one.')) ?></p>
    <?php endif; ?>

    <?php if (count($installationHistory) > (int) (bool) $activeInstallation): ?>
        <h3 class="mt-3"><?= e(__('licensing_installation_history', 'History')) ?></h3>
        <div class="data-list" data-single-open>
            <?php foreach ($installationHistory as $h):
                $hStatus = (string) ($h['status'] ?? '');
                $hTone   = $hStatus === 'active' ? 'active' : 'muted';
                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr((string) ($h['domain'] ?? 'I'), 0, 1)),
                    'avatar_color' => $hStatus === 'active' ? 'accent' : 'muted',
                    'title'        => (string) ($h['domain'] ?? '—'),
                    'meta'         => ($h['first_activated_at'] ?? '—') . ($h['deleted_at'] ? ' → ' . $h['deleted_at'] : '') . ' · ' . ($h['installation_id'] ?? '—'),
                    'badge'        => [ucfirst($hStatus), $hTone],
                    'detail'       => [
                        __('licensing_domain', 'Domain')                         => (string) ($h['domain'] ?? '—'),
                        __('licensing_installation_id_short', 'Installation ID') => (string) ($h['installation_id'] ?? '—'),
                        __('licensing_status', 'Status')                         => ucfirst($hStatus),
                        __('licensing_first_activated', 'First activated')       => ($h['first_activated_at'] ?? '—') . ($h['deleted_at'] ? ' → ' . $h['deleted_at'] : ''),
                    ],
                ]);
            endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('licensing_lifecycle', 'Lifecycle')) ?></h2></div>
    <div class="mcp-checkbox-row">
        <?php if (in_array($license['status'], ['active', 'trial', 'expired'], true)): ?>
            <details><summary class="btn btn-secondary"><?= e(__('licensing_suspend', 'Suspend')) ?></summary>
                <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_action" value="suspend">
                    <div class="field"><input type="text" name="reason" maxlength="255" required placeholder="<?= e(__('licensing_reason_placeholder', 'Reason (required)')) ?>"></div>
                    <button class="btn btn-secondary" type="submit"><?= e(__('licensing_confirm', 'Confirm suspend')) ?></button>
                </form>
            </details>
        <?php endif; ?>
        <?php if ($license['status'] === 'suspended'): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="unsuspend">
                <button class="btn btn-secondary" type="submit"><?= e(__('licensing_unsuspend', 'Unsuspend')) ?></button>
            </form>
        <?php endif; ?>
        <?php if ($license['status'] === 'expired'): ?>
            <details><summary class="btn btn-secondary"><?= e(__('licensing_renew', 'Renew')) ?></summary>
                <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_action" value="renew">
                    <div class="field"><label class="field-label"><?= __('licensing_new_expiry', 'New expiry') ?></label><input type="date" name="new_expires_at" required></div>
                    <div class="field"><input type="text" name="reason" maxlength="255" placeholder="<?= e(__('licensing_reason_placeholder_optional', 'Reason (optional)')) ?>"></div>
                    <button class="btn btn-secondary" type="submit"><?= e(__('licensing_confirm', 'Confirm renew')) ?></button>
                </form>
            </details>
        <?php endif; ?>
        <?php if (in_array($license['status'], ['active', 'trial', 'expired'], true)): ?>
            <details><summary class="btn btn-secondary"><?= e(__('licensing_extend', 'Extend')) ?></summary>
                <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="_action" value="extend">
                    <div class="field"><label class="field-label"><?= __('licensing_new_expiry', 'New expiry') ?></label><input type="date" name="new_expires_at" required></div>
                    <div class="field"><input type="text" name="reason" maxlength="255" placeholder="<?= e(__('licensing_reason_placeholder_optional', 'Reason (optional)')) ?>"></div>
                    <button class="btn btn-secondary" type="submit"><?= e(__('licensing_confirm', 'Confirm extend')) ?></button>
                </form>
            </details>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="refresh">
            <button class="btn btn-ghost" type="submit"><?= e(__('licensing_refresh', 'Refresh (re-validate, no change)')) ?></button>
        </form>
        <?php if ($license['status'] !== 'revoked'): ?>
            <details><summary class="btn btn-danger"><?= e(__('licensing_revoke', 'Revoke')) ?></summary>
                <form method="post" class="mt-2" onsubmit="return confirm(<?= e(json_encode(__('licensing_revoke_confirm', 'Revoke this license? This is terminal.'))) ?>);">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="revoke">
                    <div class="field"><input type="text" name="reason" maxlength="255" required placeholder="<?= e(__('licensing_reason_placeholder', 'Reason (required)')) ?>"></div>
                    <button class="btn btn-danger" type="submit"><?= e(__('licensing_confirm', 'Confirm revoke')) ?></button>
                </form>
            </details>
        <?php endif; ?>
    </div>
</section>

<section class="card">
    <div class="card-header"><h2><?= e(__('licensing_event_history', 'Event history')) ?></h2></div>
    <?php if (!$events): ?>
        <p class="text-muted"><?= e(__('licensing_no_events', 'No events recorded yet.')) ?></p>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($events as $ev):
                $actorLabel = $ev['actor_type'] === 'system' ? __('licensing_system', 'System') : ($actorNames[(int) $ev['actor_id']] ?? ('#' . $ev['actor_id']));
                $evMeta     = ($ev['created_at'] ?? '—') . (!empty($ev['reason']) ? ' · ' . $ev['reason'] : '');
                slate_data_row([
                    'avatar'       => mb_strtoupper(mb_substr((string) ($ev['event_type'] ?? 'E'), 0, 1)),
                    'avatar_color' => 'muted',
                    'title'        => ucfirst((string) $ev['event_type']) . ' — ' . $actorLabel,
                    'meta'         => $evMeta,
                    'badge'        => [ucfirst((string) $ev['event_type']), 'muted'],
                    'detail'       => [
                        __('licensing_event', 'Event')         => ucfirst((string) $ev['event_type']),
                        __('licensing_actor', 'Actor')         => $actorLabel,
                        __('licensing_timestamp', 'Timestamp') => (string) ($ev['created_at'] ?? '—'),
                        __('licensing_reason', 'Reason')       => (string) ($ev['reason'] ?: '—'),
                    ],
                ]);
            endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-checkbox-row{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}
.mcp-checkbox-row label{display:flex;align-items:center;gap:6px;font-weight:400}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
.field-static{margin:0;padding:8px 0}
.mt-2{margin-top:8px}
.mt-3{margin-top:16px}
details summary{cursor:pointer;list-style:none}
details summary::-webkit-details-marker{display:none}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}}
</style>

<?php slate_data_list_script(); ?>
<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
