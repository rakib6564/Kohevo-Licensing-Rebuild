<?php
/**
 * Booking — Reserved Slots Administration.
 *
 * Reserve specific weekly time windows for designated services.
 * Any candidate slot considered by the booking flow is filtered against
 * these rules when the `slot_restrictions` capability is enabled.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

Auth::require();
Auth::requirePerm('booking.manage_services');
ModuleGuard::require('booking');

// The capability toggle in Booking → Settings must actually turn this page
// off, not just hide its nav link. Placed before any POST handling below, so
// a disabled capability also blocks adding/removing reserved slot windows,
// not just viewing them.
if (!PluginLoader::isCapabilityEnabled('booking', 'slot_restrictions')) {
    http_response_code(503);
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">Not available</div>'
       . '<p class="text-sm">Reserved slot windows are currently disabled. Enable them in Booking &rarr; Settings.</p></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

BookingPlusAPI::ensureSchema();

$pageTitle  = __('reserved_slots', 'Reserved Slots');
$currentNav = 'booking-restrictions';
$tid        = current_tenant_id();

$dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
             4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

$services  = Database::rows("SELECT id, name FROM booking_services  WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
$providers = Database::rows("SELECT id, name FROM booking_providers WHERE tenant_id = ? AND is_active = 1 ORDER BY name", [$tid]);

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Database::delete('bookingplus_slot_restrictions', 'id = ? AND tenant_id = ?', [$id, $tid]);
            $flash = ['type' => 'success', 'msg' => __('reserved_slot_removed', 'Reserved slot window removed.')];
        }
    } else {
        $sid   = (int)($_POST['service_id'] ?? 0);
        $pid   = (int)($_POST['provider_id'] ?? 0);
        $dow   = (int)($_POST['day_of_week'] ?? -1);
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end   = trim((string)($_POST['end_time'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));

        if ($sid <= 0)                            $flash = ['type' => 'error', 'msg' => __('select_service', 'Please select a service.')];
        elseif ($dow < 0 || $dow > 6)             $flash = ['type' => 'error', 'msg' => __('select_day', 'Please pick a day of the week.')];
        elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end))
                                                  $flash = ['type' => 'error', 'msg' => __('times_hh_mm', 'Times must be in HH:MM format.')];
        elseif ($end <= $start)                   $flash = ['type' => 'error', 'msg' => __('end_after_start', 'End time must be after start time.')];
        else {
            Database::insert('bookingplus_slot_restrictions', [
                'tenant_id'   => $tid,
                'service_id'  => $sid,
                'provider_id' => $pid > 0 ? $pid : null,
                'day_of_week' => $dow,
                'start_time'  => $start . ':00',
                'end_time'    => $end   . ':00',
                'label'       => $label !== '' ? mb_substr($label, 0, 80) : null,
            ]);
            $flash = ['type' => 'success', 'msg' => __('reserved_slot_added', 'Reserved slot window added.')];
        }
    }
}

$rules = Database::rows(
    "SELECT r.*, s.name AS service_name, p.name AS provider_name
       FROM bookingplus_slot_restrictions r
       JOIN booking_services  s ON s.id = r.service_id
  LEFT JOIN booking_providers p ON p.id = r.provider_id
      WHERE r.tenant_id = ?
      ORDER BY r.day_of_week, r.start_time",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'),     'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('reserved_slots', 'Reserved Slots')],
]);
?>

<div class="page-header">
    <div>
        <h1><?= __('reserved_slots', 'Reserved Slots') ?></h1>
        <p class="text-muted"><?= __('reserved_slots_sub', 'Reserve specific weekly time windows exclusively for a specific service (e.g. Discovery Call only Thursdays 12:00-14:00).') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-4);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
    <h2 style="margin-top:0;font-size:16px;"><?= __('add_reserved_slot', 'Add reserved slot window') ?></h2>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:var(--space-3);align-items:end;">
        <?= csrf_field() ?>

        <div class="field" style="margin:0;">
            <label class="field-label" for="dow"><?= __('day', 'Day') ?></label>
            <select id="dow" name="day_of_week" required>
                <option value=""><?= __('select', 'Select…') ?></option>
                <?php foreach ($dayNames as $idx => $n): ?>
                    <option value="<?= $idx ?>"><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0;">
            <label class="field-label" for="start_time"><?= __('start', 'Start (HH:MM)') ?></label>
            <input type="text" id="start_time" name="start_time" placeholder="09:00" pattern="^\d{2}:\d{2}$" required>
        </div>

        <div class="field" style="margin:0;">
            <label class="field-label" for="end_time"><?= __('end', 'End (HH:MM)') ?></label>
            <input type="text" id="end_time" name="end_time" placeholder="12:00" pattern="^\d{2}:\d{2}$" required>
        </div>

        <div class="field" style="margin:0;">
            <label class="field-label" for="sid"><?= __('service', 'Service') ?></label>
            <select id="sid" name="service_id" required>
                <option value=""><?= __('select_service', 'Select service…') ?></option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0;">
            <label class="field-label" for="pid"><?= __('provider', 'Provider (optional)') ?></label>
            <select id="pid" name="provider_id">
                <option value="0"><?= __('all_providers', 'All providers') ?></option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin:0;">
            <label class="field-label" for="label"><?= __('label', 'Label (optional)') ?></label>
            <input type="text" id="label" name="label" placeholder="e.g. Consultations only">
        </div>

        <div>
            <button type="submit" class="btn btn-primary" style="width:100%;"><?= __('add', 'Add window') ?></button>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <h2><?= __('active_windows', 'Active reserved windows') ?> (<?= count($rules) ?>)</h2>
    </div>
    <?php if (!$rules): ?>
        <div class="empty">
            <div class="empty-title"><?= __('no_windows', 'No reserved windows') ?></div>
            <p class="text-sm"><?= __('no_windows_sub', 'All active services are bookable during any provider availability.') ?></p>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($rules as $r):
                $dayLabel = $dayNames[(int)$r['day_of_week']] ?? '—';
                $hours    = substr((string)$r['start_time'], 0, 5) . ' – ' . substr((string)$r['end_time'], 0, 5);
                $provider = $r['provider_name'] ?? __('all_providers', 'All providers');
                $label    = $r['label'] ?? '—';

                ob_start(); ?>
                <form method="post" onsubmit="return confirm('Remove this reserved slot window?');" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-xs"><?= __('delete', 'Delete') ?></button>
                </form>
                <?php $rowActions = ob_get_clean();

                slate_data_row([
                    'avatar'       => mb_substr((string)$dayLabel, 0, 2),
                    'avatar_color' => 'accent',
                    'title'        => (string)$dayLabel,
                    'meta'         => $hours,
                    'badge'        => [(string)$r['service_name'], 'active'],
                    'detail'       => [
                        __('hours', 'Hours')    => $hours,
                        __('provider', 'Provider') => (string)$provider,
                        __('label', 'Label')    => (string)$label,
                    ],
                    'actions'      => $rowActions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
