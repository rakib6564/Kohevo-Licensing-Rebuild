<?php
/**
 * Studio — admin Enrollments.
 *
 * Enroll a family's student into a class (auto-waitlisting when the class is at
 * capacity) and see the tuition that applies — the multi-class + sibling discount
 * curve from TuitionCalculator, computed live per enrollment. Existing
 * enrollments are listed with their status and tuition, and can be dropped.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_enrollments', 'Studio · Enrollments');
$currentNav = 'studio_enrollments';
$isNew      = !empty($_GET['new']);

/** '$12.34' from integer minor units. */
$money = static fn (int $minor): string => '$' . number_format($minor / 100, 2);

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'enroll') {
        try {
            $studentId = (int) ($_POST['student_id'] ?? 0);
            $seriesId  = (int) ($_POST['series_id'] ?? 0);
            if ($studentId <= 0 || $seriesId <= 0) {
                throw new \InvalidArgumentException(__('studio_need_student_class', 'Pick both a student and a class.'));
            }

            $res = StudioAPI::enrollStudent($studentId, $seriesId);
            if (empty($res['ok'])) {
                throw new \RuntimeException($res['error'] ?? __('studio_enroll_failed', 'Enrollment failed.'));
            }

            $studentName = (string) Database::value('SELECT display_name FROM contacts WHERE id = ?', [$studentId]);
            $className   = (string) Database::value('SELECT name FROM studio_class_series WHERE id = ?', [$seriesId]);
            $tuition     = StudioAPI::calculateTuition($studentId, $seriesId);

            $statusLabel = $res['status'] === 'waitlist'
                ? __('studio_waitlisted', 'waitlisted (class full)')
                : __('studio_active', 'active');
            $already = !empty($res['existing']) ? ' ' . __('studio_already', '(already enrolled)') : '';

            studio_set_flash('success', sprintf(
                __('studio_enrolled_msg', '%s enrolled in “%s” — %s · tuition %s%s'),
                $studentName ?: ('#' . $studentId),
                $className ?: ('#' . $seriesId),
                $statusLabel,
                $money($tuition->minor),
                $already
            ));
            header('Location: ' . plugin_url('studio', 'admin/enrollments.php'));
            exit;
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
            $isNew = true;
        }
    } else {
        $do  = (string) ($_POST['_do'] ?? '');
        $url = plugin_url('studio', 'admin/enrollments.php');
        if (str_starts_with($do, 'drop_')) {
            StudioAPI::dropEnrollment((int) substr($do, 5), 'admin');
            studio_set_flash('success', __('studio_enroll_dropped', 'Enrollment dropped.'));
            header('Location: ' . $url); exit;
        } elseif (str_starts_with($do, 'delete_')) {
            StudioAPI::deleteEnrollment((int) substr($do, 7));
            studio_set_flash('success', __('studio_enroll_removed', 'Enrollment removed.'));
            header('Location: ' . $url); exit;
        } elseif (str_starts_with($do, 'paid_')) {
            $eid = (int) substr($do, 5);
            $row = Database::row('SELECT student_id, series_id FROM studio_enrollments WHERE tenant_id = ? AND id = ?', [current_tenant_id(), $eid]);
            if ($row) {
                $amt = StudioAPI::calculateTuition((int) $row['student_id'], (int) $row['series_id'])->minor;
                StudioAPI::markEnrollmentPaidOffline($eid, $amt);
            }
            studio_set_flash('success', __('studio_marked_paid', 'Marked as paid (offline).'));
            header('Location: ' . $url); exit;
        } elseif ($do === 'bulk_drop' || $do === 'bulk_delete') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            $n = 0;
            foreach ($ids as $id) {
                if ($id <= 0) { continue; }
                $do === 'bulk_delete' ? StudioAPI::deleteEnrollment($id) : StudioAPI::dropEnrollment($id, 'admin');
                $n++;
            }
            studio_set_flash('success', sprintf(__('studio_bulk_done', '%d enrollment(s) updated.'), $n));
            header('Location: ' . $url); exit;
        }
    }
}

$flash       = $flash ?? studio_take_flash();
$families    = StudioAPI::getFamiliesWithMembers();
$series      = array_filter(StudioAPI::getClassSeriesWithStats(), fn ($s) => (int) $s['is_active'] === 1);
$enrollments = StudioAPI::getEnrollments();

// Live tuition per non-dropped enrollment.
$tuitionFor = [];
foreach ($enrollments as $e) {
    if ($e['status'] === 'dropped') { continue; }
    try { $tuitionFor[(int) $e['id']] = StudioAPI::calculateTuition((int) $e['student_id'], (int) $e['series_id'])->minor; }
    catch (\Throwable $ex) { $tuitionFor[(int) $e['id']] = null; }
}

require SLATE_ROOT . '/admin/partials/header.php';

// Emit the Studio kit CSS for every branch of the page. It used to ride along
// with studio_ui_list_start(), so an ?edit= form — which never renders a list —
// got none of it: unstyled chips, an unsized image preview. Idempotent.
studio_ui_css();
?>

<?php slate_breadcrumbs(array_merge([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_enrollments', 'Enrollments'), 'href' => $isNew ? plugin_url('studio', 'admin/enrollments.php') : null],
], $isNew ? [['label' => __('studio_new_enrollment', 'Enroll a student')]] : [])); ?>

<div class="page-header">
    <div>
        <h1><?= $isNew ? __('studio_new_enrollment', 'Enroll a student') : __('studio_enrollments', 'Enrollments') ?></h1>
        <p class="page-header-sub"><?= __('studio_enrollments_sub', 'Enroll students into classes; tuition applies multi-class and sibling discounts.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($isNew): ?>
            <a href="<?= e(plugin_url('studio', 'admin/enrollments.php')) ?>" class="btn">← <?= __('studio_back_enrollments', 'All enrollments') ?></a>
        <?php else: ?>
            <a href="<?= e(plugin_url('studio', 'admin/enrollments.php')) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_enrollment_btn', 'Enroll student') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($isNew): ?>
<div class="card">
    <div class="card-header"><h2><?= __('studio_new_enrollment', 'Enroll a student') ?></h2></div>
    <?php if (!$families || !$series): ?>
        <div class="empty">
            <div class="empty-title"><?= __('studio_enroll_prereq', 'Add a class and a family first') ?></div>
            <p class="text-sm text-muted" style="margin-top:var(--space-2)">
                <a href="<?= e(plugin_url('studio', 'admin/classes.php')) ?>"><?= __('studio_classes', 'Classes') ?></a>
                ·
                <a href="<?= e(plugin_url('studio', 'admin/families.php')) ?>"><?= __('studio_families', 'Families') ?></a>
            </p>
        </div>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="enroll">
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="student_id"><?= __('studio_student', 'Student') ?></label>
                <select id="student_id" name="student_id" required>
                    <option value=""><?= __('studio_pick_student', '— Select a student —') ?></option>
                    <?php foreach ($families as $f):
                        if (empty($f['students'])) { continue; }
                        $group = $f['parent_name'] ?: (__('studio_family', 'Family') . ' #' . $f['id']);
                    ?>
                        <optgroup label="<?= e($group) ?>">
                            <?php foreach ($f['students'] as $st): ?>
                                <option value="<?= (int) $st['id'] ?>"><?= e($st['display_name'] ?: ('#' . $st['id'])) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="series_id"><?= __('studio_class', 'Class') ?></label>
                <select id="series_id" name="series_id" required>
                    <option value=""><?= __('studio_pick_class', '— Select a class —') ?></option>
                    <?php foreach ($series as $s):
                        $label = $s['name'] . ' — ' . $money((int) $s['price_cents'])
                               . ' (' . (int) $s['enrolled_count'] . '/' . (int) $s['capacity'] . ')';
                    ?>
                        <option value="<?= (int) $s['id'] ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint"><?= __('studio_capacity_hint', 'Full classes auto-waitlist.') ?></div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('studio_enroll', 'Enroll') ?></button>
    </form>
    <?php endif; ?>
</div>

<?php else: ?>

<?php
$cActive = $cWait = $cDropped = 0;
foreach ($enrollments as $en) {
    $st = $en['status'];
    if ($st === 'active' || $st === 'trial') { $cActive++; }
    elseif ($st === 'waitlist') { $cWait++; }
    elseif ($st === 'dropped') { $cDropped++; }
}

$badgeMod  = ['active' => 'active', 'trial' => 'active', 'waitlist' => 'warning', 'dropped' => 'inactive', 'inactive' => 'inactive'];
$avatarMod = ['active' => 'success', 'trial' => 'info', 'waitlist' => 'warning', 'dropped' => 'muted', 'inactive' => 'muted'];
?>

<?php
studio_ui_list_start([
    ['value' => 'all',      'label' => __('all', 'All'),               'count' => count($enrollments)],
    ['value' => 'active',   'label' => __('active', 'Active'),         'count' => $cActive],
    ['value' => 'waitlist', 'label' => __('studio_waitlist', 'Waitlist'), 'count' => $cWait],
    ['value' => 'dropped',  'label' => __('studio_dropped', 'Dropped'),   'count' => $cDropped],
], __('studio_search_enrollments', 'Search students or classes…'), [
    ['value' => 'drop',   'label' => __('studio_drop', 'Drop'),
     'confirm' => __('studio_confirm_bulk_drop', 'Drop the selected enrollments?')],
    ['value' => 'delete', 'label' => __('delete', 'Delete'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_del_enroll', 'Permanently remove the selected enrollments?')],
]);

foreach ($enrollments as $en):
    $status  = (string) $en['status'];
    $tuit    = $tuitionFor[(int) $en['id']] ?? null;
    $student = $en['student_name'] ?: ('#' . $en['student_id']);
    $pay     = StudioAPI::enrollmentPaidInfo($en['meta'] ?? null);
    $payable = in_array($status, ['active', 'trial'], true);
    $actions = '';
    if ($payable && !$pay['paid']) {
        $actions .= '<button type="submit" name="_do" value="paid_' . (int) $en['id'] . '" class="btn btn-sm btn-primary" data-confirm="'
                  . e(__('studio_confirm_mark_paid', 'Mark this enrollment as paid (offline)?')) . '">' . e(__('studio_mark_paid', 'Mark paid')) . '</button> ';
    }
    if ($status !== 'dropped') {
        $actions .= '<button type="submit" name="_do" value="drop_' . (int) $en['id'] . '" class="btn btn-sm" data-confirm="'
                  . e(__('studio_confirm_drop', 'Drop this enrollment?')) . '">' . e(__('studio_drop', 'Drop')) . '</button> ';
    }
    $actions .= '<button type="submit" name="_do" value="delete_' . (int) $en['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
              . e(__('studio_confirm_del_enroll', 'Permanently remove this enrollment?')) . '">' . e(__('delete', 'Delete')) . '</button>';

    $payLabel = $pay['paid']
        ? __('studio_paid', 'Paid') . ' · ' . $money((int) $pay['paid_cents'])
        : ($payable ? __('studio_unpaid', 'Unpaid') : '—');

    studio_ui_row([
        '_id'          => (int) $en['id'],
        'avatar'       => $student,
        'avatar_color' => $pay['paid'] ? 'success' : ($avatarMod[$status] ?? 'muted'),
        'title'        => $student,
        'meta'         => $en['series_name'],
        'value'        => $tuit === null ? null : $money((int) $tuit),
        'badge'        => $pay['paid'] ? [__('studio_paid', 'Paid'), 'active'] : [ucfirst($status), $badgeMod[$status] ?? 'accent'],
        'detail'       => [
            __('studio_class', 'Class')           => $en['series_name'],
            __('status', 'Status')                => ucfirst($status),
            __('studio_tuition', 'Tuition')       => $tuit === null ? '—' : $money((int) $tuit),
            __('studio_payment', 'Payment')       => $payLabel,
            __('studio_enrolled_on', 'Enrolled')  => substr((string) $en['enrolled_at'], 0, 10),
        ],
        'actions'      => $actions,
        '_filter'      => $status === 'trial' ? 'active' : $status,
        '_search'      => $student . ' ' . $en['series_name'],
    ]);
endforeach;

studio_ui_list_end(count($enrollments), __('studio_no_enrollments', 'No enrollments yet'), [
    'icon'      => 'check',
    'sub'       => __('studio_no_enrollments_sub', 'Enroll a student in a class series to start tracking tuition, discounts and attendance.'),
    'cta_href'  => plugin_url('studio', 'admin/enrollments.php') . '?new=1',
    'cta_label' => __('studio_new_enrollment_btn', 'Enroll student'),
]);
?>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
