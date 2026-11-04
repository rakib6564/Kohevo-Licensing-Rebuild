<?php
/**
 * Studio — admin Families.
 *
 * Create a family (a paying parent + their children) and list existing families
 * with their students. Parent and students are core contacts: the parent is
 * resolved-or-created (matched by email when given, so re-using a parent never
 * spawns a duplicate), each student is created by name. StudioAPI::createFamily
 * tags the parent 'parent' and each child 'student'.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

Auth::require();
Auth::requirePerm('studio.manage_families');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_families', 'Studio · Families');
$currentNav = 'studio_families';

$selfUrl = plugin_url('studio', 'admin/families.php');
$isNew   = !empty($_GET['new']);
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? StudioAPI::getFamily($editId) : null;
if ($editId > 0 && $editing === null) { http_response_code(404); }
$isForm  = $isNew || $editing !== null;

$flash = null;

/** Split a textarea into trimmed, non-empty lines. */
$linesOf = static function (string $raw): array {
    return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn ($l) => $l !== ''));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $do     = (string) ($_POST['_do'] ?? '');
        try {
            if ($action === 'create_family' && $do === '') {
                $parentName  = trim((string) ($_POST['parent_name'] ?? ''));
                $parentEmail = trim((string) ($_POST['parent_email'] ?? ''));
                if ($parentName === '' && $parentEmail === '') {
                    throw new \InvalidArgumentException(__('studio_need_parent', 'Enter a parent name or email.'));
                }
                $contacts = new ContactRepository(new TenantContext());
                $draft = ['display_name' => $parentName !== '' ? $parentName : $parentEmail];
                if ($parentEmail !== '') { $draft['email'] = $parentEmail; }
                $parent = $contacts->resolveOrCreate($draft);
                $studentIds = [];
                foreach ($linesOf((string) ($_POST['students'] ?? '')) as $line) {
                    $studentIds[] = $contacts->create(['display_name' => $line])->id;
                }
                StudioAPI::createFamily($parent->id, $studentIds);
                studio_set_flash('success', sprintf(
                    __('studio_family_created', 'Family created for %s with %d student(s).'),
                    $parent->displayName ?: ('#' . $parent->id), count($studentIds)
                ));
                header('Location: ' . $selfUrl); exit;

            } elseif ($action === 'update_family' && $do === '') {
                $fid = (int) ($_POST['id'] ?? 0);
                $fam = StudioAPI::getFamily($fid);
                if ($fam === null) { throw new \RuntimeException(__('studio_family_missing', 'Family not found.')); }
                $contacts = new ContactRepository(new TenantContext());
                $pName = trim((string) ($_POST['parent_name'] ?? ''));
                $pEmail = trim((string) ($_POST['parent_email'] ?? ''));
                $patch = [];
                if ($pName !== '')  { $patch['display_name'] = $pName; }
                if ($pEmail !== '') { $patch['primary_email'] = $pEmail; }
                if ($patch !== []) { $contacts->update((int) $fam['primary_parent_id'], $patch); }
                foreach ($linesOf((string) ($_POST['students'] ?? '')) as $line) {
                    StudioAPI::addStudentToFamily($fid, $contacts->create(['display_name' => $line])->id);
                }
                studio_set_flash('success', __('studio_family_updated', 'Family updated.'));
                header('Location: ' . $selfUrl . '?edit=' . $fid); exit;

            } elseif (str_starts_with($do, 'rmkid_')) {
                $fid = (int) ($_POST['id'] ?? 0);
                StudioAPI::removeStudentFromFamily($fid, (int) substr($do, 6));
                studio_set_flash('success', __('studio_student_removed', 'Student removed from family.'));
                header('Location: ' . $selfUrl . '?edit=' . $fid); exit;

            } elseif (str_starts_with($do, 'delete_')) {
                StudioAPI::deleteFamily((int) substr($do, 7));
                studio_set_flash('success', __('studio_family_deleted', 'Family deleted.'));
                header('Location: ' . $selfUrl); exit;

            } elseif ($do === 'bulk_delete') {
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $n = 0;
                foreach ($ids as $id) { if ($id > 0 && StudioAPI::deleteFamily($id)) { $n++; } }
                studio_set_flash('success', sprintf(__('studio_bulk_done', '%d family(ies) deleted.'), $n));
                header('Location: ' . $selfUrl); exit;
            }
        } catch (\Throwable $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $isForm = true;
            if (($_POST['_action'] ?? '') === 'update_family') {
                $editing = StudioAPI::getFamily((int) ($_POST['id'] ?? 0)) ?? $editing;
            } else {
                $isNew = true;
            }
        }
    }
}

$flash    = $flash ?? studio_take_flash();
$families = StudioAPI::getFamiliesWithMembers();

// Prefill data for the edit view.
$editParent = null; $editStudents = [];
if ($editing !== null) {
    $editParent   = Database::row('SELECT id, display_name, primary_email FROM contacts WHERE id = ?', [(int) $editing['primary_parent_id']]);
    $editStudents = Database::rows(
        "SELECT c.id, c.display_name FROM studio_family_members m JOIN contacts c ON c.id = m.contact_id
          WHERE m.tenant_id = ? AND m.family_id = ? AND m.relation = 'child' ORDER BY c.display_name",
        [current_tenant_id(), (int) $editing['id']]
    );
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
    ['label' => __('studio_families', 'Families'), 'href' => $isForm ? $selfUrl : null],
], $isForm ? [['label' => $editing ? __('studio_edit_family', 'Edit family') : __('studio_new_family', 'New family')]] : [])); ?>

<div class="page-header">
    <div>
        <h1><?= $isForm ? ($editing ? __('studio_edit_family', 'Edit family') : __('studio_new_family', 'New family')) : __('studio_families', 'Families') ?></h1>
        <p class="page-header-sub"><?= __('studio_families_sub', 'Group a paying parent with their children.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($isForm): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_families', 'All families') ?></a>
        <?php else: ?>
            <a href="<?= e($selfUrl) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_family_btn', 'New family') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($isForm): ?>
<div class="card">
    <div class="card-header"><h2><?= $editing ? __('studio_edit_family', 'Edit family') : __('studio_new_family', 'New family') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $editing ? 'update_family' : 'create_family' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="parent_name"><?= __('studio_parent_name', 'Parent / guardian name') ?></label>
                <input type="text" id="parent_name" name="parent_name" maxlength="190" value="<?= e((string) ($editParent['display_name'] ?? '')) ?>" placeholder="e.g. Alex Morgan">
            </div>
            <div class="field">
                <label class="field-label" for="parent_email"><?= __('studio_parent_email', 'Parent email (optional)') ?></label>
                <input type="email" id="parent_email" name="parent_email" maxlength="190" value="<?= e((string) ($editParent['primary_email'] ?? '')) ?>" placeholder="alex@example.com">
                <div class="field-hint"><?= __('studio_parent_email_hint', 'Used to match an existing contact and avoid duplicates.') ?></div>
            </div>
        </div>

        <?php if ($editing && $editStudents): ?>
        <div class="field">
            <label class="field-label"><?= __('studio_current_students', 'Current students') ?></label>
            <div>
                <?php foreach ($editStudents as $st): ?>
                    <span class="studio-chip"><?= e($st['display_name'] ?: ('#' . $st['id'])) ?>
                        <button type="submit" name="_do" value="rmkid_<?= (int) $st['id'] ?>" class="studio-chip-x"
                                data-confirm="<?= e(__('studio_confirm_rmkid', 'Remove this student from the family?')) ?>"
                                title="<?= e(__('remove', 'Remove')) ?>">×</button>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="field">
            <label class="field-label" for="students"><?= $editing ? __('studio_add_students', 'Add students') : __('studio_students', 'Students') ?></label>
            <textarea id="students" name="students" rows="4" placeholder="One student per line&#10;Sam Morgan&#10;Riley Morgan"></textarea>
            <div class="field-hint"><?= __('studio_students_hint', 'One child per line. Each becomes a student contact in this family.') ?></div>
        </div>

        <button type="submit" class="btn btn-primary"><?= $editing ? __('studio_save_family', 'Save changes') : __('studio_create_family', 'Create family') ?></button>
    </form>
</div>

<?php else: ?>

<?php
$withKids = 0;
foreach ($families as $f) { if (!empty($f['students'])) { $withKids++; } }
$emptyFams = count($families) - $withKids;
?>

<?php
studio_ui_list_start([
    ['value' => 'all',          'label' => __('all', 'All'),                       'count' => count($families)],
    ['value' => 'withstudents', 'label' => __('studio_with_students', 'With students'), 'count' => $withKids],
    ['value' => 'empty',        'label' => __('studio_empty', 'Empty'),            'count' => $emptyFams],
], __('studio_search_families', 'Search parents or students…'), [
    ['value' => 'delete', 'label' => __('delete', 'Delete'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_del_family', 'Delete the selected families? (students/parents are kept)')],
]);

foreach ($families as $f):
    $parent   = $f['parent_name'] ?: ('#' . $f['primary_parent_id']);
    $students = $f['students'] ?? [];
    $n        = count($students);
    $names    = array_map(static fn ($st) => (string) ($st['display_name'] ?: ('#' . $st['id'])), $students);

    $chips = $n === 0
        ? '<span class="text-muted text-sm">' . e(__('studio_no_students', 'No students')) . '</span>'
        : implode('', array_map(static fn ($x) => '<span class="studio-chip">' . e($x) . '</span>', $names));

    $actions = '<a href="' . e($selfUrl) . '?edit=' . (int) $f['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a>'
             . ' <button type="submit" name="_do" value="delete_' . (int) $f['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
             . e(__('studio_confirm_del_family', 'Delete this family? (students/parents are kept)')) . '">' . e(__('delete', 'Delete')) . '</button>';

    studio_ui_row([
        '_id'          => (int) $f['id'],
        'avatar_html'  => studio_avatar_media($parent, studio_gravatar_url((string) ($f['parent_email'] ?? ''), 80)),
        'avatar_color' => $n > 0 ? 'info' : 'muted',
        'title'        => $parent,
        'meta'         => $n . ' ' . ($n === 1 ? __('studio_student_lc', 'student') : __('studio_students_lc', 'students')),
        'badge'        => $n > 0 ? [__('active', 'Active'), 'active'] : [__('studio_empty', 'Empty'), 'inactive'],
        'detail'       => [
            ['label' => __('studio_students', 'Students'), 'html' => $chips],
            ['label' => __('created', 'Created'), 'value' => substr((string) ($f['created_at'] ?? ''), 0, 10) ?: '—'],
        ],
        'actions'      => $actions,
        '_filter'      => $n > 0 ? 'withstudents' : 'empty',
        '_search'      => $parent . ' ' . implode(' ', $names),
    ]);
endforeach;

studio_ui_list_end(count($families), __('studio_no_families', 'No families yet'), [
    'icon'      => 'home',
    'sub'       => __('studio_no_families_sub', 'A family groups students under one billing contact — that is what makes sibling discounts work.'),
    'cta_href'  => $selfUrl . '?new=1',
    'cta_label' => __('studio_new_family_btn', 'New family'),
]);
?>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
