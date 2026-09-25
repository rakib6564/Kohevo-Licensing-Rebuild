<?php
/**
 * Forms — single submission detail.
 *
 * Right-rail layout: main column shows each field's submitted
 * value; aside has submission metadata + audit trail (received,
 * email, read, webhook dispatch).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';
require_once dirname(__DIR__) . '/lib/FormsPdf.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('forms.view');
FormsAPI::ensureSchema();

$tid = current_tenant_id();
$id  = (int)($_GET['id'] ?? 0);

$row = Database::row(
    "SELECT s.*, f.title AS form_title, f.slug AS form_slug,
            f.fields_json AS form_fields_json, f.settings_json AS form_settings_json
       FROM forms_submissions s
       JOIN forms_definitions f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
      WHERE s.id = ? AND s.tenant_id = ?",
    [$id, $tid]
);

if (!$row) {
    http_response_code(404);
    $pageTitle = __('forms_submission_not_found', 'Submission not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><h1>' . e(__('forms_submission_not_found', 'Submission not found')) . '</h1></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

// First view marks it read
if (empty($row['read_at'])) {
    Database::update('forms_submissions',
        ['read_at' => slate_db_now()],
        'id = ? AND tenant_id = ?',
        [$id, $tid]
    );
    $row['read_at'] = slate_db_now();
}

$data   = json_decode($row['data_json']        ?? '{}', true) ?: [];
$fields = json_decode($row['form_fields_json'] ?? '[]', true) ?: [];

// Branded PDF download (GET ?download=pdf). Streams inline so the browser's
// built-in viewer opens it — admin can read, print, or save from there.
if (($_GET['download'] ?? '') === 'pdf') {
    $fset = FormsAPI::formSettings($row['form_settings_json'] ?? null);
    try {
        $pdf = FormsPdf::render(
            ['title' => $row['form_title'], 'fields' => $fields],
            $data,
            (string)$row['ref'],
            [
                'brand'        => FormsPdf::brandFromSettings(),
                'accent'       => $fset['accent'] ?? '',
                'submitted_at' => (string)($row['created_at'] ?? slate_db_now()),
                // Match the email/public PDF so admin downloads honour the
                // form's configured page size, sender profile, and signature mode.
                'page'         => $fset['pdf_page'] ?? 'a4',
                'sender'       => $fset['sender'] ?? [],
                'sign'         => $fset['pdf_sign'] ?? true,
                'sign_both'    => $fset['pdf_sign_both'] ?? false,
            ]
        );
    } catch (\Throwable $e) {
        http_response_code(500);
        echo e(__('forms_pdf_error', 'Could not generate PDF: ')) . e($e->getMessage());
        exit;
    }
    $fname = 'submission-' . preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$row['ref']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fname . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

// POST handlers
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'delete' && Auth::can('forms.manage')) {
            Database::delete('forms_submissions', 'id = ? AND tenant_id = ?', [$id, $tid]);
            AuditLog::record('forms.submission_deleted', (string)$id);
            header('Location: ' . plugin_url('forms', 'admin/submissions.php')
                . '?form_id=' . (int)$row['form_id']);
            exit;
        }
        if ($action === 'mark_unread') {
            Database::update('forms_submissions', ['read_at' => null],
                'id = ? AND tenant_id = ?', [$id, $tid]);
            header('Location: ' . plugin_url('forms', 'admin/submissions.php') . '?form_id=' . (int)$row['form_id']);
            exit;
        }
        if ($action === 'set_status') {
            $st = (string)($_POST['status'] ?? '');
            if (in_array($st, ['new', 'in_progress', 'done'], true)) {
                Database::update('forms_submissions', ['status' => $st],
                    'id = ? AND tenant_id = ?', [$id, $tid]);
                $row['status'] = $st; // reflect immediately in this render
                $label = [
                    'new'         => __('forms_status_new', 'New'),
                    'in_progress' => __('forms_status_in_progress', 'In progress'),
                    'done'        => __('forms_status_done', 'Done'),
                ][$st];
                $flash = ['type' => 'success', 'msg' => sprintf(__('forms_status_set_to', 'Status set to %s.'), $label)];
            }
        }
    }
}

// Build audit trail from what we know
$audit = [];
$audit[] = ['action' => __('forms_audit_received', 'Submission received'), 'when' => $row['created_at'], 'muted' => false,
            'detail' => $row['ip'] ? sprintf(__('forms_audit_from', 'from %s'), $row['ip']) : ''];
if ((int)$row['email_sent'] === 1) {
    $audit[] = ['action' => __('forms_audit_notif_sent', 'Admin notification sent'), 'when' => $row['created_at'], 'muted' => true];
} elseif (!empty($row['email_error'])) {
    $audit[] = ['action' => __('forms_audit_notif_failed', 'Admin notification failed'), 'when' => $row['created_at'], 'muted' => false,
                'detail' => $row['email_error']];
}
$audit[] = ['action' => __('forms_audit_read', 'Read in admin'), 'when' => $row['read_at'], 'muted' => true];

// Recent webhook dispatch logs for this submission
$hookLogs = Database::rows(
    "SELECT l.*, w.url AS hook_url
       FROM forms_webhook_log l
       JOIN forms_webhooks w ON w.id = l.webhook_id
      WHERE l.tenant_id = ? AND l.submission_id = ?
   ORDER BY l.created_at",
    [$tid, $id]
);
foreach ($hookLogs as $l) {
    $ok = ($l['status_code'] !== null && (int)$l['status_code'] >= 200 && (int)$l['status_code'] < 300);
    $audit[] = [
        'action' => ($ok ? __('forms_audit_webhook_ok', 'Webhook delivered') : __('forms_audit_webhook_fail', 'Webhook failed')) . ' · ' . parse_url($l['hook_url'], PHP_URL_HOST),
        'when'   => $l['created_at'],
        'muted'  => $ok,
        'detail' => $l['status_code'] !== null
                    ? sprintf(__('forms_audit_http', 'HTTP %s'), (int)$l['status_code'])
                    : ($l['error'] ?: __('forms_audit_no_response', 'no response')),
    ];
}

$pageTitle  = sprintf(__('forms_submission_title', 'Submission %s'), $row['ref']);
$currentNav = 'forms-submissions';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms'), 'href' => plugin_url('forms', 'admin/index.php')],
    ['label' => __('forms_submissions', 'Submissions'), 'href' => plugin_url('forms', 'admin/submissions.php') . '?form_id=' . (int)$row['form_id']],
    ['label' => $row['ref']],
]); ?>

<?php
$isRead     = !empty($row['read_at']);
$statusText = $isRead ? __('forms_status_read', 'Read') : __('forms_status_new', 'New');
$statusTone = $isRead ? 'off'  : 'warn';

$pdfHref    = plugin_url('forms', 'admin/submission.php') . '?id=' . (int)$id . '&download=pdf';
$pdfBtn     = '<a href="' . e($pdfHref) . '" target="_blank" rel="noopener" class="btn">'
            . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>'
            . e(__('forms_download_pdf', 'Download PDF')) . '</a>';
$markUnread = '<form method="post" style="display:inline;margin:0;">' . csrf_field()
            . '<button name="_action" value="mark_unread" class="btn">' . e(__('forms_mark_unread', 'Mark unread')) . '</button></form>';
$deleteBtn  = Auth::can('forms.manage')
    ? '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('forms_confirm_delete_submission', 'Delete this submission?')) . '\')">' . csrf_field()
      . '<button name="_action" value="delete" class="btn btn-danger">' . e(__('delete', 'Delete')) . '</button></form>'
    : '';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php forms_editor_css(); ?>

<?php forms_edit_open(['title_fallback' => __('forms_submission', 'Submission')]); ?>

    <?php forms_edit_backlink([
        'back_href'  => plugin_url('forms', 'admin/submissions.php') . '?form_id=' . (int)$row['form_id'],
        'back_label' => __('forms_all_submissions', 'All submissions'),
    ]); ?>

    <?php forms_edit_hero([
        'icon'         => 'mail',
        'title'        => $row['ref'],
        'status_text'  => $statusText,
        'status_tone'  => $statusTone,
        'actions_html' => $deleteBtn . $markUnread . $pdfBtn,
        'stats'        => [
            [__('forms_form', 'Form'),     '<small>' . e($row['form_title']) . '</small>'],
            [__('forms_received', 'Received'), '<small>' . e(I18n::localDate('j M Y, H:i', strtotime($row['created_at']))) . '</small>'],
            [__('forms_source', 'Source'),   '<small>' . e($row['submitter_email'] ?: ($row['ip'] ?: '—')) . '</small>'],
        ],
    ]); ?>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">
        <?php forms_edit_card_open(['icon' => 'list', 'eyebrow' => __('forms_submitted_eyebrow', 'Submitted'), 'title' => __('forms_form_fields', 'Form fields')]); ?>
            <ul class="kv-list">
                <?php foreach ($fields as $f):
                    $name  = $f['name']  ?? '';
                    $label = $f['label'] ?? $name;
                    if ($name === '') continue;
                    $value = $data[$name] ?? null;

                    if (is_bool($value)) {
                        $display = $value ? __('yes', 'Yes') : __('no', 'No');
                    } elseif (is_array($value)) {
                        // Signature has {signature, path, mode, name}; file has
                        // {path, original, size, mime}. Both carry 'path', so
                        // signatures must be checked first.
                        if (!empty($value['signature'])) {
                            $display = ['signature' => $value];
                        } elseif (isset($value['path'])) {
                            $display = ['file' => $value];
                        } else {
                            $display = implode(', ', array_map('strval', $value));
                        }
                    } else {
                        $display = (string)$value;
                    }
                ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= e($label) ?></span>
                        <span class="kv-value"><?php
                            if (is_array($display) && isset($display['signature'])) {
                                $sig  = $display['signature'];
                                $href = SLATE_URL . e($sig['path']);
                                echo '<a href="' . $href . '" target="_blank" class="forms-sig-thumb">'
                                   . '<img src="' . $href . '" alt="' . e(__('forms_signature', 'Signature')) . '" style="max-width:240px;max-height:90px;border:1px solid var(--border);border-radius:8px;background:#fff;display:block;"></a>';
                                $tag = (($sig['mode'] ?? '') === 'type') ? __('forms_sig_typed', 'Typed') : __('forms_sig_drawn', 'Drawn');
                                echo '<span class="text-xs text-muted">' . e($tag)
                                   . (!empty($sig['name']) ? ' · ' . e($sig['name']) : '') . '</span>';
                            } elseif (is_array($display) && isset($display['file'])) {
                                $file = $display['file'];
                                $href = SLATE_URL . e($file['path']);
                                echo '<a href="' . $href . '" target="_blank">'
                                   . e($file['original'] ?: basename($file['path']))
                                   . '</a> <span class="text-xs text-muted">('
                                   . e(number_format((float)$file['size'] / 1024, 1)) . ' KB)</span>';
                            } elseif ($display === '') {
                                echo '<span class="text-muted">' . e(__('forms_empty_value', '— empty —')) . '</span>';
                            } elseif (($f['type'] ?? '') === 'textarea') {
                                echo nl2br(e((string)$display));
                            } else {
                                echo e((string)$display);
                            }
                        ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php forms_edit_card_close(); ?>
    </div>

    <aside class="page-aside">
        <?php $st = $row['status'] ?? 'new'; ?>
        <style>
        .status-seg { display: flex; gap: 6px; }
        .status-seg-btn {
            flex: 1; padding: 8px 6px; font: inherit; font-size: 12px; font-weight: 600;
            border: 1px solid var(--border); background: var(--surface); color: var(--muted);
            border-radius: 9px; cursor: pointer; transition: background .12s, color .12s, border-color .12s;
        }
        .status-seg-btn:hover { border-color: var(--border-stronger, #CFD2D8); color: var(--text); }
        .status-seg-btn.is-on { color: #fff; border-color: transparent; }
        .status-seg-btn.is-on[value="new"]         { background: var(--accent, #6366F1); }
        .status-seg-btn.is-on[value="in_progress"] { background: #F59E0B; }
        .status-seg-btn.is-on[value="done"]        { background: #22C55E; }
        </style>
        <?php forms_edit_card_open(['icon' => 'tag', 'eyebrow' => __('forms_workflow_eyebrow', 'Workflow'), 'title' => __('status', 'Status')]); ?>
            <form method="post" class="status-seg">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="set_status">
                <button type="submit" name="status" value="new"         class="status-seg-btn<?= $st === 'new'         ? ' is-on' : '' ?>"><?= __('forms_status_new', 'New') ?></button>
                <button type="submit" name="status" value="in_progress" class="status-seg-btn<?= $st === 'in_progress' ? ' is-on' : '' ?>"><?= __('forms_status_in_progress', 'In progress') ?></button>
                <button type="submit" name="status" value="done"        class="status-seg-btn<?= $st === 'done'        ? ' is-on' : '' ?>"><?= __('forms_status_done', 'Done') ?></button>
            </form>
        <?php forms_edit_card_close(); ?>

        <?php forms_edit_card_open(['icon' => 'tag', 'eyebrow' => __('forms_meta_eyebrow', 'Meta'), 'title' => __('forms_submission_info', 'Submission info')]); ?>
            <ul class="kv-list">
                <?php if ($row['submitter_email']): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= __('forms_submitter', 'Submitter') ?></span>
                        <span class="kv-value"><a href="mailto:<?= e($row['submitter_email']) ?>"><?= e($row['submitter_email']) ?></a></span>
                    </li>
                <?php endif; ?>
                <li class="kv-row">
                    <span class="kv-label"><?= __('forms_spam_ip', 'IP') ?></span>
                    <span class="kv-value kv-mono"><?= e($row['ip'] ?: '—') ?></span>
                </li>
                <?php if (!empty($row['country'])): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= __('forms_spam_country', 'Country') ?></span>
                        <span class="kv-value kv-mono"><?= e($row['country']) ?></span>
                    </li>
                <?php endif; ?>
                <?php if ($row['user_agent']): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= __('forms_spam_user_agent', 'User agent') ?></span>
                        <span class="kv-value text-xs" style="text-align:right;"><?= e(mb_strimwidth($row['user_agent'], 0, 80, '…')) ?></span>
                    </li>
                <?php endif; ?>
            </ul>
        <?php forms_edit_card_close(); ?>

        <?php forms_edit_card_open(['icon' => 'clock', 'eyebrow' => __('forms_history_eyebrow', 'History'), 'title' => __('forms_audit_trail', 'Audit trail')]); ?>
            <ol class="audit-trail">
                <?php foreach ($audit as $a): if (empty($a['when'])) continue; ?>
                    <li class="audit-trail-item<?= !empty($a['muted']) ? ' is-muted' : '' ?>">
                        <div class="audit-trail-action"><?= e($a['action']) ?></div>
                        <div class="audit-trail-meta"><?= e(I18n::localDate('j M Y, H:i', strtotime($a['when']))) ?></div>
                        <?php if (!empty($a['detail'])): ?>
                            <div class="audit-trail-detail"><?= e((string)$a['detail']) ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php forms_edit_card_close(); ?>
    </aside>

<?php slate_page_layout_end(); ?>

<?php /* Actions moved into the hero header (top-right) — see forms_edit_hero above. */ ?>

<?php forms_edit_close(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
