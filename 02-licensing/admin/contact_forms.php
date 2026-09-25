<?php
/**
 * Slate — Contact Forms (basic builder).
 *
 * Routes:
 *   GET  /admin/contact_forms.php                  → list of forms
 *   GET  /admin/contact_forms.php?new              → new form
 *   GET  /admin/contact_forms.php?edit=N           → edit form
 *   GET  /admin/contact_forms.php?submissions=N    → submissions for form N
 *   POST (action=create|update|delete)             → mutation
 *
 * The builder ships in basic shape: a textarea where each line
 * defines a field as `type|name|label|required` (e.g.
 * `email|email|Your email|required`). Supported types:
 *   text, email, tel, url, textarea, select(|opt1,opt2,opt3)
 * We'll upgrade to drag-drop later. The data shape we store is
 * already flexible enough for that.
 *
 * The public render endpoint is at /api/contact/<slug>.php
 * (created in Session 3b alongside customer portal). For now,
 * forms can be created and managed; the public surface ships
 * next session.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
Auth::requirePerm('contact.view');

$canEdit    = Auth::can('contact.manage') || Auth::isSuperAdmin();
$pageTitle  = __('contact_forms', 'Contact Forms');
$currentNav = 'contact-forms';

$tenantId = current_tenant_id();
$flash    = null;
$editing  = null;
$mode     = 'list';
$viewingSubmissions = null;

// ─── POST ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission for this action.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $formId = (int)($_POST['form_id'] ?? 0);
        $title  = trim((string)($_POST['title'] ?? ''));
        $slug   = trim((string)($_POST['slug'] ?? ''));
        $slug   = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: '');
        $slug   = trim($slug, '-');
        $status = (string)($_POST['status'] ?? 'draft');
        $statusValid = in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
        $fieldsRaw = (string)($_POST['fields_raw'] ?? '');
        $notify    = trim((string)($_POST['notify_email'] ?? ''));
        $thankyou  = trim((string)($_POST['thankyou'] ?? ''));

        $fields = parseFieldDefinitions($fieldsRaw);
        $err = null;

        if ($action === 'create' || $action === 'update') {
            if ($title === '') $err = __('title_required', 'Title is required.');
            elseif ($slug === '' || !preg_match('/^[a-z][a-z0-9-]{1,62}$/', $slug))
                $err = __('slug_invalid_form', 'Slug must start with a letter and contain only lowercase letters, digits, and hyphens.');
            elseif ($notify !== '' && !filter_var($notify, FILTER_VALIDATE_EMAIL))
                $err = __('notify_email_invalid', 'Notification email is not a valid address.');
            elseif (empty($fields))
                $err = __('fields_required', 'Add at least one field. Format: type|name|label|required');
        }

        if ($action === 'create') {
            if (!$err && Database::row("SELECT id FROM contact_forms WHERE tenant_id = ? AND slug = ?",
                                       [$tenantId, $slug])) {
                $err = __('form_slug_in_use', 'A form with that slug already exists.');
            }
            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = ['id' => 0, 'title' => $title, 'slug' => $slug, 'status' => $statusValid,
                            'fields' => $fields, 'fields_raw' => $fieldsRaw,
                            'settings' => ['notify_email' => $notify, 'thankyou' => $thankyou]];
            } else {
                $newId = Database::insert('contact_forms', [
                    'tenant_id' => $tenantId,
                    'title'     => mb_substr($title, 0, 190),
                    'slug'      => $slug,
                    'fields'    => json_encode($fields, JSON_UNESCAPED_UNICODE),
                    'settings'  => json_encode(['notify_email' => $notify, 'thankyou' => $thankyou], JSON_UNESCAPED_UNICODE),
                    'status'    => $statusValid,
                ]);
                AuditLog::record('contact_form.created', "form#$newId", ['slug' => $slug]);
                $flash = ['type' => 'success', 'msg' => sprintf(__('form_created', 'Form "%s" created.'), $title)];
            }
        }

        elseif ($action === 'update') {
            $target = Database::row("SELECT * FROM contact_forms WHERE id = ? AND tenant_id = ?", [$formId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('form_not_found', 'Form not found.')];
            } elseif (!$err && Database::row(
                "SELECT id FROM contact_forms WHERE tenant_id = ? AND slug = ? AND id != ?",
                [$tenantId, $slug, $formId]
            )) {
                $err = __('form_slug_in_use', 'A form with that slug already exists.');
            }
            if ($err) {
                $flash = ['type' => 'error', 'msg' => $err];
                $mode = 'edit';
                $editing = array_merge($target, [
                    'title' => $title, 'slug' => $slug, 'status' => $statusValid,
                    'fields' => $fields, 'fields_raw' => $fieldsRaw,
                    'settings' => ['notify_email' => $notify, 'thankyou' => $thankyou],
                ]);
            } else {
                Database::update('contact_forms', [
                    'title'    => mb_substr($title, 0, 190),
                    'slug'     => $slug,
                    'fields'   => json_encode($fields, JSON_UNESCAPED_UNICODE),
                    'settings' => json_encode(['notify_email' => $notify, 'thankyou' => $thankyou], JSON_UNESCAPED_UNICODE),
                    'status'   => $statusValid,
                ], 'id = ? AND tenant_id = ?', [$formId, $tenantId]);
                AuditLog::record('contact_form.updated', "form#$formId");
                $flash = ['type' => 'success', 'msg' => __('form_updated', 'Form updated.')];
                $mode = 'edit';
                $editing = Database::row("SELECT * FROM contact_forms WHERE id = ?", [$formId]);
            }
        }

        elseif ($action === 'delete') {
            $target = Database::row("SELECT * FROM contact_forms WHERE id = ? AND tenant_id = ?", [$formId, $tenantId]);
            if (!$target) {
                $flash = ['type' => 'error', 'msg' => __('form_not_found', 'Form not found.')];
            } else {
                Database::delete('contact_forms', 'id = ? AND tenant_id = ?', [$formId, $tenantId]);
                AuditLog::record('contact_form.deleted', "form#$formId", ['slug' => $target['slug']]);
                $flash = ['type' => 'success', 'msg' => __('form_deleted', 'Form deleted.')];
            }
        }
    }
}

// ─── Mode selection (GET) ────────────────────────────────────
if ($mode === 'list') {
    if (isset($_GET['new']) && $canEdit) {
        $mode = 'edit';
        $editing = ['id' => 0, 'title' => '', 'slug' => '', 'status' => 'draft',
                    'fields' => [], 'fields_raw' => sampleFieldsRaw(),
                    'settings' => ['notify_email' => '', 'thankyou' => 'Thanks! We received your message.']];
    } elseif (isset($_GET['edit'])) {
        $id = (int)$_GET['edit'];
        $row = Database::row("SELECT * FROM contact_forms WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        if ($row) {
            $row['fields']     = json_decode($row['fields']   ?? '[]', true) ?: [];
            $row['settings']   = json_decode($row['settings'] ?? '{}', true) ?: ['notify_email' => '', 'thankyou' => ''];
            $row['fields_raw'] = serializeFieldsRaw($row['fields']);
            $editing = $row;
            $mode = 'edit';
        }
    } elseif (isset($_GET['submissions'])) {
        $id = (int)$_GET['submissions'];
        $form = Database::row("SELECT * FROM contact_forms WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        if ($form) {
            $form['fields'] = json_decode($form['fields'] ?? '[]', true) ?: [];
            $viewingSubmissions = $form;
            $mode = 'submissions';
        }
    }
}

// ─── Helpers ─────────────────────────────────────────────────
function sampleFieldsRaw(): string {
    return "text|name|Your name|required\nemail|email|Email|required\ntextarea|message|Message|required";
}

function parseFieldDefinitions(string $raw): array {
    $fields = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 3) continue;
        $type   = $parts[0];
        $name   = $parts[1];
        $label  = $parts[2];
        $req    = !empty($parts[3]) && strtolower($parts[3]) === 'required';
        $opts   = [];

        // Allow type=select(opt1,opt2)
        if (preg_match('/^select\((.+)\)$/i', $type, $m)) {
            $type = 'select';
            $opts = array_filter(array_map('trim', explode(',', $m[1])), fn($s) => $s !== '');
        }

        $allowed = ['text', 'email', 'tel', 'url', 'textarea', 'select'];
        if (!in_array($type, $allowed, true)) continue;
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/i', $name)) continue;
        if ($label === '') continue;

        $field = ['type' => $type, 'name' => $name, 'label' => $label, 'required' => $req];
        if ($type === 'select') $field['options'] = array_values($opts);
        $fields[] = $field;
    }
    return $fields;
}

function serializeFieldsRaw(array $fields): string {
    $lines = [];
    foreach ($fields as $f) {
        $type = $f['type'];
        if ($type === 'select' && !empty($f['options'])) {
            $type = 'select(' . implode(',', $f['options']) . ')';
        }
        $line = $type . '|' . ($f['name'] ?? '') . '|' . ($f['label'] ?? '');
        if (!empty($f['required'])) $line .= '|required';
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

require __DIR__ . '/partials/header.php';
?>

<?php
$crumbs = [
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('contact_forms', 'Contact Forms')],
];
if ($mode === 'edit') {
    $crumbs[1]['href'] = SLATE_URL . '/admin/contact_forms.php';
    $crumbs[] = ['label' => ((int)($editing['id'] ?? 0)) > 0 ? ($editing['title'] ?: __('edit', 'Edit')) : __('new_form', 'New form')];
} elseif ($mode === 'submissions') {
    $crumbs[1]['href'] = SLATE_URL . '/admin/contact_forms.php';
    $crumbs[] = ['label' => $viewingSubmissions['title'] . ' — ' . __('submissions', 'submissions')];
}
slate_breadcrumbs($crumbs);
?>

<?php
// DEPRECATED: this legacy core builder is superseded by the Forms plugin.
// The page stays functional so existing forms/submissions remain reachable,
// but the sidebar link is hidden and this banner steers admins to the plugin.
$formsActive = class_exists('PluginLoader') && PluginLoader::isActive('forms');
?>
<div class="alert alert-info" role="status">
    <strong>Legacy Contact Forms.</strong> This builder is deprecated in favour of the
    <?php if ($formsActive): ?>
        <a href="<?= e(SLATE_URL) ?>/admin/plugins.php">Forms plugin</a> (public pages,
        webhooks, e-signature, PDF, conditional logic). Your existing forms and submissions
        below are kept intact; new forms should be built in the Forms plugin.
    <?php else: ?>
        <strong>Forms</strong> plugin (activate it on
        <a href="<?= e(SLATE_URL) ?>/admin/plugins.php">Plugins</a>). Your existing forms and
        submissions below are kept intact.
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php /* ── EDIT ─────────────────────────────────────────── */ ?>
<?php if ($mode === 'edit'):
    $isNew = ((int)($editing['id'] ?? 0)) === 0;
    $settings = $editing['settings'] ?? ['notify_email' => '', 'thankyou' => ''];
?>
    <div class="page-header">
        <div>
            <h1><?= e($isNew ? __('new_form', 'New form') : ($editing['title'] ?: __('edit_form', 'Edit form'))) ?></h1>
            <p class="page-header-sub">
                <?= __('form_edit_sub', 'Define the fields and notification settings.') ?>
            </p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="<?= $isNew ? 'create' : 'update' ?>">
        <?php if (!$isNew): ?>
            <input type="hidden" name="form_id" value="<?= (int)$editing['id'] ?>">
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2><?= __('details', 'Details') ?></h2></div>

            <div class="field">
                <label class="field-label" for="title"><?= __('title', 'Title') ?> <span class="field-required">*</span></label>
                <input type="text" id="title" name="title" required maxlength="190"
                       value="<?= e($editing['title'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="slug"><?= __('slug', 'Slug') ?> <span class="field-required">*</span></label>
                    <input type="text" id="slug" name="slug" required maxlength="64"
                           value="<?= e($editing['slug'] ?? '') ?>" <?= $canEdit ? '' : 'disabled' ?>>
                    <div class="field-hint"><?= __('slug_hint', 'Lowercase. Used in the public URL.') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
                    <select id="status" name="status" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="draft"     <?= ($editing['status'] ?? '') === 'draft'     ? 'selected' : '' ?>><?= __('draft',     'Draft') ?></option>
                        <option value="published" <?= ($editing['status'] ?? '') === 'published' ? 'selected' : '' ?>><?= __('published', 'Published') ?></option>
                        <option value="archived"  <?= ($editing['status'] ?? '') === 'archived'  ? 'selected' : '' ?>><?= __('archived',  'Archived') ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= __('fields', 'Fields') ?></h2></div>
            <p class="text-muted text-sm mb-2">
                <?= __('fields_hint', 'One field per line. Format: type|name|label|required') ?>
            </p>
            <p class="text-muted text-sm mb-3">
                <?= __('fields_types', 'Types:') ?>
                <code>text</code>, <code>email</code>, <code>tel</code>, <code>url</code>,
                <code>textarea</code>, <code>select(a,b,c)</code>
            </p>
            <div class="field">
                <textarea name="fields_raw" rows="8"
                          style="font-family:var(--font-mono);font-size:13px"
                          <?= $canEdit ? '' : 'disabled' ?>><?= e($editing['fields_raw'] ?? sampleFieldsRaw()) ?></textarea>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= __('notifications', 'Notifications') ?></h2></div>
            <div class="field">
                <label class="field-label" for="notify_email"><?= __('notify_email', 'Email submissions to') ?></label>
                <input type="email" id="notify_email" name="notify_email" maxlength="190"
                       value="<?= e($settings['notify_email'] ?? '') ?>"
                       placeholder="<?= e(Database::setting('business_email') ?: 'you@example.com') ?>"
                       <?= $canEdit ? '' : 'disabled' ?>>
                <div class="field-hint"><?= __('notify_hint', 'Leave blank to skip email notifications. Submissions are still stored.') ?></div>
            </div>
            <div class="field">
                <label class="field-label" for="thankyou"><?= __('thankyou', 'Thank-you message') ?></label>
                <textarea id="thankyou" name="thankyou" rows="2" maxlength="500"
                          <?= $canEdit ? '' : 'disabled' ?>><?= e($settings['thankyou'] ?? '') ?></textarea>
                <div class="field-hint"><?= __('thankyou_hint', 'Shown after a successful submission.') ?></div>
            </div>
        </div>

        <?php if ($canEdit): ?>
            <div class="flex gap-2" style="flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">
                    <?= $isNew ? __('create_form', 'Create form') : __('save_changes', 'Save changes') ?>
                </button>
                <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php" class="btn"><?= __('cancel', 'Cancel') ?></a>
            </div>
        <?php endif; ?>
    </form>

    <?php if (!$isNew && $canEdit): ?>
        <div class="card mt-4">
            <div class="card-header"><h2 class="text-danger"><?= __('danger_zone', 'Danger zone') ?></h2></div>
            <p class="text-muted text-sm mb-3">
                <?= __('delete_form_warn', 'Deleting a form also deletes all its submissions. This cannot be undone.') ?>
            </p>
            <form method="post"
                  onsubmit="return confirm(<?= e(json_encode(
                      sprintf(__('confirm_delete_form', 'Delete form "%s" and all its submissions? This cannot be undone.'), $editing['title'])
                  )) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete">
                <input type="hidden" name="form_id" value="<?= (int)$editing['id'] ?>">
                <button type="submit" class="btn btn-danger"><?= __('delete_form', 'Delete form') ?></button>
            </form>
        </div>
    <?php endif; ?>

<?php /* ── SUBMISSIONS ──────────────────────────────────── */ ?>
<?php elseif ($mode === 'submissions'): ?>
    <div class="page-header">
        <div>
            <h1><?= e($viewingSubmissions['title']) ?> — <?= __('submissions', 'submissions') ?></h1>
            <p class="page-header-sub"><code><?= e($viewingSubmissions['slug']) ?></code></p>
        </div>
        <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php" class="btn btn-ghost">
            ← <?= __('back_to_list', 'Back to list') ?>
        </a>
    </div>

    <?php
    $subs = Database::rows(
        "SELECT * FROM contact_form_submissions WHERE form_id = ? AND tenant_id = ? ORDER BY created_at DESC LIMIT 200",
        [(int)$viewingSubmissions['id'], $tenantId]
    );
    ?>

    <?php if (empty($subs)): ?>
        <div class="card">
            <div class="empty">
                <div class="empty-title"><?= __('no_submissions', 'No submissions yet') ?></div>
                <p>
                    <?= __('no_submissions_intro', 'When someone fills out this form, their submission will appear here.') ?>
                </p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($subs as $s):
                $data = json_decode($s['data_json'] ?? '{}', true) ?: [];
                $first = '';
                foreach ($data as $v) { $first = (string)$v; if ($first !== '') break; }
                $detail = [];
                foreach ($data as $k => $v) {
                    $detail[$k] = is_scalar($v) ? (string)$v : json_encode($v);
                }
                $detail['IP']         = $s['ip'] ?: '—';
                $detail['User agent'] = $s['user_agent'] ?: '—';
                $detail['Received']   = $s['created_at'];

                slate_data_row([
                    'avatar'       => '#' . (int)$s['id'],
                    'avatar_color' => 'info',
                    'title'        => $first !== '' ? mb_substr($first, 0, 80) : __('submission', 'Submission'),
                    'meta'         => $s['created_at'],
                    'detail'       => $detail,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php /* ── LIST ─────────────────────────────────────────── */ ?>
<?php else:
    $forms = Database::rows(
        "SELECT cf.*,
            (SELECT COUNT(*) FROM contact_form_submissions s
              WHERE s.form_id = cf.id) AS submission_count
         FROM contact_forms cf
         WHERE cf.tenant_id = ?
         ORDER BY cf.created_at DESC",
        [$tenantId]
    );
?>

    <div class="page-header">
        <div>
            <h1><?= __('contact_forms', 'Contact Forms') ?></h1>
            <p class="page-header-sub">
                <?= __('forms_subtitle', 'Build forms to collect inquiries and submissions.') ?>
            </p>
        </div>
        <?php if ($canEdit): ?>
            <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php?new" class="btn btn-primary">
                + <?= __('new_form', 'New form') ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (empty($forms)): ?>
        <div class="card">
            <div class="empty">
                <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="5" width="18" height="14" rx="2"/>
                    <path d="M3 7l9 6 9-6"/>
                </svg>
                <div class="empty-title"><?= __('no_forms_yet', 'No forms yet') ?></div>
                <p><?= __('no_forms_intro', 'Create your first form to start collecting inquiries.') ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($forms as $f):
                $statusColor = match($f['status']) {
                    'published' => 'success',
                    'draft'     => 'warning',
                    'archived'  => 'muted',
                    default     => 'muted',
                };
                $initials = mb_strtoupper(mb_substr($f['title'] ?? '?', 0, 2));

                ob_start();
                ?>
                <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php?submissions=<?= (int)$f['id'] ?>"
                   class="btn btn-sm"><?= __('submissions', 'Submissions') ?>
                    (<?= (int)$f['submission_count'] ?>)</a>
                <a href="<?= e(SLATE_URL) ?>/admin/contact_forms.php?edit=<?= (int)$f['id'] ?>"
                   class="btn btn-sm btn-primary"><?= $canEdit ? __('edit', 'Edit') : __('view', 'View') ?></a>
                <?php
                $actions = ob_get_clean();

                slate_data_row([
                    'avatar'       => $initials,
                    'avatar_color' => $statusColor,
                    'title'        => $f['title'],
                    'meta'         => $f['slug'] . ' · ' . (int)$f['submission_count']
                                      . ' ' . __('submissions', 'submissions'),
                    'badge'        => [$f['status'], $statusColor],
                    'detail'       => [
                        'Title'        => $f['title'],
                        'Slug'         => $f['slug'],
                        'Status'       => $f['status'],
                        'Submissions'  => (int)$f['submission_count'],
                        'Created'      => $f['created_at'] ?? '—',
                        'Updated'      => $f['updated_at'] ?? '—',
                    ],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
