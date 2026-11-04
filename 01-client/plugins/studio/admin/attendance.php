<?php
/**
 * Studio — admin Attendance.
 *
 * Three steps via query params: pick a class → pick a session (occurrence) →
 * mark the roster (present / late / absent / excused). Attendance upserts per
 * (occurrence, student) so re-marking updates in place.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_attendance', 'Studio · Attendance');
$currentNav = 'studio_attendance';
$selfUrl    = plugin_url('studio', 'admin/attendance.php');

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'mark_attendance') {
        $occId    = (int) ($_POST['occurrence_id'] ?? 0);
        $statuses = (array) ($_POST['status'] ?? []);
        if ($occId > 0) {
            StudioAPI::markAttendance($occId, $statuses, null);
            studio_set_flash('success', sprintf(__('studio_attendance_saved', 'Attendance saved for %d student(s).'), count($statuses)));
        }
        header('Location: ' . $selfUrl . '?occ=' . $occId); exit;
    } else {
        // Cancel / restore one lesson. Returns to the session list for the
        // same class rather than the occurrence, since the cancelled lesson
        // no longer has an attendance sheet worth opening.
        $do  = (string) ($_POST['_do'] ?? '');
        $sid = (int) ($_GET['series'] ?? $_POST['series_id'] ?? 0);

        if (str_starts_with($do, 'cancel_')) {
            $oid = (int) substr($do, 7);
            StudioAPI::cancelOccurrence($oid, (string) ($_POST['cancel_note'] ?? ''));
            studio_set_flash('success', __('studio_lesson_cancelled', 'Lesson cancelled.'));
            header('Location: ' . $selfUrl . ($sid > 0 ? '?series=' . $sid : '')); exit;
        }
        if (str_starts_with($do, 'restore_')) {
            StudioAPI::restoreOccurrence((int) substr($do, 8));
            studio_set_flash('success', __('studio_lesson_restored', 'Lesson put back on.'));
            header('Location: ' . $selfUrl . ($sid > 0 ? '?series=' . $sid : '')); exit;
        }
        if ($do === 'bulk_cancel' || $do === 'bulk_restore') {
            $n = 0;
            foreach ((array) ($_POST['ids'] ?? []) as $raw) {
                $oid = (int) $raw;
                if ($oid <= 0) { continue; }
                $n += $do === 'bulk_cancel'
                    ? (StudioAPI::cancelOccurrence($oid, (string) ($_POST['cancel_note'] ?? '')) ? 1 : 0)
                    : (StudioAPI::restoreOccurrence($oid) ? 1 : 0);
            }
            studio_set_flash('success', sprintf(__('studio_lessons_updated', '%d lesson(s) updated.'), $n));
            header('Location: ' . $selfUrl . ($sid > 0 ? '?series=' . $sid : '')); exit;
        }
    }
}

$flash = $flash ?? studio_take_flash();

$occId = (int) ($_GET['occ'] ?? 0);
$occ   = $occId > 0 ? StudioAPI::getOccurrence($occId) : null;
if ($occId > 0 && $occ === null) { http_response_code(404); }

$seriesId = $occ ? (int) $occ['series_id'] : (int) ($_GET['series'] ?? 0);
$series   = $seriesId > 0 ? StudioAPI::getClassSeries($seriesId) : null;

$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$statusLabels = [
    'present' => __('studio_present', 'Present'),
    'late'    => __('studio_late', 'Late'),
    'absent'  => __('studio_absent', 'Absent'),
    'excused' => __('studio_excused', 'Excused'),
];

require SLATE_ROOT . '/admin/partials/header.php';

// Emit the Studio kit CSS for every branch of the page. It used to ride along
// with studio_ui_list_start(), so an ?edit= form — which never renders a list —
// got none of it: unstyled chips, an unsized image preview. Idempotent.
studio_ui_css();

$crumbs = [
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_attendance', 'Attendance'), 'href' => ($series || $occ) ? $selfUrl : null],
];
if ($series) {
    $crumbs[] = ['label' => $series['name'], 'href' => $occ ? $selfUrl . '?series=' . $seriesId : null];
}
if ($occ) {
    $crumbs[] = ['label' => date('D, M j Y', strtotime((string) $occ['occurrence_date']))];
}
slate_breadcrumbs($crumbs);
?>

<div class="page-header">
    <div>
        <h1><?= __('studio_attendance', 'Attendance') ?></h1>
        <p class="page-header-sub"><?= __('studio_attendance_sub', 'Mark who showed up, session by session.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($occ): ?>
            <a href="<?= e($selfUrl) ?>?series=<?= $seriesId ?>" class="btn">← <?= __('studio_back_sessions', 'Sessions') ?></a>
        <?php elseif ($series): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_classes', 'All classes') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($occ && $series):
    // ── Step 3: mark the roster ──
    $roster = StudioAPI::getRoster($seriesId);
    $marked = StudioAPI::getAttendanceMap($occId);
    ?>
    <div class="card">
        <div class="card-header">
            <h2><?= e($series['name']) ?> · <?= e(date('D, M j Y', strtotime((string) $occ['occurrence_date']))) ?></h2>
        </div>
        <?php if (!$roster): ?>
            <div class="empty"><div class="empty-title"><?= __('studio_no_roster', 'No enrolled students to mark') ?></div></div>
        <?php else: ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="mark_attendance">
            <input type="hidden" name="occurrence_id" value="<?= $occId ?>">
            <div class="studio-roster">
                <?php foreach ($roster as $st):
                    $sid = (int) $st['id'];
                    $cur = $marked[$sid] ?? 'present';
                    $fid = 'att_' . $sid;
                ?>
                <div class="studio-roster-row">
                    <label class="studio-roster-name" for="<?= e($fid) ?>"><?= e($st['display_name'] ?: ('#' . $sid)) ?></label>
                    <select id="<?= e($fid) ?>" name="status[<?= $sid ?>]">
                        <?php foreach ($statusLabels as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $cur === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="studio-roster-foot">
                <button type="submit" class="btn btn-primary"><?= __('studio_save_attendance', 'Save attendance') ?></button>
            </div>
        </form>
        <?php endif; ?>
    </div>

<?php elseif ($series):
    // ── Step 2: pick a session ──
    $occs = StudioAPI::getSeriesOccurrencesWithStats($seriesId);
    ?>
    <h2 class="studio-section-heading"><?= e($series['name']) ?> · <?= __('studio_sessions', 'Sessions') ?></h2>
    <?php
    $today    = date('Y-m-d');
    $nUpcoming = 0; $nPast = 0; $nOff = 0;
    foreach ($occs as $o) {
        if ((string) $o['status'] === 'cancelled') { $nOff++; }
        elseif ((string) $o['occurrence_date'] < $today) { $nPast++; }
        else { $nUpcoming++; }
    }

    // Upcoming / past / cancelled, because a term's worth of sessions is a
    // long list and the only one anybody acts on is the next one.
    // Bulk actions are what makes this a <form> — studio_ui_list_start only
    // wraps one when they are passed, and without it the per-row Cancel
    // buttons would be inert. They also earn their place: a snow week means
    // cancelling every lesson on one date, not one at a time.
    studio_ui_list_start([
        ['value' => 'upcoming',  'label' => __('studio_upcoming', 'Upcoming'),   'count' => $nUpcoming],
        ['value' => 'past',      'label' => __('studio_past', 'Past'),           'count' => $nPast],
        ['value' => 'cancelled', 'label' => __('cancelled', 'Cancelled'),        'count' => $nOff],
        ['value' => 'all',       'label' => __('all', 'All'),                    'count' => count($occs)],
    ], __('studio_search_sessions', 'Search dates…'), [
        ['value' => 'cancel',  'label' => __('studio_cancel_lesson', 'Cancel'), 'danger' => true,
         'confirm' => __('studio_confirm_bulk_cancel', 'Cancel the selected lessons?')],
        ['value' => 'restore', 'label' => __('studio_restore_lesson', 'Put back on'),
         'confirm' => __('studio_confirm_bulk_restore', 'Put the selected lessons back on?')],
    ]);
    // Carries the class through the POST so the redirect lands back here.
    echo '<input type="hidden" name="series_id" value="' . (int) $seriesId . '">';

    foreach ($occs as $o):
        $date   = (string) $o['occurrence_date'];
        $marked = (int) $o['marked_count'];
        $isPast = $date < $today;
        $isOff  = (string) $o['status'] === 'cancelled';
        $note   = trim((string) ($o['note'] ?? ''));

        $badge = $isOff
            ? [__('cancelled', 'Cancelled'), 'muted']
            : ($marked > 0
                ? [sprintf(__('studio_present_n', '%d present'), (int) $o['present_count']), 'active']
                : ($isPast ? [__('studio_unmarked', 'Unmarked'), 'warning']
                           : [__('studio_upcoming', 'Upcoming'), 'inactive']));

        if ($isOff) {
            $actions = '<button type="submit" name="_do" value="restore_' . (int) $o['id'] . '" class="btn btn-sm">'
                     . e(__('studio_restore_lesson', 'Put back on')) . '</button>';
        } else {
            $actions = '<a href="' . e($selfUrl) . '?occ=' . (int) $o['id'] . '" class="btn btn-sm btn-primary">'
                     . e(__('studio_take_attendance', 'Take attendance')) . '</a> '
                     . '<button type="submit" name="_do" value="cancel_' . (int) $o['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
                     . e(__('studio_confirm_cancel_lesson', 'Cancel this lesson? Attendance already taken is kept.'))
                     . '">' . e(__('studio_cancel_lesson', 'Cancel')) . '</button>';
        }

        studio_ui_row([
            '_id'          => (int) $o['id'],
            'avatar_html'  => '<span>' . e(date('j', strtotime($date))) . '</span>',
            'avatar_color' => $isOff ? 'muted' : null,
            'title'        => date('D, M j Y', strtotime($date)),
            'meta'         => $isOff && $note !== ''
                              ? $note
                              : substr((string) $o['start_time'], 0, 5) . '–' . substr((string) $o['end_time'], 0, 5),
            'badge'        => $badge,
            'value'        => (!$isOff && $marked > 0) ? sprintf(__('studio_n_marked', '%d marked'), $marked) : '',
            'actions'      => $actions,
            '_filter'      => $isOff ? 'cancelled' : ($isPast ? 'past' : 'upcoming'),
            '_search'      => $date . ' ' . $note,
        ]);
    endforeach;
    studio_ui_list_end(count($occs), __('studio_no_sessions', 'No sessions generated for this class'), [
        'icon' => 'calendar',
        'sub'  => __('studio_no_sessions_sub', 'Sessions come from the class series term dates. Check the start and end dates on the class.'),
    ]);
    ?>

<?php else:
    // ── Step 1: pick a class ──
    $classes = StudioAPI::getActiveClassSeries();
    ?>
    <h2 class="studio-section-heading"><?= __('studio_pick_class_att', 'Pick a class') ?></h2>
    <?php
    studio_ui_list_start([], __('studio_search_classes', 'Search classes…'));
    foreach ($classes as $s):
        $dow = (int) $s['day_of_week'];
        studio_ui_row([
            'avatar'  => $s['name'],
            'avatar_color' => 'info',
            'title'   => $s['name'],
            'meta'    => ($days[$dow] ?? '?') . ' ' . substr((string) $s['start_time'], 0, 5) . '–' . substr((string) $s['end_time'], 0, 5),
            'actions' => '<a href="' . e($selfUrl) . '?series=' . (int) $s['id'] . '" class="btn btn-sm btn-primary">' . e(__('studio_view_sessions', 'View sessions')) . '</a>',
            '_search' => $s['name'],
        ]);
    endforeach;
    studio_ui_list_end(count($classes), __('studio_no_classes', 'No classes yet'), [
        'icon'      => 'calendar',
        'sub'       => __('studio_no_classes_att_sub', 'Attendance is taken per class session — create an active class series first.'),
        'cta_href'  => plugin_url('studio', 'admin/classes.php') . '?new=1',
        'cta_label' => __('studio_new_class_btn', 'New class'),
    ]);
    ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
