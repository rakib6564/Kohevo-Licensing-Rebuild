<?php
/**
 * Forms — admin list page.
 * Cards-row list of all forms in the tenant, plus a "New form" CTA.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';

Auth::require();
Auth::requirePerm('forms.view');
FormsAPI::ensureSchema();

$pageTitle  = __('forms', 'Forms');
$currentNav = 'forms';

$tid = current_tenant_id();

// ── POST handlers (delete + status toggle) ──────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            if ($action === 'delete' && Auth::can('forms.manage')) {
                Database::delete('forms_submissions', 'form_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('forms_webhooks',    'form_id = ? AND tenant_id = ?', [$id, $tid]);
                Database::delete('forms_definitions', 'id = ? AND tenant_id = ?',     [$id, $tid]);
                AuditLog::record('forms.deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('forms_list_deleted', 'Form deleted.')];
            } elseif ($action === 'publish' && Auth::can('forms.manage')) {
                Database::update('forms_definitions', ['status' => 'published'],
                    'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('forms.published', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('forms_list_published', 'Form published.')];
            } elseif ($action === 'unpublish' && Auth::can('forms.manage')) {
                Database::update('forms_definitions', ['status' => 'draft'],
                    'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('forms.unpublished', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('forms_list_unpublished', 'Form moved to draft.')];
            } elseif ($action === 'archive' && Auth::can('forms.manage')) {
                Database::update('forms_definitions', ['status' => 'archived'],
                    'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('forms.archived', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('forms_list_archived', 'Form archived.')];
            } elseif ($action === 'duplicate' && Auth::can('forms.manage')) {
                $src = Database::row(
                    "SELECT * FROM forms_definitions WHERE id = ? AND tenant_id = ?", [$id, $tid]);
                if ($src) {
                    $newTitle = mb_substr($src['title'] . ' ' . __('forms_list_copy_suffix', '(copy)'), 0, 200);
                    Database::insert('forms_definitions', [
                        'tenant_id'         => $tid,
                        'slug'              => FormsAPI::slugify($newTitle),
                        'title'             => $newTitle,
                        'description'       => $src['description'],
                        'fields_json'       => $src['fields_json'],
                        'submit_label'      => $src['submit_label'],
                        'success_message'   => $src['success_message'],
                        'redirect_url'      => $src['redirect_url'],
                        'notify_email'      => $src['notify_email'],
                        'confirm_submitter' => $src['confirm_submitter'],
                        'confirm_subject'   => $src['confirm_subject'],
                        'confirm_body'      => $src['confirm_body'],
                        'status'            => 'draft',
                        'submission_limit'  => $src['submission_limit'],
                        'settings_json'     => $src['settings_json'],
                    ]);
                    AuditLog::record('forms.duplicated', (string)$id);
                    $flash = ['type' => 'success', 'msg' => __('forms_list_duplicated', 'Form duplicated as a draft.')];
                }
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────
$q            = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? ''); // '' | published | draft | archived

$where  = ['d.tenant_id = ?'];
$params = [$tid];
if ($q !== '') {
    $where[]  = '(d.title LIKE ? OR d.slug LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['published', 'draft', 'archived'], true)) {
    $where[]  = 'd.status = ?';
    $params[] = $statusFilter;
}
$whereSql = implode(' AND ', $where);

$forms = Database::rows(
    "SELECT d.*,
            (SELECT COUNT(*) FROM forms_submissions s
              WHERE s.form_id = d.id AND s.tenant_id = d.tenant_id) AS submission_count,
            (SELECT COUNT(*) FROM forms_submissions s
              WHERE s.form_id = d.id AND s.tenant_id = d.tenant_id AND s.read_at IS NULL) AS unread_count,
            (SELECT MAX(s.created_at) FROM forms_submissions s
              WHERE s.form_id = d.id AND s.tenant_id = d.tenant_id) AS last_submission_at
       FROM forms_definitions d
      WHERE $whereSql
   ORDER BY d.updated_at DESC, d.id DESC",
    $params
);

// ── Summary stats (tenant-wide, independent of filters) ──────
$statForms     = (int) Database::value("SELECT COUNT(*) FROM forms_definitions WHERE tenant_id = ?", [$tid]);
$statPublished = (int) Database::value("SELECT COUNT(*) FROM forms_definitions WHERE tenant_id = ? AND status = 'published'", [$tid]);
$statSubs      = (int) Database::value("SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ?", [$tid]);
$statUnread    = (int) Database::value("SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND read_at IS NULL", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('forms', 'Forms') ?></h1>
        <p class="page-header-sub"><?= e(__('forms_list_subtitle', 'Public forms you can embed or link to.')) ?></p>
    </div>
    <?php if (Auth::can('forms.manage')): ?>
        <button type="button" class="btn btn-primary" data-forms-new><?= e(__('forms_list_new_form', 'New form')) ?></button>
        <noscript><a href="<?= e(plugin_url('forms', 'admin/edit.php')) ?>" class="btn btn-primary"><?= e(__('forms_list_new_form', 'New form')) ?></a></noscript>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<style>
.fl-stats { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; margin-bottom: 16px; }
.fl-stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); padding: 14px 16px; }
.fl-stat-num { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1.1; font-family: var(--font-display, inherit); }
.fl-stat-label { font-size: 11.5px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-top: 4px; }
.fl-stat.is-alert .fl-stat-num { color: var(--accent, #4F46E5); }
@media (max-width: 680px) { .fl-stats { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; } .fl-stat-num { font-size: 20px; } }
/* Compact inline copy button used inside expanded detail rows */
.btn-xs { padding: 2px 9px; font-size: 11px; min-height: 0; line-height: 1.6; vertical-align: middle; }
/* Copy-link button transient state */
.btn[data-copy].is-copied { color: var(--success, #15803D); border-color: var(--success, #15803D); }
</style>

<?php if ($statForms > 0): ?>
<div class="fl-stats">
    <div class="fl-stat">
        <div class="fl-stat-num"><?= number_format($statForms) ?></div>
        <div class="fl-stat-label"><?= e(__('forms', 'Forms')) ?></div>
    </div>
    <div class="fl-stat">
        <div class="fl-stat-num"><?= number_format($statPublished) ?></div>
        <div class="fl-stat-label"><?= e(__('forms_list_published_label', 'Published')) ?></div>
    </div>
    <div class="fl-stat">
        <div class="fl-stat-num"><?= number_format($statSubs) ?></div>
        <div class="fl-stat-label"><?= e(__('forms_submissions', 'Submissions')) ?></div>
    </div>
    <div class="fl-stat<?= $statUnread > 0 ? ' is-alert' : '' ?>">
        <div class="fl-stat-num"><?= number_format($statUnread) ?></div>
        <div class="fl-stat-label"><?= e(__('forms_unread', 'Unread')) ?></div>
    </div>
</div>
<?php endif; ?>

<?php if ($statForms > 0): ?>
<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="q"><?= e(__('search', 'Search')) ?></label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('forms_list_search_ph', 'Form name or slug…')) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="status"><?= e(__('status', 'Status')) ?></label>
            <select id="status" name="status">
                <option value=""          <?= $statusFilter === ''          ? 'selected' : '' ?>><?= e(__('forms_list_any_status', 'Any status')) ?></option>
                <option value="published" <?= $statusFilter === 'published' ? 'selected' : '' ?>><?= e(__('forms_list_published_label', 'Published')) ?></option>
                <option value="draft"     <?= $statusFilter === 'draft'     ? 'selected' : '' ?>><?= e(__('forms_list_draft_label', 'Draft')) ?></option>
                <option value="archived"  <?= $statusFilter === 'archived'  ? 'selected' : '' ?>><?= e(__('forms_list_archived_label', 'Archived')) ?></option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn"><?= e(__('filter', 'Filter')) ?></button>
            <a href="<?= e(plugin_url('forms', 'admin/index.php')) ?>" class="btn btn-ghost"><?= e(__('reset', 'Reset')) ?></a>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (empty($forms)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h6"/>
                </svg>
            </div>
            <?php if ($statForms > 0): ?>
                <div class="empty-title"><?= e(__('forms_list_no_match', 'No matching forms')) ?></div>
                <p class="text-sm"><?= e(__('forms_list_try_different_search', 'Try a different search or reset the filters.')) ?></p>
                <a href="<?= e(plugin_url('forms', 'admin/index.php')) ?>" class="btn btn-ghost mt-3"><?= e(__('forms_list_reset_filters', 'Reset filters')) ?></a>
            <?php else: ?>
                <div class="empty-title"><?= e(__('forms_list_no_forms_yet', 'No forms yet')) ?></div>
                <p class="text-sm"><?= e(__('forms_list_create_first_hint', 'Create your first form and we\'ll give you a public URL + an embed snippet.')) ?></p>
                <?php if (Auth::can('forms.manage')): ?>
                    <button type="button" class="btn btn-primary mt-3" data-forms-new><?= e(__('forms_list_create_a_form', 'Create a form')) ?></button>
                    <noscript><a href="<?= e(plugin_url('forms', 'admin/edit.php')) ?>" class="btn btn-primary mt-3"><?= e(__('forms_list_create_a_form', 'Create a form')) ?></a></noscript>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

    <?php
    // Pick a meaningful icon for a form from keywords in its title; falls back
    // to the clipboard/form glyph. Uses FormsAPI's curated card-icon SVG set.
    $formIcon = static function (string $title): string {
        $t = strtolower($title);
        $map = [
            'powerboat' => 'boat', 'speedboat' => 'boat', 'boat' => 'boat', 'yacht' => 'boat',
            'vessel' => 'anchor', 'marine' => 'anchor', 'ship' => 'anchor', 'sail' => 'boat', 'anchor' => 'anchor',
            'survey' => 'star', 'feedback' => 'star', 'review' => 'star', 'rating' => 'star',
            'estimate' => 'dollar', 'quote' => 'dollar', 'price' => 'dollar', 'payment' => 'card',
            'donat' => 'heart', 'order' => 'tag',
            'booking' => 'calendar', 'appointment' => 'calendar', 'schedule' => 'calendar',
            'reserv' => 'calendar', 'rsvp' => 'calendar', 'event' => 'calendar',
            'contact' => 'mail', 'newsletter' => 'mail', 'subscribe' => 'mail', 'email' => 'mail',
            'job' => 'briefcase', 'applic' => 'briefcase', 'career' => 'briefcase', 'employ' => 'briefcase',
            'support' => 'wrench', 'ticket' => 'wrench', 'repair' => 'wrench', 'service' => 'wrench',
            'register' => 'user', 'signup' => 'user',
        ];
        foreach ($map as $kw => $ic) {
            if (strpos($t, $kw) !== false) return FormsAPI::cardIcon($ic);
        }
        return slate_admin_nav_icon('clipboard-list');
    };
    ?>
    <div class="data-list" data-single-open>
        <?php foreach ($forms as $f):
            $publicUrl = SLATE_URL . '/forms/' . $f['slug'];
            $editUrl   = plugin_url('forms', 'admin/edit.php') . '?id=' . (int)$f['id'];
            $inboxUrl  = plugin_url('forms', 'admin/submissions.php') . '?form_id=' . (int)$f['id'];
            $isPub     = $f['status'] === 'published';

            $badgeMap = [
                'published' => [__('forms_list_published_label', 'Published'), 'active'],
                'draft'     => [__('forms_list_draft_label', 'Draft'),     'warning'],
                'archived'  => [__('forms_list_archived_label', 'Archived'),  'inactive'],
            ];
            $badge = $badgeMap[$f['status']] ?? [__('forms_list_draft_label', 'Draft'), 'warning'];

            $fieldCount = count(json_decode($f['fields_json'] ?? '[]', true) ?: []);
            $embedSnippet = '<iframe src="' . $publicUrl . '?embed=1" style="width:100%;border:0;min-height:520px"></iframe>';

            $publishedOnlyHtml = '<span class="text-muted">' . e(__('forms_list_published_only', '— published forms only —')) . '</span>';
            $detail = [
                'Public URL' => ['label' => __('forms_list_public_url', 'Public URL'),
                    'html' => $isPub
                        ? '<a href="' . e($publicUrl) . '" target="_blank" rel="noopener">' . e($publicUrl) . '</a>'
                          . ' <button type="button" class="btn btn-xs btn-ghost" data-copy="' . e($publicUrl) . '">' . e(__('copy', 'Copy')) . '</button>'
                        : $publishedOnlyHtml,
                ],
                'Embed snippet' => ['label' => __('forms_list_embed_snippet', 'Embed snippet'),
                    'html' => $isPub
                        ? '<code style="word-break:break-all;">' . e($embedSnippet) . '</code>'
                          . ' <button type="button" class="btn btn-xs btn-ghost" data-copy="' . e($embedSnippet) . '">' . e(__('copy', 'Copy')) . '</button>'
                        : $publishedOnlyHtml,
                ],
                'Submissions' => [
                    'label' => __('forms_submissions', 'Submissions'),
                    'value' => sprintf(__('forms_list_n_total', '%d total'), (int)$f['submission_count'])
                        . ((int)$f['unread_count'] > 0 ? ' · ' . sprintf(__('forms_list_n_unread', '%d unread'), (int)$f['unread_count']) : ''),
                ],
                'Last submission' => [
                    'label' => __('forms_list_last_submission', 'Last submission'),
                    'value' => $f['last_submission_at']
                        ? I18n::localDate('j M Y, H:i', strtotime($f['last_submission_at'])) : __('forms_list_none_yet', '— none yet —'),
                ],
                'Fields'    => ['label' => __('forms_list_fields', 'Fields'), 'value' => sprintf(__('forms_list_n_configured', '%d configured'), $fieldCount)],
                'Updated'   => ['label' => __('updated', 'Updated'), 'value' => I18n::localDate('j M Y, H:i', strtotime($f['updated_at']))],
                'Notify'    => ['label' => __('forms_list_notify', 'Notify'), 'value' => $f['notify_email'] ?: __('forms_list_notify_none', '— none —')],
            ];

            $actions = '';
            if ($isPub) {
                $actions .= '<a href="' . e($publicUrl) . '" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">' . e(__('forms_list_open', 'Open ↗')) . '</a> '
                          . '<button type="button" class="btn btn-sm btn-ghost" data-copy="' . e($publicUrl) . '">' . e(__('forms_list_copy_link', 'Copy link')) . '</button> ';
            }
            $actions .= '<a href="' . e($editUrl)  . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                      . '<a href="' . e($inboxUrl) . '" class="btn btn-sm btn-ghost">' . e(__('forms_list_view_submissions', 'View submissions')) . '</a>';

            if (Auth::can('forms.manage')) {
                $actions .= ' <form method="post" style="display:inline; margin:0;">' . csrf_field()
                          . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">';
                if ($isPub) {
                    $actions .= '<button name="_action" value="unpublish" class="btn btn-sm btn-ghost">' . e(__('forms_list_unpublish', 'Unpublish')) . '</button>';
                } elseif ($f['status'] === 'draft') {
                    $actions .= '<button name="_action" value="publish" class="btn btn-sm btn-primary">' . e(__('forms_list_publish', 'Publish')) . '</button>';
                } elseif ($f['status'] === 'archived') {
                    $actions .= '<button name="_action" value="publish" class="btn btn-sm">' . e(__('forms_list_restore_publish', 'Restore & publish')) . '</button>';
                }
                $actions .= '</form>';

                // Duplicate
                $actions .= ' <form method="post" style="display:inline; margin:0;">' . csrf_field()
                          . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">'
                          . '<button name="_action" value="duplicate" class="btn btn-sm btn-ghost">' . e(__('duplicate', 'Duplicate')) . '</button>'
                          . '</form>';

                // Archive (only when not already archived)
                if ($f['status'] !== 'archived') {
                    $actions .= ' <form method="post" style="display:inline; margin:0;">' . csrf_field()
                              . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">'
                              . '<button name="_action" value="archive" class="btn btn-sm btn-ghost">' . e(__('archive', 'Archive')) . '</button>'
                              . '</form>';
                }

                $actions .= ' <form method="post" style="display:inline; margin:0;" onsubmit="return confirm(\'' . e(__('forms_list_confirm_delete', 'Delete this form and all its submissions?')) . '\')">' . csrf_field()
                          . '<input type="hidden" name="id" value="' . (int)$f['id'] . '">'
                          . '<button name="_action" value="delete" class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button>'
                          . '</form>';
            }

            $titleHtml = e($f['title']);
            if ($f['unread_count'] > 0) {
                $titleHtml .= ' <span class="badge badge-accent">' . sprintf(e(__('forms_list_n_unread', '%d unread')), (int)$f['unread_count']) . '</span>';
            }

            slate_data_row([
                'avatar_html'  => $formIcon($f['title']),
                'avatar_color' => 'info',
                'title_html'   => $titleHtml,
                'meta'         => '/forms/' . $f['slug'] . ' · ' . (int)$f['submission_count'] . ' submissions',
                'badge'        => $badge,
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>

    <?php slate_data_list_script(); ?>
    <script>
    (function () {
        if (window.__formsCopy) return;
        window.__formsCopy = true;
        var copiedLabel = <?= json_encode(__('copied', 'Copied!')) ?>;
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy]');
            if (!btn) return;
            e.preventDefault();
            var text = btn.getAttribute('data-copy') || '';
            var done = function () {
                var orig = btn.textContent;
                btn.textContent = copiedLabel;
                btn.classList.add('is-copied');
                setTimeout(function () { btn.textContent = orig; btn.classList.remove('is-copied'); }, 1300);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () {});
            } else {
                var ta = document.createElement('textarea');
                ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                document.body.removeChild(ta);
            }
        });
    })();
    </script>
<?php endif; ?>

<?php if (Auth::can('forms.manage') && class_exists('FormTemplates')):
    $blankUrl = plugin_url('forms', 'admin/edit.php');
?>
<dialog class="tpl-modal" id="forms-tpl-modal" aria-label="<?= e(__('forms_list_choose_starting_point', 'Choose a starting point')) ?>">
    <div class="tpl-head">
        <div>
            <div class="tpl-eyebrow"><?= e(__('forms_list_start_a_form', 'Start a form')) ?></div>
            <h2 class="tpl-title"><?= e(__('forms_list_choose_starting_point', 'Choose a starting point')) ?></h2>
        </div>
        <button type="button" class="tpl-x" data-tpl-close aria-label="<?= e(__('close', 'Close')) ?>">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="tpl-cats" id="tpl-cats">
        <button type="button" class="tpl-cat is-on" data-cat=""><?= e(__('all', 'All')) ?></button>
        <?php foreach (FormTemplates::categories() as $cat): ?>
            <button type="button" class="tpl-cat" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="tpl-grid" id="tpl-grid">
        <a class="tpl-card tpl-card-blank" data-cat="" href="<?= e($blankUrl) ?>">
            <span class="tpl-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg></span>
            <span class="tpl-name"><?= e(__('forms_list_blank_form', 'Blank form')) ?></span>
            <span class="tpl-desc"><?= e(__('forms_list_blank_form_desc', 'Start from scratch and build it your way.')) ?></span>
        </a>
        <?php foreach (FormTemplates::all() as $t): ?>
        <a class="tpl-card" data-cat="<?= e($t['category'] ?? '') ?>" href="<?= e($blankUrl . '?template=' . urlencode($t['slug'])) ?>">
            <span class="tpl-ico"><?= slate_admin_nav_icon($t['icon'] ?? 'box') ?></span>
            <span class="tpl-name"><?= e($t['name']) ?></span>
            <span class="tpl-desc"><?= e($t['desc']) ?></span>
            <?php if (!empty($t['badges'])): ?>
            <span class="tpl-badges">
                <?php foreach ($t['badges'] as $b): ?><span class="tpl-badge"><?= e($b) ?></span><?php endforeach; ?>
            </span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</dialog>
<style>
.tpl-modal{width:min(900px,calc(100vw - 32px));max-width:900px;border:1px solid var(--border);border-radius:18px;padding:0;background:var(--surface,#fff);color:var(--text);box-shadow:0 24px 60px rgba(15,17,23,.22);}
.tpl-modal::backdrop{background:rgba(14,17,23,.45);backdrop-filter:blur(3px);}
.tpl-head{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:20px 22px 14px;border-bottom:1px solid var(--border);}
.tpl-eyebrow{font-family:var(--font-mono,monospace);font-size:10.5px;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);margin-bottom:4px;}
.tpl-title{margin:0;font-size:18px;font-weight:700;letter-spacing:-.02em;}
.tpl-x{flex:none;width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--border);border-radius:999px;background:var(--surface-2,#f5f6f8);color:var(--muted);cursor:pointer;}
.tpl-x:hover{color:var(--text);background:var(--surface-sunken,#eef0f2);}
.tpl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding:20px 22px 24px;max-height:70vh;overflow:auto;}
.tpl-card{display:flex;flex-direction:column;gap:7px;padding:16px;border:1px solid var(--border);border-radius:13px;background:var(--surface,#fff);text-decoration:none;color:var(--text);transition:border-color .12s,box-shadow .12s,transform .12s;}
.tpl-card:hover{border-color:var(--accent);box-shadow:var(--shadow-md);transform:translateY(-2px);text-decoration:none;}
.tpl-ico{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:var(--accent-soft);color:var(--accent);}
.tpl-ico svg{width:20px;height:20px;}
.tpl-card-blank .tpl-ico{background:var(--surface-2,#f5f6f8);color:var(--muted);}
.tpl-name{font-size:14px;font-weight:650;letter-spacing:-.01em;}
.tpl-desc{font-size:12px;color:var(--muted);line-height:1.45;}
.tpl-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:3px;}
.tpl-badge{font-size:10px;font-weight:600;letter-spacing:.01em;color:var(--accent);background:var(--accent-soft);border-radius:999px;padding:2px 8px;}
.tpl-card-blank{border-style:dashed;}
/* Category filter chips */
.tpl-cats{display:flex;flex-wrap:wrap;gap:7px;padding:14px 22px 0;}
.tpl-cat{border:1px solid var(--border);background:var(--surface,#fff);color:var(--muted);border-radius:999px;padding:5px 13px;font-size:12.5px;font-weight:600;cursor:pointer;transition:all .12s;}
.tpl-cat:hover{color:var(--text);border-color:var(--border-stronger,#CFD2D8);}
.tpl-cat.is-on{background:var(--accent);border-color:var(--accent);color:#fff;}
.tpl-card.is-hidden{display:none;}
@media (max-width:720px){ .tpl-grid{grid-template-columns:1fr 1fr;} }
@media (max-width:460px){ .tpl-grid{grid-template-columns:1fr;} .tpl-cats{padding:12px 16px 0;} }
</style>
<script>
(function(){
    var modal = document.getElementById('forms-tpl-modal'); if (!modal) return;
    function open(){ try { modal.showModal(); } catch (e) { modal.setAttribute('open',''); } }
    function close(){ try { modal.close(); } catch (e) { modal.removeAttribute('open'); } }
    Array.prototype.forEach.call(document.querySelectorAll('[data-forms-new]'), function(b){ b.addEventListener('click', open); });
    Array.prototype.forEach.call(modal.querySelectorAll('[data-tpl-close]'), function(b){ b.addEventListener('click', close); });
    modal.addEventListener('click', function(e){ if (e.target === modal) close(); });

    // Category filtering
    var cats  = modal.querySelector('#tpl-cats');
    var cards = Array.prototype.slice.call(modal.querySelectorAll('.tpl-grid .tpl-card'));
    if (cats) {
        cats.addEventListener('click', function(e){
            var chip = e.target.closest('.tpl-cat'); if (!chip) return;
            var sel = chip.getAttribute('data-cat') || '';
            Array.prototype.forEach.call(cats.querySelectorAll('.tpl-cat'), function(c){ c.classList.toggle('is-on', c === chip); });
            cards.forEach(function(card){
                var cc = card.getAttribute('data-cat') || '';
                var show = sel === '' || cc === sel || card.classList.contains('tpl-card-blank');
                card.classList.toggle('is-hidden', !show);
            });
        });
    }
})();
</script>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
