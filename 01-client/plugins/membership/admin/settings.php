<?php
/**
 * Membership — settings. Stored in the core settings table under the
 * "membership." prefix, matching Plugin::setting() / MembershipAPI reads.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/MembershipAPI.php';

Auth::require();
Auth::requirePerm('membership.manage_settings');
ModuleGuard::require('membership');
MembershipAPI::ensureSchema();

$pageTitle  = 'Membership settings';
$currentNav = 'membership-settings';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        Database::setSetting('membership.insurance_fee_cents', (string) max(0, (int) round(((float)($_POST['insurance_fee'] ?? 0)) * 100)));
        Database::setSetting('membership.require_membership_to_book', !empty($_POST['require_membership_to_book']) ? '1' : '0');
        Database::setSetting('membership.require_profile_to_book',    !empty($_POST['require_profile_to_book']) ? '1' : '0');
        Database::setSetting('membership.enable_card',                !isset($_POST['enable_card']) ? '0' : '1');
        Database::setSetting('membership.enable_attendance',          !isset($_POST['enable_attendance']) ? '0' : '1');
        Database::setSetting('membership.enable_checkin_howto',       !isset($_POST['enable_checkin_howto']) ? '0' : '1');
        $insSvcs = array_filter(array_map('intval', (array)($_POST['insurance_services'] ?? [])));
        Database::setSetting('membership.insurance_required_services', implode(',', array_unique($insSvcs)));
        Database::setSetting('membership.expiry_reminder_days', preg_replace('/[^0-9,]/', '', (string)($_POST['expiry_reminder_days'] ?? '7,3')) ?: '7,3');
        Database::setSetting('membership.terms_url', trim((string)($_POST['terms_url'] ?? '')));

        AuditLog::record('membership.settings_updated', 'membership');
        $flash = ['type' => 'success', 'msg' => 'Settings saved.'];
    }
}

$g = fn(string $k, $d = '') => (string)(Database::setting('membership.' . $k) ?? $d);

// Insurance-required services — drawn from the Booking plugin when present.
$insSelected = array_filter(array_map('intval', explode(',', $g('insurance_required_services'))));
$bookingServices = [];
try {
    $hasBooking = (int) Database::value(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_services'") > 0;
    if ($hasBooking) {
        $bookingServices = Database::rows(
            "SELECT id, name FROM booking_services WHERE tenant_id = ? AND is_active = 1 ORDER BY name",
            [current_tenant_id()]);
    }
} catch (\Throwable $e) { /* booking not installed */ }

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('membership', 'Membership'), 'href' => plugin_url('membership', 'admin/index.php')],
    ['label' => __('membership_settings', 'Settings')],
]); ?>

<div class="page-header"><div><h1><?= __('membership_settings', 'Membership settings') ?></h1></div></div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_general', 'General') ?></h2></div>
        
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="enable_card" value="1" <?= $g('enable_card', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('membership_enable_card', 'Enable Digital Membership Card & QR Code') ?></span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="enable_attendance" value="1" <?= $g('enable_attendance', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('membership_enable_attendance', 'Enable Attendance Tracking & Recent Attendance') ?></span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="enable_checkin_howto" value="1" <?= $g('enable_checkin_howto', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('membership_enable_checkin_howto', 'Enable "How to check in" banner (Requires Card & Attendance)') ?></span>
            </label>
        </div>

        <div class="field" style="margin-top: 16px;">
            <label class="field-label" for="terms_url"><?= __('membership_terms_url', 'Terms & consent URL') ?></label>
            <input type="url" id="terms_url" name="terms_url" value="<?= e($g('terms_url')) ?>" placeholder="https://…">
        </div>
        <p class="field-hint">
            <?= sprintf(
                __('membership_currency_moved', 'Default currency is set once for the whole site under %s.'),
                '<a href="' . e(SLATE_URL) . '/admin/settings.php?tab=general">' . __('settings_general_link', 'Settings → General') . '</a>'
            ) ?>
        </p>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_booking_gate', 'Booking gate') ?></h2></div>
        <p class="text-sm text-muted"><?= __('membership_booking_gate_hint', 'These rules are enforced by the Booking plugin once the integration is wired (Phase 4). Configure them now so they take effect automatically.') ?></p>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="require_membership_to_book" value="1" <?= $g('require_membership_to_book', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('membership_require_active', 'Require an active membership to book') ?></span>
            </label>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="require_profile_to_book" value="1" <?= $g('require_profile_to_book', '1') !== '0' ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <span><?= __('membership_require_profile', 'Require a completed profile to book') ?></span>
            </label>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_insurance', 'Insurance') ?></h2></div>
        <div class="field">
            <label class="field-label" for="insurance_fee"><?= __('membership_ins_fee', 'Insurance add-on fee') ?></label>
            <input type="number" id="insurance_fee" name="insurance_fee" min="0" step="0.01"
                   value="<?= e(number_format(((int)$g('insurance_fee_cents'))/100, 2, '.', '')) ?>" style="max-width:200px;">
            <div class="field-hint"><?= __('membership_ins_fee_hint', 'One global fee, added when a plan includes insurance (optional or required). Configure per-plan insurance under Plans.') ?></div>
        </div>
        <hr style="border:none;border-top:1px solid var(--border);margin:var(--space-3) 0;">
        <p class="text-sm text-muted"><strong><?= __('membership_insurance_services', 'Insurance-required courses') ?></strong> — <?= __('membership_insurance_services_hint', 'Members must hold an active insurance plan to book the selected Booking courses (e.g. boxing).') ?></p>
        <?php if (!$bookingServices): ?>
            <div class="empty"><div class="empty-title"><?= __('membership_no_courses', 'No active Booking courses found.') ?></div></div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;">
                <?php foreach ($bookingServices as $svc): ?>
                    <label class="switch-label" style="gap:8px;">
                        <input type="checkbox" name="insurance_services[]" value="<?= (int)$svc['id'] ?>" <?= in_array((int)$svc['id'], $insSelected, true) ? 'checked' : '' ?>>
                        <span><?= e($svc['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('membership_reminders', 'Expiry reminders') ?></h2></div>
        <div class="field" style="margin-bottom:0;">
            <label class="field-label" for="expiry_reminder_days"><?= __('membership_reminder_days', 'Reminder lead days (comma-separated)') ?></label>
            <input type="text" id="expiry_reminder_days" name="expiry_reminder_days" value="<?= e($g('expiry_reminder_days', '7,3')) ?>" placeholder="7,3">
            <div class="field-hint"><?= __('membership_reminder_days_hint', 'e.g. 7,3 = email 7 days and 3 days before expiry. Sent by the cron (Phase 5).') ?></div>
        </div>
    </div>

    <div class="flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary"><?= __('membership_save_settings', 'Save settings') ?></button>
    </div>
</form>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
