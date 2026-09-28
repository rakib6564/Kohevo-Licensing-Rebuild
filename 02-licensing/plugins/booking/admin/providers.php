<?php
/**
 * Booking — providers CRUD + weekly hours editor + service links.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_providers');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_providers', 'Providers');
$currentNav = 'booking-providers';

$tid    = current_tenant_id();
$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing       = null;
$linked        = [];
$linkByService = [];
$hours         = [];
$breaks        = [];
$overrides     = [];
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_providers WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
    else {
        $linkRows = Database::rows(
            "SELECT * FROM booking_provider_services WHERE provider_id = ?", [$editId]
        );
        $linked    = array_map('intval', array_column($linkRows, 'service_id'));
        $linkByService = [];
        foreach ($linkRows as $lr) $linkByService[(int)$lr['service_id']] = $lr;
        $hours     = BookingAPI::getProviderHours($editId);
        $breaks    = BookingAPI::getProviderBreaks($editId);
        $overrides = BookingAPI::getDateOverrides($editId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('booking_name_required', 'Name is required.')];
            } else {
                $row = [
                    'tenant_id' => $tid,
                    'name'      => mb_substr($name, 0, 160),
                    'email'     => trim((string)($_POST['email'] ?? '')) !== ''
                                   && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)
                                   ? mb_substr(trim($_POST['email']), 0, 200) : null,
                    'timezone'  => mb_substr(trim((string)($_POST['timezone'] ?? 'UTC')), 0, 60),
                    'bio'       => trim((string)($_POST['bio'] ?? '')) ?: null,
                    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if ($id > 0) {
                    Database::update('booking_providers', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                    AuditLog::record('booking.provider_updated', (string)$id);
                } else {
                    $id = Database::insert('booking_providers', $row);
                    AuditLog::record('booking.provider_created', (string)$id);
                }

                // Sync linked services + per-staff price/duration overrides.
                // A service id must belong to this tenant — booking_provider_
                // services has no tenant_id column of its own, so without this
                // check a submitted id from another tenant's catalog would be
                // linked to this provider as-is.
                $validServiceIds = array_flip(array_map(
                    'intval',
                    array_column(Database::rows('SELECT id FROM booking_services WHERE tenant_id = ?', [$tid]), 'id')
                ));
                Database::delete('booking_provider_services', 'provider_id = ?', [$id]);
                foreach ((array)($_POST['services'] ?? []) as $sid) {
                    $sid = (int)$sid;
                    if ($sid <= 0 || !isset($validServiceIds[$sid])) continue;
                    $pRaw = trim((string)($_POST['price_' . $sid] ?? ''));
                    $dRaw = trim((string)($_POST['dur_' . $sid] ?? ''));
                    Database::insert('booking_provider_services', [
                        'provider_id'  => $id,
                        'service_id'   => $sid,
                        'price_cents'  => $pRaw !== '' ? max(0, (int)round(((float)$pRaw) * 100)) : null,
                        'duration_min' => $dRaw !== '' ? max(0, (int)$dRaw) : null,
                    ]);
                }

                // Sync hours — full replace
                Database::delete('booking_provider_hours', 'provider_id = ? AND tenant_id = ?', [$id, $tid]);
                for ($d = 0; $d <= 6; $d++) {
                    $on    = !empty($_POST['day_' . $d . '_on']);
                    $start = (string)($_POST['day_' . $d . '_start'] ?? '');
                    $end   = (string)($_POST['day_' . $d . '_end']   ?? '');
                    if (!$on) continue;
                    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
                    if ($end <= $start) continue;
                    Database::insert('booking_provider_hours', [
                        'tenant_id'   => $tid,
                        'provider_id' => $id,
                        'day_of_week' => $d,
                        'start_time'  => $start . ':00',
                        'end_time'    => $end   . ':00',
                    ]);
                }

                // Sync breaks — one optional break block per weekday.
                Database::delete('booking_provider_breaks', 'provider_id = ? AND tenant_id = ?', [$id, $tid]);
                for ($d = 0; $d <= 6; $d++) {
                    if (empty($_POST['break_' . $d . '_on'])) continue;
                    $bs = (string)($_POST['break_' . $d . '_start'] ?? '');
                    $be = (string)($_POST['break_' . $d . '_end']   ?? '');
                    if (!preg_match('/^\d{2}:\d{2}$/', $bs) || !preg_match('/^\d{2}:\d{2}$/', $be) || $be <= $bs) continue;
                    Database::insert('booking_provider_breaks', [
                        'tenant_id'   => $tid,
                        'provider_id' => $id,
                        'day_of_week' => $d,
                        'start_time'  => $bs . ':00',
                        'end_time'    => $be . ':00',
                        'label'       => mb_substr(trim((string)($_POST['break_' . $d . '_label'] ?? '')), 0, 80) ?: null,
                    ]);
                }

                $flash = ['type' => 'success', 'msg' => __('booking_provider_saved', 'Provider saved.')];
                header('Location: ?edit=' . $id . '&saved=1');
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('booking_provider_services', 'provider_id = ?', [$id]);
                Database::delete('booking_provider_hours',    'provider_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('booking_provider_breaks',   'provider_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('booking_date_overrides',    'provider_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('booking_providers',         'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.provider_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_provider_deleted', 'Provider deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/providers.php') . '?deleted=1');
                exit;
            }
        } elseif ($action === 'add_override') {
            $pid  = (int)($_POST['provider_id'] ?? 0);
            $date = (string)($_POST['date'] ?? '');
            if ($pid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $closed = !empty($_POST['is_closed']) ? 1 : 0;
                $os = (string)($_POST['ov_start'] ?? '');
                $oe = (string)($_POST['ov_end'] ?? '');
                Database::insert('booking_date_overrides', [
                    'tenant_id'   => $tid,
                    'provider_id' => $pid,
                    'date'        => $date,
                    'is_closed'   => $closed,
                    'start_time'  => (!$closed && preg_match('/^\d{2}:\d{2}$/', $os)) ? $os . ':00' : null,
                    'end_time'    => (!$closed && preg_match('/^\d{2}:\d{2}$/', $oe)) ? $oe . ':00' : null,
                    'note'        => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 160) ?: null,
                ]);
                AuditLog::record('booking.override_added', (string)$pid);
            }
            header('Location: ?edit=' . $pid);
            exit;
        } elseif ($action === 'del_override') {
            $oid = (int)($_POST['override_id'] ?? 0);
            $pid = (int)($_POST['provider_id'] ?? 0);
            if ($oid > 0) Database::delete('booking_date_overrides', 'id = ? AND tenant_id = ?', [$oid, $tid]);
            header('Location: ?edit=' . $pid);
            exit;
        }
    }
}

$providers = Database::rows(
    "SELECT * FROM booking_providers WHERE tenant_id = ? ORDER BY name", [$tid]
);
$allServices = Database::rows(
    "SELECT id, name FROM booking_services WHERE tenant_id = ? AND is_active = 1 ORDER BY name", [$tid]
);

// Build hours + breaks maps for display
$hoursByDay = array_fill(0, 7, null);
foreach ($hours as $h) $hoursByDay[(int)$h['day_of_week']] = $h;
$breaksByDay = array_fill(0, 7, null);
foreach ($breaks as $b) $breaksByDay[(int)$b['day_of_week']] = $b;

require SLATE_ROOT . '/admin/partials/header.php';

$dayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$dayFull  = [
    __('sunday', 'Sunday'),
    __('monday', 'Monday'),
    __('tuesday', 'Tuesday'),
    __('wednesday', 'Wednesday'),
    __('thursday', 'Thursday'),
    __('friday', 'Friday'),
    __('saturday', 'Saturday'),
];

// Snapshot stats for the editor hero (reflects the last-saved schedule).
$pvActiveDays = 0; $pvWeekMin = 0;
for ($d = 0; $d <= 6; $d++) {
    $h = $hoursByDay[$d];
    if (!$h) continue;
    $pvActiveDays++;
    $mins = (strtotime($h['end_time']) - strtotime($h['start_time'])) / 60;
    $b = $breaksByDay[$d];
    if ($b) $mins -= max(0, (strtotime($b['end_time']) - strtotime($b['start_time'])) / 60);
    $pvWeekMin += max(0, $mins);
}
$pvWeekHours = rtrim(rtrim(number_format($pvWeekMin / 60, 1), '0'), '.');
$pvSvcCount  = count($linked);

// Confirmation flash after a PRG redirect (the $flash var is lost across the
// redirect, so re-derive it from the query string on the follow-up GET).
if (!$flash && isset($_GET['saved']))   $flash = ['type' => 'success', 'msg' => __('booking_provider_saved', 'Provider saved.')];
if (!$flash && isset($_GET['deleted'])) $flash = ['type' => 'success', 'msg' => __('booking_provider_deleted', 'Provider deleted.')];
if (!$flash && isset($_GET['gcal_connected']))    $flash = ['type' => 'success', 'msg' => __('booking_provider_gcal_connected', 'Google Calendar connected') . ($_GET['gcal_connected'] !== '1' ? ' (' . $_GET['gcal_connected'] . ')' : '') . '.'];
if (!$flash && isset($_GET['gcal_disconnected'])) $flash = ['type' => 'success', 'msg' => __('booking_provider_gcal_disconnected', 'Google Calendar disconnected') . '.'];
if (!$flash && isset($_GET['gcal_error']))        $flash = ['type' => 'error',   'msg' => (string)$_GET['gcal_error']];
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_providers', 'Providers')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $p = $editing ?: ['id'=>0,'name'=>'','email'=>'','timezone'=>'UTC','bio'=>'','is_active'=>1];
    $pInitials = ($editing && trim((string)$editing['name']) !== '') ? mb_strtoupper(mb_substr($editing['name'], 0, 2)) : '–';
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_provider_new', 'New provider'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/providers.php'),
            'back_label' => __('all_providers', 'All providers'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'user',
            'initials'     => $pInitials,
            'title'        => $editing ? $editing['name'] : __('booking_provider_new', 'New provider'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($p['is_active']),
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_provider_active_toggle', 'Active (bookable)'),
            'status_on'    => __('booking_provider_active_status', 'Active · bookable'),
            'status_off'   => __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_provider_stat_active_days', 'Active days'),  (int)$pvActiveDays . '<small>/7</small>'],
                [__('booking_provider_stat_hours_week', 'Hours / week'), e($pvWeekHours) . '<small>h</small>'],
                [__('services', 'Services'),     (string)(int)$pvSvcCount],
            ] : [],
        ]); ?>

        <?php slate_page_layout('with-aside'); ?>
            <div class="page-main">
                <?php booking_edit_card_open(['icon' => 'user', 'eyebrow' => __('booking_general', 'General'), 'title' => __('booking_provider_details', 'Provider details')]); ?>
                    <div class="field-row field-row-3">
                        <div class="field">
                            <label class="field-label" for="name"><?= e(__('name', 'Name')) ?> <span class="field-required">*</span></label>
                            <input type="text" id="name" name="name" required maxlength="160" value="<?= e($p['name']) ?>" placeholder="<?= e(__('booking_provider_name_ph', 'Jane Doe')) ?>">
                        </div>
                        <div class="field">
                            <label class="field-label" for="email"><?= e(__('email', 'Email')) ?></label>
                            <input type="email" id="email" name="email" maxlength="200" value="<?= e($p['email'] ?? '') ?>" placeholder="<?= e(__('booking_provider_email_ph', 'jane@studio.com')) ?>">
                        </div>
                        <div class="field">
                            <label class="field-label" for="timezone"><?= e(__('booking_provider_timezone', 'Timezone')) ?></label>
                            <input type="text" id="timezone" name="timezone" maxlength="60" value="<?= e($p['timezone']) ?>" placeholder="<?= e(__('booking_provider_timezone_ph', 'UTC')) ?>">
                        </div>
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label class="field-label" for="bio"><?= e(__('booking_provider_bio', 'Bio')) ?></label>
                        <textarea id="bio" name="bio" maxlength="2000" placeholder="<?= e(__('booking_provider_bio_ph', 'Short bio shown on the booking page')) ?>"><?= e($p['bio'] ?? '') ?></textarea>
                        <div class="field-hint"><?= e(__('booking_provider_details_hint', 'Timezone uses IANA names (UTC, America/New_York…). Bio appears on the public booking page.')) ?></div>
                    </div>
                <?php booking_edit_card_close(); ?>

                <?php booking_edit_card_open(['icon' => 'clock', 'eyebrow' => __('booking_provider_availability', 'Availability'), 'title' => __('booking_provider_weekly_hours', 'Weekly hours')]); ?>
                    <?php booking_edit_card_note(__('booking_provider_hours_note', 'Toggle a day on to make the provider bookable. Times are local to the site timezone.')); ?>
                    <?php booking_edit_days_open(); ?>
                    <?php for ($d = 0; $d <= 6; $d++):
                        $h     = $hoursByDay[$d];
                        $on    = (bool)$h;
                        $start = $h ? substr($h['start_time'], 0, 5) : '09:00';
                        $end   = $h ? substr($h['end_time'],   0, 5) : '17:00';
                        booking_edit_day_row([
                            'toggle_name' => 'day_' . $d . '_on',
                            'checked'     => $on,
                            'label'       => $dayFull[$d],
                            'times_html'  =>
                                '<input type="time" name="day_' . $d . '_start" value="' . e($start) . '" aria-label="' . e($dayFull[$d]) . ' start">'
                              . '<span class="sep">→</span>'
                              . '<input type="time" name="day_' . $d . '_end" value="' . e($end) . '" aria-label="' . e($dayFull[$d]) . ' end">',
                        ]);
                    endfor; ?>
                    <?php booking_edit_days_close(); ?>
                <?php booking_edit_card_close(); ?>

                <?php booking_edit_card_open(['icon' => 'coffee', 'eyebrow' => __('booking_provider_availability', 'Availability'), 'title' => __('booking_provider_breaks', 'Breaks')]); ?>
                    <?php booking_edit_card_note(__('booking_provider_breaks_note', 'One optional break per day, subtracted from working hours.')); ?>
                    <?php for ($d = 0; $d <= 6; $d++):
                        $b    = $breaksByDay[$d];
                        $bon  = (bool)$b;
                        $bs   = $b ? substr($b['start_time'], 0, 5) : '12:00';
                        $be   = $b ? substr($b['end_time'],   0, 5) : '13:00';
                        $blab = $b['label'] ?? '';
                        booking_edit_day_row([
                            'toggle_name' => 'break_' . $d . '_on',
                            'checked'     => $bon,
                            'label'       => $dayFull[$d],
                            'times_html'  =>
                                '<input type="time" name="break_' . $d . '_start" value="' . e($bs) . '" aria-label="' . e($dayFull[$d]) . ' break start">'
                              . '<span class="sep">→</span>'
                              . '<input type="time" name="break_' . $d . '_end" value="' . e($be) . '" aria-label="' . e($dayFull[$d]) . ' break end">'
                              . '<input type="text" name="break_' . $d . '_label" maxlength="80" placeholder="' . e(__('booking_provider_break_label_ph', 'Lunch')) . '" value="' . e($blab) . '" aria-label="' . e($dayFull[$d]) . ' break label">',
                        ]);
                    endfor; ?>
                <?php booking_edit_card_close(); ?>
            </div>

            <aside class="page-aside">
                <?php booking_edit_card_open(['icon' => 'tag', 'eyebrow' => __('services', 'Services'), 'title' => __('booking_provider_services_offered', 'Services offered')]); ?>
                    <?php if (!$allServices): ?>
                        <?php booking_edit_card_note(__('booking_provider_no_services_note', 'No services yet.') . ' <a href="' . e(plugin_url('booking', 'admin/services.php')) . '">' . __('booking_create_one', 'Create one') . '</a>.'); ?>
                    <?php else: ?>
                        <?php booking_edit_card_note(__('booking_provider_services_note', 'Toggle a service on. Leave price / minutes blank to inherit the service default.')); ?>
                        <?php foreach ($allServices as $s):
                            $sid  = (int)$s['id'];
                            $link = $linkByService[$sid] ?? null;
                            $isOn = in_array($sid, $linked, true);
                            $pvv  = ($link && $link['price_cents'] !== null && $link['price_cents'] !== '') ? number_format(((int)$link['price_cents'])/100, 2, '.', '') : '';
                            $dv   = ($link && $link['duration_min'] !== null && $link['duration_min'] !== '') ? (int)$link['duration_min'] : '';
                            booking_edit_toggle_row([
                                'name'        => 'services[]',
                                'value'       => $sid,
                                'checked'     => $isOn,
                                'label'       => $s['name'],
                                'fields_html' =>
                                    '<input type="number" name="price_' . $sid . '" min="0" step="0.01" placeholder="' . e(__('booking_price', 'Price')) . '" value="' . e((string)$pvv) . '" aria-label="' . e($s['name']) . ' ' . e(__('booking_price', 'price')) . '">'
                                  . '<input type="number" name="dur_' . $sid . '" min="0" step="5" placeholder="' . e(__('booking_provider_duration_ph', 'Min')) . '" value="' . e((string)$dv) . '" aria-label="' . e($s['name']) . ' ' . e(__('booking_provider_duration_aria', 'duration')) . '">',
                            ]);
                        endforeach; ?>
                    <?php endif; ?>
                <?php booking_edit_card_close(); ?>
            </aside>
        <?php slate_page_layout_end(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('save_changes', 'Save changes')) : e(__('booking_provider_create', 'Create provider'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/providers.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>

    <?php if ($editing): ?>
        <?php booking_edit_card_open(['icon' => 'calendar', 'eyebrow' => __('booking_provider_exceptions', 'Exceptions'), 'title' => __('booking_provider_date_overrides', 'Date overrides')]); ?>
            <?php booking_edit_card_note(__('booking_provider_overrides_note', 'Close a specific date (holiday) or set special hours that replace the weekly schedule.')); ?>
            <form method="post" class="field-row field-row-4" style="align-items:flex-end;">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="add_override">
                <input type="hidden" name="provider_id" value="<?= (int)$editing['id'] ?>">
                <div class="field">
                    <label class="field-label" for="ov_date"><?= e(__('booking_date', 'Date')) ?></label>
                    <input type="date" id="ov_date" name="date" required>
                </div>
                <div class="field">
                    <label class="field-label" for="ov_start"><?= e(__('booking_provider_open_optional', 'Open (optional)')) ?></label>
                    <input type="time" id="ov_start" name="ov_start">
                </div>
                <div class="field">
                    <label class="field-label" for="ov_end"><?= e(__('booking_provider_close_optional', 'Close (optional)')) ?></label>
                    <input type="time" id="ov_end" name="ov_end">
                </div>
                <div class="field" style="display:flex;align-items:flex-end;">
                    <label class="switch-label">
                        <span class="switch"><input type="checkbox" name="is_closed" value="1" checked><span class="switch-track"></span></span>
                        <span><?= e(__('booking_provider_closed', 'Closed')) ?></span>
                    </label>
                </div>
                <div class="field" style="grid-column:1/-1;display:flex;gap:8px;align-items:flex-end;">
                    <input type="text" name="note" maxlength="160" placeholder="<?= e(__('booking_provider_note_ph', 'Note (e.g. Public holiday)')) ?>" style="flex:1;">
                    <button type="submit" class="btn btn-primary"><?= e(__('booking_provider_add_override', 'Add override')) ?></button>
                </div>
            </form>

            <?php if ($overrides): ?>
                <div class="data-list mt-3">
                    <?php foreach ($overrides as $ov):
                        $label = (int)$ov['is_closed'] === 1
                            ? __('booking_provider_closed', 'Closed')
                            : __('booking_provider_open_prefix', 'Open') . ' ' . substr((string)$ov['start_time'], 0, 5) . '–' . substr((string)$ov['end_time'], 0, 5);
                        $closed = (int)$ov['is_closed'] === 1;
                    ?>
                        <div class="pv-ov">
                            <span>
                                <span class="pv-ov-date"><?= e($ov['date']) ?></span>
                                <span class="badge <?= $closed ? 'badge-danger' : 'badge-active' ?>" style="margin-left:8px;"><?= e($label) ?></span>
                                <?= !empty($ov['note']) ? ' <span class="text-muted text-sm">· ' . e($ov['note']) . '</span>' : '' ?>
                                <?= $ov['provider_id'] === null ? ' <span class="badge badge-inactive">' . e(__('booking_provider_business_wide', 'business-wide')) . '</span>' : '' ?>
                            </span>
                            <?php if ($ov['provider_id'] !== null): ?>
                                <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('booking_provider_confirm_remove_override', 'Remove this override?')) ?>')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="del_override">
                                    <input type="hidden" name="provider_id" value="<?= (int)$editing['id'] ?>">
                                    <input type="hidden" name="override_id" value="<?= (int)$ov['id'] ?>">
                                    <button class="btn btn-sm btn-danger"><?= e(__('booking_remove', 'Remove')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php booking_edit_card_close(); ?>

        <?php
        $gEnabled   = (string)(Database::setting('booking.google_enabled') ?? '0') === '1';
        $gConfigured = GoogleCalendarSync::isConfigured();
        $gConnected  = !empty($editing['google_refresh_token']);
        ?>
        <?php booking_edit_card_open(['icon' => 'calendar', 'eyebrow' => __('booking_provider_integrations', 'Integrations'), 'title' => __('booking_provider_gcal', 'Google Calendar')]); ?>
            <?php if (!$gEnabled || !$gConfigured): ?>
                <?php booking_edit_card_note(
                    __('booking_provider_gcal_off_1', 'Google Calendar sync is off.') . ' <a href="' . e(plugin_url('booking', 'admin/settings.php')) . '">' . __('booking_provider_gcal_off_link', 'Enable it in Booking settings') . '</a> ' . __('booking_provider_gcal_off_2', 'first, then come back here to connect') . ' ' . e($editing['name']) . '.'
                ); ?>
            <?php elseif ($gConnected): ?>
                <p class="text-sm" style="margin-bottom:12px;">
                    <span class="badge badge-active"><?= e(__('booking_provider_connected', 'Connected')) ?></span>
                    <?php if (!empty($editing['google_connected_email'])): ?>
                        <span class="text-muted"> <?= e(__('booking_provider_connected_as', 'as')) ?> <?= e($editing['google_connected_email']) ?></span>
                    <?php endif; ?>
                </p>
                <p class="text-sm text-muted" style="margin-bottom:12px;">
                    <?= e(__('booking_provider_gcal_sync_desc', 'New and rescheduled bookings push to this calendar automatically, and events created or moved directly in Google flow back into Kohevo.')) ?>
                    <?php if (!empty($editing['google_synced_at'])): ?><?= e(__('booking_provider_last_synced', 'Last synced')) ?> <?= e(I18n::localDate('M j, Y H:i', strtotime($editing['google_synced_at']))) ?>.<?php endif; ?>
                </p>
                <form method="post" action="<?= e(plugin_url('booking', 'admin/gcal-disconnect.php')) ?>" onsubmit="return confirm('<?= e(__('booking_provider_confirm_disconnect_gcal', 'Disconnect Google Calendar? Existing synced events on Google will not be deleted.')) ?>')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="provider_id" value="<?= (int)$editing['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(__('booking_provider_disconnect', 'Disconnect')) ?></button>
                </form>
            <?php else: ?>
                <?php booking_edit_card_note(__('booking_provider_connect_prefix', 'Connect') . ' ' . e($editing['name']) . __('booking_provider_connect_suffix', "'s Google Calendar so their bookings sync automatically and Kohevo respects time they've blocked off on their own calendar.")); ?>
                <a href="<?= e(plugin_url('booking', 'admin/gcal-connect.php')) ?>?provider_id=<?= (int)$editing['id'] ?>" class="btn btn-sm btn-primary"><?= e(__('booking_provider_connect_gcal', 'Connect Google Calendar')) ?></a>
            <?php endif; ?>
        <?php booking_edit_card_close(); ?>
    <?php endif; ?>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_providers', 'Providers')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_provider_new', 'New provider')) ?></a>
    </div>

    <?php if (!$providers): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_provider_none_yet', 'No providers yet')) ?></div></div></div>
    <?php else: ?>
        <div class="data-list" data-single-open>
        <?php foreach ($providers as $p):
            $svcCount = (int) Database::value(
                "SELECT COUNT(*) FROM booking_provider_services WHERE provider_id = ?", [$p['id']]);
            $hrCount = (int) Database::value(
                "SELECT COUNT(*) FROM booking_provider_hours WHERE provider_id = ?", [$p['id']]);

            $detail = [
                __('email', 'Email')    => $p['email'] ?: '—',
                __('booking_provider_timezone', 'Timezone') => $p['timezone'],
                __('services', 'Services') => $svcCount,
                __('hours', 'Hours')    => $hrCount . ' ' . __('booking_provider_days_of_week_suffix', 'day(s) of the week'),
            ];
            if (!empty($p['bio'])) $detail[__('booking_provider_bio', 'Bio')] = ['label'=>__('booking_provider_bio', 'Bio'),'value'=>$p['bio'],'muted'=>true];

            $actions = '<a href="?edit=' . (int)$p['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_provider_confirm_delete', 'Delete this provider?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete">'
                     . '<input type="hidden" name="id" value="' . (int)$p['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';

            slate_data_row([
                'avatar'       => mb_substr($p['name'], 0, 2),
                'avatar_color' => $p['is_active'] ? 'accent' : 'muted',
                'title'        => $p['name'],
                'meta'         => $p['email'] ?: $p['timezone'],
                'badge'        => $p['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive'],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
