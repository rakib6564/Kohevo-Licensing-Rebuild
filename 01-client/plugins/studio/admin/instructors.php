<?php
/**
 * Studio — admin Instructors.
 *
 * Instructors are core contacts tagged role=instructor. Profile (bio, phone, and
 * an optional Booking-provider link for room scheduling) is JSON on the role row.
 * List (tabs + search + bulk) with a create/edit form on ?new / ?edit=contactId.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

use Slate\Services\Identity\ContactRepository;
use Slate\Tenancy\TenantContext;

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');
if (class_exists('Media')) { Media::enqueuePicker(); }

$pageTitle  = __('studio_instructors', 'Studio · Instructors');
$currentNav = 'studio_instructors';

$selfUrl = plugin_url('studio', 'admin/instructors.php');
$isNew   = !empty($_GET['new']);
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? StudioAPI::getInstructorDetail($editId) : null;
if ($editId > 0 && $editing === null) { http_response_code(404); }
$isForm  = $isNew || $editing !== null;

$flash = null;

$profileFromPost = static function (): array {
    return [
        'phone'               => trim((string) ($_POST['phone'] ?? '')),
        'bio'                 => trim((string) ($_POST['bio'] ?? '')),
        'image'               => trim((string) ($_POST['image'] ?? '')),
        'booking_provider_id' => (int) ($_POST['booking_provider_id'] ?? 0),
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $do     = (string) ($_POST['_do'] ?? '');
        try {
            if ($action === 'create_instructor') {
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') { throw new \InvalidArgumentException(__('studio_need_name', 'Name is required.')); }
                $contacts = new ContactRepository(new TenantContext());
                $email = trim((string) ($_POST['email'] ?? ''));
                $draft = ['display_name' => $name];
                if ($email !== '') { $draft['email'] = $email; }
                $cid = $contacts->resolveOrCreate($draft)->id;
                StudioAPI::saveInstructorProfile($cid, $profileFromPost());
                studio_set_flash('success', sprintf(__('studio_instructor_created', 'Instructor “%s” added.'), $name));
                header('Location: ' . $selfUrl); exit;

            } elseif ($action === 'update_instructor') {
                $cid  = (int) ($_POST['id'] ?? 0);
                $name = trim((string) ($_POST['name'] ?? ''));
                $contacts = new ContactRepository(new TenantContext());
                if ($name !== '') { $contacts->update($cid, ['display_name' => $name]); }
                $email = trim((string) ($_POST['email'] ?? ''));
                if ($email !== '') { $contacts->update($cid, ['primary_email' => $email]); }
                StudioAPI::saveInstructorProfile($cid, $profileFromPost());
                studio_set_flash('success', __('studio_instructor_updated', 'Instructor updated.'));
                header('Location: ' . $selfUrl . '?edit=' . $cid); exit;

            } elseif (str_starts_with($do, 'delete_')) {
                StudioAPI::removeInstructor((int) substr($do, 7));
                studio_set_flash('success', __('studio_instructor_removed', 'Instructor removed.'));
                header('Location: ' . $selfUrl); exit;

            } elseif ($do === 'bulk_delete') {
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $n = 0;
                foreach ($ids as $id) { if ($id > 0 && StudioAPI::removeInstructor($id)) { $n++; } }
                studio_set_flash('success', sprintf(__('studio_bulk_done', '%d instructor(s) removed.'), $n));
                header('Location: ' . $selfUrl); exit;
            }
        } catch (\Throwable $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $isForm = true;
            if (($_POST['_action'] ?? '') === 'update_instructor') {
                $editing = StudioAPI::getInstructorDetail((int) ($_POST['id'] ?? 0)) ?? $editing;
            } else {
                $isNew = true;
            }
        }
    }
}

$flash       = $flash ?? studio_take_flash();
$instructors = StudioAPI::getInstructorsDetailed();
$providers   = StudioAPI::getBookingProviderOptions();
$providerName = [];
foreach ($providers as $p) { $providerName[(int) $p['id']] = (string) $p['name']; }

$ed = $editing ?? [];
$pr = $ed['profile'] ?? [];
$pv = static fn (string $k) => e((string) ($pr[$k] ?? ''));

require SLATE_ROOT . '/admin/partials/header.php';

// Emit the Studio kit CSS for every branch of the page. It used to ride along
// with studio_ui_list_start(), so an ?edit= form — which never renders a list —
// got none of it: unstyled chips, an unsized image preview. Idempotent.
studio_ui_css();
?>

<?php slate_breadcrumbs(array_merge([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_instructors', 'Instructors'), 'href' => $isForm ? $selfUrl : null],
], $isForm ? [['label' => $editing ? __('studio_edit_instructor', 'Edit instructor') : __('studio_new_instructor_t', 'New instructor')]] : [])); ?>

<div class="page-header">
    <div>
        <h1><?= $isForm ? ($editing ? __('studio_edit_instructor', 'Edit instructor') : __('studio_new_instructor_t', 'New instructor')) : __('studio_instructors', 'Instructors') ?></h1>
        <p class="page-header-sub"><?= __('studio_instructors_sub', 'Teaching staff and their optional booking link.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($isForm): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_instructors', 'All instructors') ?></a>
        <?php else: ?>
            <a href="<?= e($selfUrl) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_instructor_btn', 'New instructor') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($isForm): ?>
<div class="card">
    <div class="card-header"><h2><?= $editing ? __('studio_edit_instructor', 'Edit instructor') : __('studio_new_instructor_t', 'New instructor') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $editing ? 'update_instructor' : 'create_instructor' ?>">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>"><?php endif; ?>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="name"><?= __('studio_instructor_name', 'Instructor name') ?></label>
                <input type="text" id="name" name="name" required maxlength="190" value="<?= e((string) ($ed['display_name'] ?? '')) ?>" placeholder="e.g. Ryann Marshall">
            </div>
            <div class="field">
                <label class="field-label" for="email"><?= __('studio_instructor_email', 'Email') ?></label>
                <input type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($ed['primary_email'] ?? '')) ?>">
            </div>
        </div>

        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="phone"><?= __('studio_phone', 'Phone') ?></label>
                <input type="text" id="phone" name="phone" maxlength="40" value="<?= $pv('phone') ?>">
            </div>
            <?php if ($providers): ?>
            <div class="field">
                <label class="field-label" for="booking_provider_id"><?= __('studio_booking_provider', 'Booking provider (for scheduling)') ?></label>
                <select id="booking_provider_id" name="booking_provider_id">
                    <option value="0"><?= __('studio_no_provider', '— Not linked —') ?></option>
                    <?php $curProv = (int) ($pr['booking_provider_id'] ?? 0);
                    foreach ($providers as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $curProv === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field-hint"><?= __('studio_provider_hint', 'Link to a Booking provider so room scheduling can avoid clashes.') ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="field">
            <label class="field-label" for="bio"><?= __('studio_bio', 'Bio') ?></label>
            <textarea id="bio" name="bio" rows="3"><?= $pv('bio') ?></textarea>
        </div>

        <?php studio_image_field('image', (string) ($pr['image'] ?? ''), __('studio_photo', 'Photo'), __('studio_photo_hint', 'Optional — falls back to their Gravatar or initials.')); ?>

        <?php if ($editing && !empty($ed['classes'])): ?>
        <div class="field">
            <label class="field-label"><?= __('studio_classes_taught', 'Classes taught') ?></label>
            <div>
                <?php foreach ($ed['classes'] as $cl): ?>
                    <span class="studio-chip"><?= e($cl['name']) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <button type="submit" class="btn btn-primary"><?= $editing ? __('studio_save_instructor', 'Save changes') : __('studio_create_instructor', 'Add instructor') ?></button>
    </form>
</div>

<?php else: ?>

<?php
$linkedCount = 0;
foreach ($instructors as $i) {
    $m = $i['profile_meta'] ? (json_decode((string) $i['profile_meta'], true) ?: []) : [];
    if (!empty($m['booking_provider_id'])) { $linkedCount++; }
}

studio_ui_list_start([
    ['value' => 'all',     'label' => __('all', 'All'),                    'count' => count($instructors)],
    ['value' => 'linked',  'label' => __('studio_linked', 'Linked'),       'count' => $linkedCount],
    ['value' => 'unlinked','label' => __('studio_unlinked', 'Unlinked'),   'count' => count($instructors) - $linkedCount],
], __('studio_search_instructors', 'Search instructors…'), [
    ['value' => 'delete', 'label' => __('studio_remove', 'Remove'), 'danger' => true,
     'confirm' => __('studio_confirm_bulk_rm_instr', 'Remove the selected instructors? (contacts + their classes are kept)')],
]);

foreach ($instructors as $i):
    $m       = $i['profile_meta'] ? (json_decode((string) $i['profile_meta'], true) ?: []) : [];
    $classes = (int) $i['class_count'];
    $provId  = (int) ($m['booking_provider_id'] ?? 0);
    $linked  = $provId > 0;
    $metaBits = array_filter([
        $i['primary_email'] ?: '',
        !empty($m['phone']) ? (string) $m['phone'] : '',
    ], fn ($x) => $x !== '');

    $actions = '<a href="' . e($selfUrl) . '?edit=' . (int) $i['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a>'
             . ' <button type="submit" name="_do" value="delete_' . (int) $i['id'] . '" class="btn btn-sm btn-danger" data-confirm="'
             . e(__('studio_confirm_rm_instr', 'Remove this instructor? (contact + classes are kept)')) . '">' . e(__('studio_remove', 'Remove')) . '</button>';

    $avaImg = (string) ($m['image'] ?? '') ?: studio_gravatar_url((string) ($i['primary_email'] ?? ''), 80);
    studio_ui_row([
        '_id'          => (int) $i['id'],
        'avatar_html'  => studio_avatar_media((string) $i['display_name'], $avaImg),
        'avatar_color' => $classes > 0 ? 'info' : 'muted',
        'title'        => $i['display_name'],
        'meta'         => implode(' · ', $metaBits),
        'value'        => $classes > 0 ? sprintf(__('studio_n_classes', '%d classes'), $classes) : '',
        'badge'        => $linked ? [__('studio_linked', 'Linked'), 'active'] : [__('studio_unlinked', 'Unlinked'), 'inactive'],
        'detail'       => [
            __('studio_instructor_email', 'Email')   => $i['primary_email'] ?: '—',
            __('studio_phone', 'Phone')              => (string) ($m['phone'] ?? '') ?: '—',
            __('studio_classes_taught', 'Classes')   => (string) $classes,
            __('studio_booking_provider', 'Provider')=> $linked ? ($providerName[$provId] ?? ('#' . $provId)) : __('studio_no_provider', '— Not linked —'),
            __('studio_bio', 'Bio')                  => (string) ($m['bio'] ?? '') ?: '—',
        ],
        'actions'      => $actions,
        '_filter'      => $linked ? 'linked' : 'unlinked',
        '_search'      => $i['display_name'] . ' ' . ($i['primary_email'] ?? ''),
    ]);
endforeach;

studio_ui_list_end(count($instructors), __('studio_no_instructors', 'No instructors yet'), [
    'icon'      => 'user',
    'sub'       => __('studio_no_instructors_sub', 'Add your teaching staff, then assign them to class series. Their name and photo show on the public schedule.'),
    'cta_href'  => $selfUrl . '?new=1',
    'cta_label' => __('studio_new_instructor_btn', 'New instructor'),
]);
?>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
