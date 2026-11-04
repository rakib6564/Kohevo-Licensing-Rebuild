<?php
/**
 * Studio — admin Classes.
 *
 * List (default) with tabs + search + bulk actions; a create/edit form on
 * ?new=1 / ?edit=ID. Tenant-scoped via StudioAPI; instructor is a core contact
 * (picked or created inline and tagged instructor).
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

use Slate\Module\Studio\Domain\DanceStyle;
use Slate\Module\Studio\Domain\ClassLevel;
use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');
if (class_exists('Media')) { Media::enqueuePicker(); }

$pageTitle  = __('studio_classes', 'Studio · Classes');
$currentNav = 'studio_classes';

$selfUrl = plugin_url('studio', 'admin/classes.php');
$isNew   = !empty($_GET['new']);
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? StudioAPI::getClassSeries($editId) : null;
if ($editId > 0 && $editing === null) { http_response_code(404); }
$isForm  = $isNew || $editing !== null;

$flash = null;

/** Resolve the instructor id from the posted form (new name wins, else picked). */
$resolveInstructor = static function (): int {
    $newName = trim((string) ($_POST['new_instructor'] ?? ''));
    if ($newName !== '') {
        $id = (new ContactRepository(new TenantContext()))->create(['display_name' => $newName])->id;
        StudioAPI::assignContactRole($id, 'instructor');
        return $id;
    }
    $id = (int) ($_POST['instructor_id'] ?? 0);
    if ($id <= 0) {
        throw new \InvalidArgumentException(__('studio_need_instructor', 'Pick an instructor or enter a new one.'));
    }
    return $id;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $do     = (string) ($_POST['_do'] ?? '');

        /**
         * Warn on a clash, don't block it. A studio sometimes double-books
         * deliberately — two small groups in one big room, or a teacher
         * covering an overlap — and refusing the save would mean the schedule
         * simply cannot be expressed. Saving and naming the clash keeps the
         * studio in control while making the mistake impossible to miss; the
         * Classes list also carries a standing banner for anything unresolved.
         */
        $conflictNote = static function (array $candidate): string {
            $hits = StudioAPI::scheduleConflictsFor($candidate);
            if (!$hits) { return ''; }
            $parts = [];
            foreach ($hits as $h) {
                $parts[] = ($h['kind'] === 'room'
                    ? __('studio_conflict_room', 'Room clash with')
                    : __('studio_conflict_instr', 'Instructor clash with'))
                    . ' ' . \Slate\Module\Studio\Domain\ScheduleConflict::describe($h);
            }
            return ' ' . implode('; ', $parts) . '.';
        };

        $roomFromPost = static fn (): ?int =>
            ($_POST['room_id'] ?? '') !== '' ? (int) $_POST['room_id'] : null;

        try {
            if ($action === 'create_series') {
                $warn = $conflictNote([
                    'id'            => 0,
                    'day_of_week'   => (int) ($_POST['day_of_week'] ?? 0),
                    'start_time'    => (string) ($_POST['start_time'] ?? ''),
                    'end_time'      => (string) ($_POST['end_time'] ?? ''),
                    'room_id'       => $roomFromPost(),
                    'instructor_id' => $resolveInstructor(),
                ]);
                $seriesId = StudioAPI::createClassSeries([
                    'room_id'       => $roomFromPost(),
                    'name'          => trim((string) ($_POST['name'] ?? '')),
                    'style'         => (string) ($_POST['style'] ?? ''),
                    'level'         => ($_POST['level'] ?? '') !== '' ? (string) $_POST['level'] : null,
                    'instructor_id' => $resolveInstructor(),
                    'capacity'      => (int) ($_POST['capacity'] ?? 20),
                    'day_of_week'   => (int) ($_POST['day_of_week'] ?? 0),
                    'start_time'    => (string) ($_POST['start_time'] ?? ''),
                    'end_time'      => (string) ($_POST['end_time'] ?? ''),
                    'session_start' => (string) ($_POST['session_start'] ?? ''),
                    'session_end'   => (string) ($_POST['session_end'] ?? ''),
                    'price_cents'   => (int) round(((float) ($_POST['price'] ?? 0)) * 100),
                    'currency'      => 'USD',
                    'image'         => trim((string) ($_POST['image'] ?? '')),
                    'description'   => trim((string) ($_POST['description'] ?? '')),
                    'location'      => trim((string) ($_POST['location'] ?? '')),
                    'virtual_url'   => trim((string) ($_POST['virtual_url'] ?? '')),
                ]);
                $made = StudioAPI::generateOccurrences($seriesId);
                studio_set_flash($warn === '' ? 'success' : 'warning', sprintf(
                    __('studio_series_created', 'Class “%s” created — %d weekly session(s) generated.'),
                    (string) ($_POST['name'] ?? ''), $made
                ) . $warn);
                header('Location: ' . $selfUrl); exit;

            } elseif ($action === 'update_series') {
                $id = (int) ($_POST['id'] ?? 0);
                $warn = $conflictNote([
                    'id'            => $id,
                    'day_of_week'   => (int) ($_POST['day_of_week'] ?? 0),
                    'start_time'    => (string) ($_POST['start_time'] ?? ''),
                    'end_time'      => (string) ($_POST['end_time'] ?? ''),
                    'room_id'       => $roomFromPost(),
                    'instructor_id' => $resolveInstructor(),
                ]);
                StudioAPI::updateClassSeries($id, [
                    'room_id'       => $roomFromPost(),
                    'name'          => trim((string) ($_POST['name'] ?? '')),
                    'style'         => (string) ($_POST['style'] ?? ''),
                    'level'         => ($_POST['level'] ?? '') !== '' ? (string) $_POST['level'] : null,
                    'instructor_id' => $resolveInstructor(),
                    'capacity'      => (int) ($_POST['capacity'] ?? 20),
                    'day_of_week'   => (int) ($_POST['day_of_week'] ?? 0),
                    'start_time'    => (string) ($_POST['start_time'] ?? ''),
                    'end_time'      => (string) ($_POST['end_time'] ?? ''),
                    'session_start' => (string) ($_POST['session_start'] ?? ''),
                    'session_end'   => (string) ($_POST['session_end'] ?? ''),
                    'price_cents'   => (int) round(((float) ($_POST['price'] ?? 0)) * 100),
                    'image'         => trim((string) ($_POST['image'] ?? '')),
                    'description'   => trim((string) ($_POST['description'] ?? '')),
                    'location'      => trim((string) ($_POST['location'] ?? '')),
                    'virtual_url'   => trim((string) ($_POST['virtual_url'] ?? '')),
                ]);
                StudioAPI::generateOccurrences($id); // add any newly-in-range sessions
                studio_set_flash($warn === '' ? 'success' : 'warning',
                    __('studio_series_updated', 'Class updated.') . $warn);
                header('Location: ' . $selfUrl); exit;

            } elseif (str_starts_with($do, 'dup_')) {
                // A second section of the same class — same time slot, same
                // teacher, new roster. Enrolments are never copied.
                $newId = StudioAPI::duplicateClass((int) substr($do, 4));
                studio_set_flash('success', __('studio_series_duplicated',
                    'Class duplicated. Adjust its day, time or teacher, then it is ready.'));
                header('Location: ' . $selfUrl . '?edit=' . $newId); exit;

            } elseif (str_starts_with($do, 'delete_')) {
                StudioAPI::deleteClassSeries((int) substr($do, 7));
                studio_set_flash('success', __('studio_series_deleted', 'Class deleted.'));
                header('Location: ' . $selfUrl); exit;

            } elseif (in_array($do, ['bulk_delete', 'bulk_activate', 'bulk_deactivate'], true)) {
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $n = 0;
                foreach ($ids as $id) {
                    if ($id <= 0) { continue; }
                    if ($do === 'bulk_delete')          { StudioAPI::deleteClassSeries($id); }
                    elseif ($do === 'bulk_activate')    { StudioAPI::setClassSeriesActive($id, true); }
                    elseif ($do === 'bulk_deactivate')  { StudioAPI::setClassSeriesActive($id, false); }
                    $n++;
                }
                studio_set_flash('success', sprintf(__('studio_bulk_done', '%d class(es) updated.'), $n));
                header('Location: ' . $selfUrl); exit;
            }
        } catch (\Throwable $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $isForm = true;
            if (($_POST['_action'] ?? '') === 'update_series') {
                $editing = StudioAPI::getClassSeries((int) ($_POST['id'] ?? 0)) ?? $editing;
            } else {
                $isNew = true;
            }
        }
    }
}

$flash = $flash ?? studio_take_flash();

$series      = StudioAPI::getClassSeriesWithStats();
$instructors = StudioAPI::getInstructors();
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** Pretty style label: enum label when known, else Title Case of the raw value. */
$styleLabel = static function (string $v): string {
    $case = DanceStyle::tryFrom($v);
    return $case ? $case->label() : ucwords(str_replace(['_', '-'], ' ', $v));
};

// Form value helper (edit prefill / defaults).
$ed = $editing ?? [];
$fv = static function (string $k, $default = '') use ($ed) { return e((string) ($ed[$k] ?? $default)); };
// Term dates from Settings prefill a NEW class; an existing one keeps its own.
$termDefaults = StudioAPI::termDefaults();
$priceDefault = $editing ? number_format(((int) $ed['price_cents']) / 100, 2) : '120.00';
$dowDefault   = $editing ? (int) $ed['day_of_week'] : 1;

require SLATE_ROOT . '/admin/partials/header.php';

// Emit the Studio kit CSS for every branch of the page. It used to ride along
// with studio_ui_list_start(), so an ?edit= form — which never renders a list —
// got none of it: unstyled chips, an unsized image preview. Idempotent.
studio_ui_css();
?>

<?php slate_breadcrumbs(array_merge([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_classes', 'Classes'), 'href' => $isForm ? $selfUrl : null],
], $isForm ? [['label' => $editing ? __('studio_edit_class', 'Edit class') : __('studio_new_class', 'New class')]] : [])); ?>

<div class="page-header">
    <div>
        <h1><?= $isForm ? ($editing ? __('studio_edit_class', 'Edit class') : __('studio_new_class', 'New class series')) : __('studio_classes', 'Classes') ?></h1>
        <p class="page-header-sub"><?= __('studio_classes_sub', 'Create weekly class series and generate their sessions.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($isForm): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_classes', 'All classes') ?></a>
        <?php else: ?>
            <a href="<?= e($selfUrl) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_class_btn', 'New class') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($isForm): ?>
<div class="card">
    <div class="card-header"><h2><?= $editing ? __('studio_edit_class', 'Edit class') : __('studio_new_class', 'New class series') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $editing ? 'update_series' : 'create_series' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>"><?php endif; ?>

        <div class="field">
            <label class="field-label" for="name"><?= __('studio_class_name', 'Class name') ?></label>
            <input type="text" id="name" name="name" required maxlength="128" value="<?= $fv('name') ?>" placeholder="Ballet I — Tuesdays">
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="style"><?= __('studio_style', 'Style') ?></label>
                <select id="style" name="style" required>
                    <?php $curStyle = (string) ($ed['style'] ?? '');
                    foreach (DanceStyle::cases() as $s): ?>
                        <option value="<?= e($s->value) ?>" <?= $curStyle === $s->value ? 'selected' : '' ?>><?= e($s->label()) ?></option>
                    <?php endforeach;
                    if ($curStyle !== '' && DanceStyle::tryFrom($curStyle) === null): ?>
                        <option value="<?= e($curStyle) ?>" selected><?= e(ucwords(str_replace(['_', '-'], ' ', $curStyle))) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="level"><?= __('studio_level', 'Level') ?></label>
                <select id="level" name="level">
                    <option value=""><?= __('studio_any_level', '— Any —') ?></option>
                    <?php $curLevel = (string) ($ed['level'] ?? '');
                    foreach (ClassLevel::cases() as $l): ?>
                        <option value="<?= e($l->value) ?>" <?= $curLevel === $l->value ? 'selected' : '' ?>><?= e($l->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="instructor_id"><?= __('studio_instructor', 'Instructor') ?></label>
                <select id="instructor_id" name="instructor_id">
                    <option value=""><?= __('studio_pick_instructor', '— Select existing —') ?></option>
                    <?php $curInstr = (int) ($ed['instructor_id'] ?? 0);
                    foreach ($instructors as $i): ?>
                        <option value="<?= (int) $i['id'] ?>" <?= $curInstr === (int) $i['id'] ? 'selected' : '' ?>><?= e($i['display_name'] ?: ('#' . $i['id'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint"><?= __('studio_instructor_hint', 'Or add a new instructor below.') ?></div>
            </div>
            <div class="field">
                <label class="field-label" for="new_instructor"><?= __('studio_new_instructor', 'New instructor name') ?></label>
                <input type="text" id="new_instructor" name="new_instructor" maxlength="190" placeholder="e.g. Jamie Lee">
            </div>
        </div>

        <?php
        // Rooms come from Booking (booking_resources) rather than a studio
        // table of its own, so a studio that already runs private lessons has
        // its rooms defined once. The whole block disappears when Booking is
        // inactive — an empty picker that cannot be filled is worse than none.
        $rooms   = StudioAPI::roomOptions();
        $curRoom = (int) ($ed['room_id'] ?? 0);
        if ($rooms):
        ?>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="room_id"><?= __('studio_room', 'Room') ?></label>
                <select id="room_id" name="room_id">
                    <option value=""><?= __('studio_room_none', '— Not assigned —') ?></option>
                    <?php foreach ($rooms as $r): ?>
                        <option value="<?= (int) $r['id'] ?>" <?= $curRoom === (int) $r['id'] ? 'selected' : '' ?>>
                            <?= e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint">
                    <?= __('studio_room_hint', 'Shared with Booking. A clash is flagged on save, not blocked.') ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="day_of_week"><?= __('studio_day', 'Day of week') ?></label>
                <select id="day_of_week" name="day_of_week" required>
                    <?php foreach ($days as $n => $label): ?>
                        <option value="<?= $n ?>" <?= $n === $dowDefault ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="capacity"><?= __('studio_capacity', 'Capacity') ?></label>
                <input type="number" id="capacity" name="capacity" min="1" max="500" value="<?= $fv('capacity', '20') ?>">
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="start_time"><?= __('studio_start_time', 'Start time') ?></label>
                <input type="time" id="start_time" name="start_time" required value="<?= e(substr((string) ($ed['start_time'] ?? '16:00'), 0, 5)) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="end_time"><?= __('studio_end_time', 'End time') ?></label>
                <input type="time" id="end_time" name="end_time" required value="<?= e(substr((string) ($ed['end_time'] ?? '17:00'), 0, 5)) ?>">
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="session_start"><?= __('studio_session_start', 'Term starts') ?></label>
                <input type="date" id="session_start" name="session_start" required value="<?= e(substr((string) ($ed['session_start'] ?? $termDefaults['start']), 0, 10)) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="session_end"><?= __('studio_session_end', 'Term ends') ?></label>
                <input type="date" id="session_end" name="session_end" required value="<?= e(substr((string) ($ed['session_end'] ?? $termDefaults['end']), 0, 10)) ?>">
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="price"><?= __('studio_price', 'Price (USD per term)') ?></label>
            <input type="number" id="price" name="price" min="0" step="0.01" value="<?= e($priceDefault) ?>">
        </div>

        <?php studio_image_field('image', $editing ? StudioAPI::classImage($ed) : '', __('studio_featured_image', 'Featured image'), __('studio_featured_hint', 'Shown on the public class catalog.')); ?>

        <div class="field">
            <label class="field-label" for="description"><?= __('studio_class_desc', 'Description') ?></label>
            <textarea id="description" name="description" rows="4"
                      placeholder="<?= e(__('studio_class_desc_ph', 'What the class covers, what to expect, what to bring.')) ?>"><?=
                e($editing ? StudioAPI::classMeta($ed, 'description') : '') ?></textarea>
            <div class="field-hint"><?= __('studio_class_desc_hint', 'Shown on the public class page and in the parent portal.') ?></div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="location"><?= __('studio_class_location', 'Location') ?></label>
                <input type="text" id="location" name="location" maxlength="190"
                       value="<?= e($editing ? StudioAPI::classMeta($ed, 'location') : '') ?>"
                       placeholder="<?= e(__('studio_class_location_ph', 'e.g. Main studio, 21 Jackson Ave')) ?>">
                <div class="field-hint">
                    <?= __('studio_class_location_hint', 'Where families actually go. The room above is for clash detection.') ?>
                </div>
            </div>
            <div class="field">
                <label class="field-label" for="virtual_url"><?= __('studio_class_virtual', 'Online class link') ?></label>
                <input type="url" id="virtual_url" name="virtual_url" maxlength="190"
                       value="<?= e($editing ? StudioAPI::classMeta($ed, 'virtual_url') : '') ?>"
                       placeholder="https://…">
                <div class="field-hint">
                    <?= __('studio_class_virtual_hint', 'Only ever shown to enrolled families — never on the public page.') ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary"><?= $editing ? __('studio_save_class', 'Save changes') : __('studio_create_class', 'Create class &amp; generate sessions') ?></button>
    </form>
</div>

<?php else: ?>

<?php
$activeCount = 0;
foreach ($series as $s) { if ((int) $s['is_active'] === 1) { $activeCount++; } }
$inactiveCount = count($series) - $activeCount;

// Standing clash banner. The save-time warning is a flash and is gone on the
// next page load, so an unresolved double-booking needs somewhere permanent to
// live — otherwise a clash created on Monday is invisible by Tuesday.
$clashes = StudioAPI::scheduleConflicts();
if ($clashes):
    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
?>
<div class="alert alert-warning" style="margin-bottom:16px">
    <strong><?= sprintf(
        __('studio_clashes_n', '%d scheduling clash(es)'), count($clashes)) ?></strong>
    <ul style="margin:8px 0 0;padding-left:18px">
        <?php foreach ($clashes as $c):
            $a = $c['a']; $b = $c['b'];
            $day = $dayNames[(int) ($a['day_of_week'] ?? 0)] ?? '';
        ?>
            <li style="margin:3px 0">
                <?= $c['kind'] === 'room'
                    ? e(__('studio_room', 'Room')) . ' ' . e(StudioAPI::roomName(isset($a['room_id']) ? (int) $a['room_id'] : null))
                    : e(__('studio_instructor', 'Instructor')) . ' ' . e((string) ($a['instructor_name'] ?? '')) ?>
                — <?= e((string) $a['name']) ?>
                <?= e(__('studio_and', 'and')) ?> <?= e((string) $b['name']) ?>
                (<?= e($day) ?>
                <?= e(substr((string) $a['start_time'], 0, 5)) ?>–<?= e(substr((string) $a['end_time'], 0, 5)) ?>
                / <?= e(substr((string) $b['start_time'], 0, 5)) ?>–<?= e(substr((string) $b['end_time'], 0, 5)) ?>)
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif;

studio_ui_list_start([
    ['value' => 'all',      'label' => __('all', 'All'),           'count' => count($series)],
    ['value' => 'active',   'label' => __('active', 'Active'),     'count' => $activeCount],
    ['value' => 'inactive', 'label' => __('inactive', 'Inactive'), 'count' => $inactiveCount],
], __('studio_search_classes', 'Search classes…'), [
    ['value' => 'activate',   'label' => __('studio_activate', 'Activate')],
    ['value' => 'deactivate', 'label' => __('studio_deactivate', 'Deactivate')],
    ['value' => 'delete',     'label' => __('delete', 'Delete'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_delete_class', 'Delete the selected classes and their sessions/enrollments?')],
]);

foreach ($series as $s):
    $dow    = (int) $s['day_of_week'];
    $lvl    = $s['level'] ? ' · ' . ucfirst((string) $s['level']) : '';
    $active = (int) $s['is_active'] === 1;
    $price  = '$' . number_format(((int) $s['price_cents']) / 100, 2);
    $sched  = ($days[$dow] ?? '?') . ' ' . substr((string) $s['start_time'], 0, 5) . '–' . substr((string) $s['end_time'], 0, 5);
    $instr  = $s['instructor_name'] ?: ('#' . $s['instructor_id']);
    $actions = '<a href="' . e($selfUrl) . '?edit=' . (int) $s['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a>'
             . ' <button type="submit" name="_do" value="dup_' . (int) $s['id'] . '" class="btn btn-sm" data-confirm="'
             . e(__('studio_confirm_dup_class', 'Duplicate this class? Times and dates are copied; enrolments are not.'))
             . '">' . e(__('studio_duplicate', 'Duplicate')) . '</button>'
             . ' <button type="submit" name="_do" value="delete_' . (int) $s['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
             . e(__('studio_confirm_delete_class', 'Delete this class and its sessions/enrollments?')) . '">' . e(__('delete', 'Delete')) . '</button>';
    studio_ui_row([
        '_id'          => (int) $s['id'],
        'avatar_html'  => studio_avatar_media((string) $s['name'], StudioAPI::classImage($s)),
        'avatar_color' => $active ? 'info' : 'muted',
        'title'        => $s['name'],
        'meta'         => $styleLabel((string) $s['style']) . $lvl . ' · ' . $instr,
        'value'        => $price,
        'badge'        => $active ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive'],
        'detail'       => [
            __('studio_instructor', 'Instructor') => $instr,
            __('studio_schedule', 'Schedule')     => $sched,
            __('studio_term', 'Term')             => substr((string) $s['session_start'], 0, 10) . ' → ' . substr((string) $s['session_end'], 0, 10),
            __('studio_enrolled', 'Enrolled')     => (int) $s['enrolled_count'] . ' / ' . (int) $s['capacity'],
            __('studio_sessions', 'Sessions')     => (string) (int) $s['occurrence_count'],
            __('studio_price', 'Price')           => $price,
        ],
        'actions'      => $actions,
        '_filter'      => $active ? 'active' : 'inactive',
        '_search'      => $s['name'] . ' ' . $s['style'] . ' ' . ($s['instructor_name'] ?? ''),
    ]);
endforeach;

studio_ui_list_end(count($series), __('studio_no_classes', 'No classes yet'), [
    'icon'      => 'calendar',
    'sub'       => __('studio_no_classes_sub', 'A class series is a weekly slot — style, level, day and time. Create one and the schedule, enrollment and attendance pages fill in from it.'),
    'cta_href'  => $selfUrl . '?new=1',
    'cta_label' => __('studio_new_class_btn', 'New class'),
]);
?>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
