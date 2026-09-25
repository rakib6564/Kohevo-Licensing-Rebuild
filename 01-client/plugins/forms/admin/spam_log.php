<?php
/**
 * Forms — spam & security log.
 *
 * Every blocked submission recorded by FormsSpamGuard (when a form's
 * "log blocked attempts" toggle is on) lands here. Filter by form and
 * block reason; clear the log per-form or wholesale. Read-only data —
 * nothing here is ever re-submitted.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';

Auth::require();
Auth::requirePerm('forms.view');
FormsAPI::ensureSchema();

$tid = current_tenant_id();

// Human labels + tone + icon for the block codes FormsSpamGuard records.
$codeMeta = [
    'ip'         => [__('forms_spam_code_ip', 'IP blocklist'),         'danger',  'lock'],
    'timetrap'   => [__('forms_spam_code_timetrap', 'Too fast'),       'warning', 'clock'],
    'keyword'    => [__('forms_spam_code_keyword', 'Keyword'),         'warning', 'type'],
    'links'      => [__('forms_spam_code_links', 'Too many links'),    'warning', 'globe'],
    'email'      => [__('forms_spam_code_email', 'Email blocklist'),   'danger',  'mail'],
    'disposable' => [__('forms_spam_code_disposable', 'Disposable email'), 'warning', 'mail'],
    'country'    => [__('forms_spam_code_country', 'Country'),         'info',    'map-pin'],
    'rate'       => [__('forms_spam_code_rate', 'Rate limit'),         'info',    'clock'],
    'captcha'    => [__('forms_spam_code_captcha', 'CAPTCHA'),         'info',    'shield'],
];

// ── POST handlers (clear log) ────────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'clear' && Auth::can('forms.manage')) {
        $clearForm = (int)($_POST['form_id'] ?? 0);
        if ($clearForm > 0) {
            $n = Database::delete('forms_spam_log', 'tenant_id = ? AND form_id = ?', [$tid, $clearForm]);
        } else {
            $n = Database::delete('forms_spam_log', 'tenant_id = ?', [$tid]);
        }
        AuditLog::record('forms.spam_log_cleared', (string)$clearForm);
        $flash = ['type' => 'success', 'msg' => __('forms_spam_cleared', 'Spam log cleared.')];
    }
}

// ── Filters ──────────────────────────────────────────────────
$formId = (int)($_GET['form_id'] ?? 0);
$code   = (string)($_GET['code'] ?? '');
if ($code !== '' && !isset($codeMeta[$code])) $code = '';

$forms = Database::rows(
    "SELECT id, slug, title FROM forms_definitions WHERE tenant_id = ? ORDER BY title",
    [$tid]
);
$formTitles = [];
foreach ($forms as $f) { $formTitles[(int)$f['id']] = $f['title']; }

$where  = ['l.tenant_id = ?'];
$params = [$tid];
if ($formId > 0) { $where[] = 'l.form_id = ?'; $params[] = $formId; }
if ($code !== '') { $where[] = 'l.code = ?'; $params[] = $code; }
$whereSql = implode(' AND ', $where);

// Pagination
$perPage    = 40;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalRows  = (int) Database::value("SELECT COUNT(*) FROM forms_spam_log l WHERE $whereSql", $params);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = Database::rows(
    "SELECT l.* FROM forms_spam_log l
      WHERE $whereSql
   ORDER BY l.id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Headline counts (unfiltered) for the page sub-line + stat strip.
$blocked30 = (int) Database::value(
    "SELECT COUNT(*) FROM forms_spam_log WHERE tenant_id = ? AND created_at > (NOW() - INTERVAL 30 DAY)",
    [$tid]
);
$blockedTotal = (int) Database::value(
    "SELECT COUNT(*) FROM forms_spam_log WHERE tenant_id = ?", [$tid]);
$blockedToday = (int) Database::value(
    "SELECT COUNT(*) FROM forms_spam_log WHERE tenant_id = ? AND DATE(created_at) = CURDATE()", [$tid]);
$blockedIps = (int) Database::value(
    "SELECT COUNT(DISTINCT ip) FROM forms_spam_log WHERE tenant_id = ? AND ip IS NOT NULL AND ip <> '' AND created_at > (NOW() - INTERVAL 30 DAY)",
    [$tid]);

$pageTitle  = __('forms_spam_log', 'Spam log');
$currentNav = 'forms-spam-log';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms'), 'href' => plugin_url('forms', 'admin/index.php')],
    ['label' => __('forms_spam_log', 'Spam log')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('forms_spam_log', 'Spam log') ?></h1>
        <p class="page-header-sub">
            <?= sprintf(__('forms_spam_subtitle', '%s blocked attempt(s) match this view · %s in the last 30 days.'), number_format($totalRows), number_format($blocked30)) ?>
        </p>
    </div>
    <?php if ($totalRows > 0 && Auth::can('forms.manage')): ?>
        <form method="post" style="margin:0;"
              onsubmit="return confirm('<?= $formId > 0 ? e(__('forms_spam_confirm_clear_form', "Clear this form's spam log? This cannot be undone.")) : e(__('forms_spam_confirm_clear_all', 'Clear the entire spam log? This cannot be undone.')) ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="form_id" value="<?= (int)$formId ?>">
            <button name="_action" value="clear" class="btn btn-danger">
                <?= sprintf(__('forms_spam_clear_btn', 'Clear %s'), $formId > 0 ? __('forms_spam_this_form_word', 'this form') : __('forms_spam_all_word', 'all')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<style>
.sl-stats { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 12px; margin-bottom: 16px; }
.sl-stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); padding: 14px 16px; }
.sl-stat-num { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1.1; font-family: var(--font-display, inherit); }
.sl-stat-label { font-size: 11.5px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-top: 4px; }
.sl-stat.is-alert .sl-stat-num { color: var(--danger, #DC2626); }
@media (max-width: 680px) { .sl-stats { grid-template-columns: repeat(2, minmax(0,1fr)); gap: 10px; } .sl-stat-num { font-size: 20px; } }
</style>

<?php if ($blockedTotal > 0): ?>
<div class="sl-stats">
    <div class="sl-stat<?= $blockedToday > 0 ? ' is-alert' : '' ?>">
        <div class="sl-stat-num"><?= number_format($blockedToday) ?></div>
        <div class="sl-stat-label"><?= __('forms_spam_today', 'Today') ?></div>
    </div>
    <div class="sl-stat">
        <div class="sl-stat-num"><?= number_format($blocked30) ?></div>
        <div class="sl-stat-label"><?= __('forms_spam_last_30d', 'Last 30 days') ?></div>
    </div>
    <div class="sl-stat">
        <div class="sl-stat-num"><?= number_format($blockedTotal) ?></div>
        <div class="sl-stat-label"><?= __('forms_spam_all_time', 'All time') ?></div>
    </div>
    <div class="sl-stat">
        <div class="sl-stat-num"><?= number_format($blockedIps) ?></div>
        <div class="sl-stat-label"><?= __('forms_spam_unique_ips', 'Unique IPs (30d)') ?></div>
    </div>
</div>
<?php endif; ?>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="form_id"><?= __('forms_form', 'Form') ?></label>
            <select id="form_id" name="form_id">
                <option value="0"><?= __('forms_spam_all_forms', 'All forms') ?></option>
                <?php foreach ($forms as $f): ?>
                    <option value="<?= (int)$f['id'] ?>" <?= $formId === (int)$f['id'] ? 'selected' : '' ?>>
                        <?= e($f['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="code"><?= __('reason', 'Reason') ?></label>
            <select id="code" name="code">
                <option value=""><?= __('forms_spam_all_reasons', 'All reasons') ?></option>
                <?php foreach ($codeMeta as $k => $meta): ?>
                    <option value="<?= e($k) ?>" <?= $code === $k ? 'selected' : '' ?>><?= e($meta[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn"><?= __('filter', 'Filter') ?></button>
            <a href="<?= e(plugin_url('forms', 'admin/spam_log.php')) ?>" class="btn btn-ghost"><?= __('reset', 'Reset') ?></a>
        </div>
    </form>
</div>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('forms_spam_nothing_blocked', 'Nothing blocked') ?></div>
            <p class="text-sm"><?= sprintf(
                __('forms_spam_empty_desc', "When the spam guard stops a submission, it'll be logged here. Turn on the checks under any form's %s tab."),
                '<strong>' . e(__('forms_spam_security_tab', 'Spam & Security')) . '</strong>'
            ) ?></p>
        </div>
    </div>
<?php else: ?>

    <div class="data-list" data-single-open>
        <?php foreach ($rows as $r):
            $meta  = $codeMeta[$r['code']] ?? [$r['code'], 'inactive', 'shield'];
            $form  = $formTitles[(int)$r['form_id']] ?? sprintf(__('forms_spam_form_hash', 'Form #%d'), (int)$r['form_id']);
            $where = trim(($r['ip'] ?: '—') . (!empty($r['country']) ? ' · ' . $r['country'] : ''));

            $detail = [
                __('reason', 'Reason')   => $r['reason'] ?: $meta[0],
                __('forms_form', 'Form') => $form,
                __('forms_spam_when', 'When') => I18n::localDate('j M Y, H:i', strtotime($r['created_at'])),
                __('forms_spam_ip', 'IP')     => $r['ip'] ?: '—',
            ];
            if (!empty($r['country']))    $detail[__('forms_spam_country', 'Country')]       = $r['country'];
            if (!empty($r['user_agent'])) $detail[__('forms_spam_user_agent', 'User agent')] = ['label' => __('forms_spam_user_agent', 'User agent'), 'value' => $r['user_agent']];
            if (!empty($r['snippet']))    $detail[__('forms_spam_content', 'Content')]       = ['label' => __('forms_spam_content', 'Content'), 'value' => $r['snippet']];

            slate_data_row([
                'avatar_html'  => FormsAPI::cardIcon($meta[2] ?? 'shield'),
                'avatar_color' => $meta[1] === 'danger' ? 'danger' : ($meta[1] === 'warning' ? 'warning' : 'muted'),
                'title'        => $meta[0],
                'meta'         => $form . ' · ' . $where . ' · ' . I18n::localDate('j M, H:i', strtotime($r['created_at'])),
                'badge'        => [$meta[0], $meta[1]],
                'detail'       => $detail,
            ]);
        endforeach; ?>
    </div>

    <?php slate_data_list_script(); ?>
    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalRows, 'per_page' => $perPage, 'label' => __('forms_spam_pager_label', 'blocked attempts'),
    ]); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
