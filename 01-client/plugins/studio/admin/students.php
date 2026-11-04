<?php
/**
 * Studio — admin Students.
 *
 * Roster of students (contacts with role=student) with a studio profile (DOB,
 * skill, medical/allergies, emergency contact) stored as JSON on the student's
 * role row. List (tabs + search + bulk) with a create/edit form on ?new / ?edit.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

use Slate\Module\Studio\Domain\ClassLevel;
use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

Auth::require();
Auth::requirePerm('studio.manage_families');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_students', 'Studio · Students');
$currentNav = 'studio_students';

$selfUrl = plugin_url('studio', 'admin/students.php');
$isNew   = !empty($_GET['new']);
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? StudioAPI::getStudentDetail($editId) : null;
if ($editId > 0 && $editing === null) { http_response_code(404); }
$isForm  = $isNew || $editing !== null;

$flash = null;

/** Collect the profile fields from POST. */
$profileFromPost = static function (): array {
    return [
        'dob'             => trim((string) ($_POST['dob'] ?? '')),
        'skill'           => trim((string) ($_POST['skill'] ?? '')),
        'medical'         => trim((string) ($_POST['medical'] ?? '')),
        'allergies'       => trim((string) ($_POST['allergies'] ?? '')),
        'emergency_name'  => trim((string) ($_POST['emergency_name'] ?? '')),
        'emergency_phone' => trim((string) ($_POST['emergency_phone'] ?? '')),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $do     = (string) ($_POST['_do'] ?? '');
        try {
            if ($action === 'create_student') {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') { throw new \InvalidArgumentException(__('studio_need_name', 'Name is required.')); }
                $contacts = new ContactRepository(new TenantContext());
                $email = trim((string) ($_POST['email'] ?? ''));
                $draft = ['display_name' => $name];
                if ($email !== '') { $draft['email'] = $email; }
                $cid = $contacts->create($draft)->id;
                StudioAPI::saveStudentProfile($cid, $profileFromPost());
                $famId = (int) ($_POST['family_id'] ?? 0);
                if ($famId > 0) { StudioAPI::addStudentToFamily($famId, $cid); }
                studio_set_flash('success', sprintf(__('studio_student_created', 'Student “%s” added.'), $name));
                header('Location: ' . $selfUrl); exit;

            } elseif ($action === 'update_student') {
                $cid  = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $contacts = new ContactRepository(new TenantContext());
                if ($name !== '') { $contacts->update($cid, ['display_name' => $name]); }
                $email = trim((string) ($_POST['email'] ?? ''));
                if ($email !== '') { $contacts->update($cid, ['primary_email' => $email]); }
                StudioAPI::saveStudentProfile($cid, $profileFromPost());
                // Family reassignment.
                $newFam = (int) ($_POST['family_id'] ?? 0);
                $curFam = (int) (Database::value('SELECT family_id FROM studio_family_members WHERE tenant_id = ? AND contact_id = ? LIMIT 1', [current_tenant_id(), $cid]) ?? 0);
                if ($newFam !== $curFam) {
                    if ($curFam > 0) { StudioAPI::removeStudentFromFamily($curFam, $cid); }
                    if ($newFam > 0) { StudioAPI::addStudentToFamily($newFam, $cid); }
                }
                studio_set_flash('success', __('studio_student_updated', 'Student updated.'));
                header('Location: ' . $selfUrl . '?edit=' . $cid); exit;

            } elseif (str_starts_with($do, 'delete_')) {
                StudioAPI::removeStudent((int) substr($do, 7));
                studio_set_flash('success', __('studio_student_removed_msg', 'Student removed from the studio.'));
                header('Location: ' . $selfUrl); exit;

            } elseif ($do === 'bulk_delete') {
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $n = 0;
                foreach ($ids as $id) { if ($id > 0 && StudioAPI::removeStudent($id)) { $n++; } }
                studio_set_flash('success', sprintf(__('studio_bulk_done', '%d student(s) removed.'), $n));
                header('Location: ' . $selfUrl); exit;
            }
        } catch (\Throwable $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $isForm = true;
            if (($_POST['_action'] ?? '') === 'update_student') {
                $editing = StudioAPI::getStudentDetail((int) ($_POST['id'] ?? 0)) ?? $editing;
            } else {
                $isNew = true;
            }
        }
    }
}

$flash    = $flash ?? studio_take_flash();
$students = StudioAPI::getStudents();
$families = StudioAPI::getFamilyOptions();

/** Age in years from a Y-m-d DOB, or '' */
$ageOf = static function (string $dob): string {
    if ($dob === '') { return ''; }
    try { return (string) (new \DateTimeImmutable($dob))->diff(new \DateTimeImmutable('today'))->y; }
    catch (\Throwable $e) { return ''; }
};

$ed  = $editing ?? [];
$pr  = $ed['profile'] ?? [];
$pv  = static fn (string $k) => e((string) ($pr[$k] ?? ''));

require SLATE_ROOT . '/admin/partials/header.php';

// Emit the Studio kit CSS for every branch of the page. It used to ride along
// with studio_ui_list_start(), so an ?edit= form — which never renders a list —
// got none of it: unstyled chips, an unsized image preview. Idempotent.
studio_ui_css();
?>

<?php slate_breadcrumbs(array_merge([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_students', 'Students'), 'href' => $isForm ? $selfUrl : null],
], $isForm ? [['label' => $editing ? __('studio_edit_student', 'Edit student') : __('studio_new_student', 'New student')]] : [])); ?>

<div class="page-header">
    <div>
        <h1><?= $isForm ? ($editing ? __('studio_edit_student', 'Edit student') : __('studio_new_student', 'New student')) : __('studio_students', 'Students') ?></h1>
        <p class="page-header-sub"><?= __('studio_students_page_sub', 'Student roster, profiles and family links.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($isForm): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_students', 'All students') ?></a>
        <?php else: ?>
            <a href="<?= e($selfUrl) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_student_btn', 'New student') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($isForm): ?>
<div class="card">
    <div class="card-header"><h2><?= $editing ? __('studio_edit_student', 'Edit student') : __('studio_new_student', 'New student') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $editing ? 'update_student' : 'create_student' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>"><?php endif; ?>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="name"><?= __('studio_student_name', 'Student name') ?></label>
                <input type="text" id="name" name="name" required maxlength="190" value="<?= e((string) ($ed['display_name'] ?? '')) ?>" placeholder="e.g. Sam Morgan">
            </div>
            <div class="field">
                <label class="field-label" for="email"><?= __('studio_student_email', 'Email (optional)') ?></label>
                <input type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($ed['primary_email'] ?? '')) ?>">
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="dob"><?= __('studio_dob', 'Date of birth') ?></label>
                <input type="date" id="dob" name="dob" value="<?= $pv('dob') ?>">
            </div>
            <div class="field">
                <label class="field-label" for="skill"><?= __('studio_skill', 'Skill level') ?></label>
                <select id="skill" name="skill">
                    <option value=""><?= __('studio_any_level', '— Any —') ?></option>
                    <?php $curSkill = (string) ($pr['skill'] ?? '');
                    foreach (ClassLevel::cases() as $l): ?>
                        <option value="<?= e($l->value) ?>" <?= $curSkill === $l->value ? 'selected' : '' ?>><?= e($l->label()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="family_id"><?= __('studio_family', 'Family') ?></label>
            <select id="family_id" name="family_id">
                <option value="0"><?= __('studio_no_family', '— None —') ?></option>
                <?php $curFam = (int) ($ed['family_id'] ?? 0);
                foreach ($families as $f): ?>
                    <option value="<?= (int) $f['id'] ?>" <?= $curFam === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="emergency_name"><?= __('studio_emergency_name', 'Emergency contact') ?></label>
                <input type="text" id="emergency_name" name="emergency_name" maxlength="190" value="<?= $pv('emergency_name') ?>">
            </div>
            <div class="field">
                <label class="field-label" for="emergency_phone"><?= __('studio_emergency_phone', 'Emergency phone') ?></label>
                <input type="text" id="emergency_phone" name="emergency_phone" maxlength="40" value="<?= $pv('emergency_phone') ?>">
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="allergies"><?= __('studio_allergies', 'Allergies') ?></label>
            <input type="text" id="allergies" name="allergies" maxlength="255" value="<?= $pv('allergies') ?>">
        </div>

        <div class="field">
            <label class="field-label" for="medical"><?= __('studio_medical', 'Medical notes') ?></label>
            <textarea id="medical" name="medical" rows="3"><?= $pv('medical') ?></textarea>
        </div>

        <?php if ($editing && !empty($ed['enrollments'])): ?>
        <div class="field">
            <label class="field-label"><?= __('studio_enrolled_classes', 'Enrolled classes') ?></label>
            <div>
                <?php foreach ($ed['enrollments'] as $en): ?>
                    <span class="studio-chip"><?= e($en['series_name']) ?> · <?= e($en['status']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?= $editing ? __('studio_save_student', 'Save changes') : __('studio_create_student', 'Add student') ?></button>
    </form>
</div>

<?php else: ?>

<?php
$enrolledCount = 0;
foreach ($students as $s) { if ((int) $s['enrolled_count'] > 0) { $enrolledCount++; } }
$unenrolled = count($students) - $enrolledCount;

studio_ui_list_start([
    ['value' => 'all',        'label' => __('all', 'All'),                    'count' => count($students)],
    ['value' => 'enrolled',   'label' => __('studio_enrolled', 'Enrolled'),   'count' => $enrolledCount],
    ['value' => 'unenrolled', 'label' => __('studio_unenrolled', 'Unenrolled'), 'count' => $unenrolled],
], __('studio_search_students', 'Search students…'), [
    ['value' => 'delete', 'label' => __('studio_remove', 'Remove'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_rm_student', 'Remove the selected students from the studio? (contacts are kept)')],
]);

foreach ($students as $s):
    $prof   = $s['profile_meta'] ? (json_decode((string) $s['profile_meta'], true) ?: []) : [];
    $age    = $ageOf((string) ($prof['dob'] ?? ''));
    $enr    = (int) $s['enrolled_count'];
    $parent = $s['parent_name'] ?: __('studio_no_family', '— None —');
    $metaBits = array_filter([
        $parent,
        $age !== '' ? sprintf(__('studio_age_yrs', 'age %s'), $age) : '',
        !empty($prof['skill']) ? ucfirst((string) $prof['skill']) : '',
    ], fn ($x) => $x !== '');

    $actions = '<a href="' . e($selfUrl) . '?edit=' . (int) $s['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a>'
             . ' <button type="submit" name="_do" value="delete_' . (int) $s['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
             . e(__('studio_confirm_rm_student', 'Remove this student from the studio? (contact is kept)')) . '">' . e(__('studio_remove', 'Remove')) . '</button>';

    studio_ui_row([
        '_id'          => (int) $s['id'],
        'avatar_html'  => studio_avatar_media((string) $s['display_name'], studio_gravatar_url((string) ($s['primary_email'] ?? ''), 80)),
        'avatar_color' => $enr > 0 ? 'info' : 'muted',
        'title'        => $s['display_name'],
        'meta'         => implode(' · ', $metaBits),
        'value'        => $enr > 0 ? sprintf(__('studio_n_classes', '%d classes'), $enr) : '',
        'badge'        => $enr > 0 ? [__('studio_enrolled', 'Enrolled'), 'active'] : [__('studio_unenrolled', 'Unenrolled'), 'inactive'],
        'detail'       => [
            __('studio_family', 'Family')            => $parent,
            __('studio_dob', 'Date of birth')        => (string) ($prof['dob'] ?? '') ?: '—',
            __('studio_skill', 'Skill level')        => !empty($prof['skill']) ? ucfirst((string) $prof['skill']) : '—',
            __('studio_allergies', 'Allergies')      => (string) ($prof['allergies'] ?? '') ?: '—',
            __('studio_emergency_name', 'Emergency') => trim(((string) ($prof['emergency_name'] ?? '')) . ' ' . ((string) ($prof['emergency_phone'] ?? ''))) ?: '—',
            __('studio_enrolled', 'Enrolled')        => (string) $enr,
        ],
        'actions'      => $actions,
        '_filter'      => $enr > 0 ? 'enrolled' : 'unenrolled',
        '_search'      => $s['display_name'] . ' ' . $parent,
    ]);
endforeach;

studio_ui_list_end(count($students), __('studio_no_students_yet', 'No students yet'), [
    'icon'      => 'user',
    'sub'       => __('studio_no_students_sub', 'Students are the dancers themselves. Add them here, or let parents add their own during self-serve registration.'),
    'cta_href'  => $selfUrl . '?new=1',
    'cta_label' => __('studio_new_student_btn', 'New student'),
]);
?>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
