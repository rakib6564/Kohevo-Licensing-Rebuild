<?php
/**
 * Forms — submissions inbox.
 *
 * Filter by form + read/unread. CSV export honours the current
 * filter set. Click into a row → submission detail (right-rail).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';

Auth::require();
Auth::requirePerm('forms.view');
ModuleGuard::require('forms');
FormsAPI::ensureSchema();

$tid = current_tenant_id();

// ── CSV export ───────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    Auth::requirePerm('forms.export');
    $formId = (int)($_GET['form_id'] ?? 0);
    if ($formId <= 0) {
        http_response_code(400);
        echo e(__('forms_specify_form_id', 'Specify a form_id to export.'));
        exit;
    }

    $form = FormsAPI::getFormById($formId);
    if (!$form) {
        http_response_code(404);
        echo e(__('form_not_found', 'Form not found.'));
        exit;
    }

    $rows = Database::rows(
        "SELECT * FROM forms_submissions
          WHERE tenant_id = ? AND form_id = ?
       ORDER BY created_at DESC",
        [$tid, $formId]
    );

    $fieldNames = [];
    foreach ($form['fields'] as $f) {
        if (!empty($f['name'])) $fieldNames[] = $f['name'];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="forms-' . preg_replace('/[^a-z0-9-]/i', '-', $form['slug']) . '-'
         . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    // BOM so Excel picks UTF-8
    fwrite($out, "\xEF\xBB\xBF");
    // Neutralise CSV/formula injection: a cell starting with = + - @ (or a
    // tab/CR) is run as a formula by Excel/Sheets. Submitted values are
    // attacker-controlled, so prefix risky cells with a quote.
    $csvSafe = static function ($v): string {
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $s;
        }
        return $s;
    };
    fputcsv($out, array_merge(['ref', 'created_at', 'ip', 'submitter_email'], $fieldNames));
    foreach ($rows as $r) {
        $data = json_decode($r['data_json'] ?? '{}', true) ?: [];
        $row  = [$r['ref'], $r['created_at'], $r['ip'], $r['submitter_email']];
        foreach ($fieldNames as $n) {
            $v = $data[$n] ?? '';
            if (is_bool($v)) {
                $v = $v ? 'yes' : 'no';
            } elseif (is_array($v) && (!empty($v['signature']) || isset($v['path']))) {
                // Signature or file: export the public URL (+ typed name).
                $v = rtrim(SLATE_URL, '/') . ($v['path'] ?? '');
                if (!empty($data[$n]['name'])) $v .= ' (' . $data[$n]['name'] . ')';
            } elseif (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            $row[] = (string)$v;
        }
        fputcsv($out, array_map($csvSafe, $row));
    }
    fclose($out);
    AuditLog::record('forms.exported', (string)$formId, ['count' => count($rows)]);
    exit;
}

/**
 * Best-effort display name for a submission, derived from its field data.
 * Looks for explicit name fields, then first/last pairs, then any "*name*"
 * field, and finally falls back to a title-cased email local-part.
 */
if (!function_exists('forms_submission_name')) {
    function forms_submission_name(array $data, string $email = ''): string {
        $norm = static fn(string $k): string => strtolower(preg_replace('/[^a-z]/i', '', $k));
        $full = ['name', 'fullname', 'yourname', 'customername', 'contactname'];
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            if (in_array($norm($k), $full, true)) return trim($v);
        }
        $first = $last = '';
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $kn = $norm($k);
            if ($first === '' && in_array($kn, ['firstname', 'fname', 'givenname'], true)) $first = trim($v);
            if ($last  === '' && in_array($kn, ['lastname', 'lname', 'surname', 'familyname'], true)) $last = trim($v);
        }
        if ($first !== '' || $last !== '') return trim($first . ' ' . $last);
        foreach ($data as $k => $v) {
            if (!is_string($v) || trim($v) === '') continue;
            $kl = strtolower($k);
            if (strpos($kl, 'name') !== false && !preg_match('/(company|business|file|user|nick)/', $kl)) {
                return trim($v);
            }
        }
        if ($email !== '') {
            $lp = explode('@', $email)[0];
            return ucwords(trim(str_replace(['.', '_', '-', '+'], ' ', $lp)));
        }
        return '';
    }
}

/** Up-to-two-letter initials for an avatar, from a name (or email). */
if (!function_exists('forms_initials')) {
    function forms_initials(string $name, string $email = ''): string {
        $src = trim($name) !== '' ? trim($name) : $email;
        if ($src === '') return '?';
        $parts = preg_split('/[\s@._-]+/', $src, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        return mb_strtoupper(mb_substr($src, 0, 2));
    }
}

/**
 * Gravatar image URL for an email, or '' if no email. Uses d=404 so the
 * request 404s when the address has no Gravatar — the <img> onerror then
 * falls back to the initials avatar. Size is doubled for retina.
 */
if (!function_exists('forms_gravatar_url')) {
    function forms_gravatar_url(string $email, int $size = 36): string {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) return '';
        return 'https://www.gravatar.com/avatar/' . md5($email)
             . '?s=' . ($size * 2) . '&d=404';
    }
}

// ── POST handlers (mark read/unread, delete — single or bulk) ─
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        // Accept a bulk ids[] set or a single id; normalise to one int list.
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
        if (empty($ids) && (int)($_POST['id'] ?? 0) > 0) $ids = [(int)$_POST['id']];

        if (!empty($ids)) {
            $place  = implode(',', array_fill(0, count($ids), '?'));
            $n      = count($ids);
            if ($action === 'mark_read') {
                Database::update('forms_submissions', ['read_at' => slate_db_now()],
                    "tenant_id = ? AND id IN ($place)", array_merge([$tid], $ids));
                $flash = ['type' => 'success', 'msg' => $n === 1 ? __('forms_marked_read_one', 'Marked as read.') : sprintf(__('forms_marked_read_n', '%d marked as read.'), $n)];
            } elseif ($action === 'mark_unread') {
                Database::update('forms_submissions', ['read_at' => null],
                    "tenant_id = ? AND id IN ($place)", array_merge([$tid], $ids));
                $flash = ['type' => 'success', 'msg' => $n === 1 ? __('forms_marked_unread_one', 'Marked as unread.') : sprintf(__('forms_marked_unread_n', '%d marked as unread.'), $n)];
            } elseif ($action === 'set_status') {
                $st = (string)($_POST['status'] ?? '');
                if (in_array($st, ['new', 'in_progress', 'done'], true)) {
                    Database::update('forms_submissions', ['status' => $st],
                        "tenant_id = ? AND id IN ($place)", array_merge([$tid], $ids));
                    $label = [
                        'new'         => __('forms_status_new', 'New'),
                        'in_progress' => __('forms_status_in_progress', 'In progress'),
                        'done'        => __('forms_status_done', 'Done'),
                    ][$st];
                    $flash = ['type' => 'success', 'msg' => $n === 1
                        ? sprintf(__('forms_status_set_to', 'Status set to %s.'), $label)
                        : sprintf(__('forms_status_set_to_n', '%d set to %s.'), $n, $label)];
                }
            } elseif ($action === 'delete' && Auth::can('forms.manage')) {
                Database::delete('forms_submissions',
                    "tenant_id = ? AND id IN ($place)", array_merge([$tid], $ids));
                AuditLog::record('forms.submission_deleted', implode(',', $ids), ['count' => $n]);
                $flash = ['type' => 'success', 'msg' => $n === 1 ? __('forms_submission_deleted', 'Submission deleted.') : sprintf(__('forms_submissions_deleted_n', '%d deleted.'), $n)];
            }
        }
    }
}

// ── Filters ──────────────────────────────────────────────────
$formId   = (int)($_GET['form_id'] ?? 0);
$showRead = ($_GET['show'] ?? 'all'); // all | unread | read
$status   = (string)($_GET['status'] ?? ''); // '' | new | in_progress | done
$q        = trim((string)($_GET['q'] ?? ''));

$forms = Database::rows(
    "SELECT id, slug, title FROM forms_definitions WHERE tenant_id = ? ORDER BY title",
    [$tid]
);

$where  = ['s.tenant_id = ?'];
$params = [$tid];
if ($formId > 0) {
    $where[] = 's.form_id = ?';
    $params[] = $formId;
}
if ($showRead === 'unread') {
    $where[] = 's.read_at IS NULL';
} elseif ($showRead === 'read') {
    $where[] = 's.read_at IS NOT NULL';
}
if (in_array($status, ['new', 'in_progress', 'done'], true)) {
    $where[] = 's.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    // Name is stored inside data_json, so search both the email column and
    // the raw JSON blob. LIKE on the JSON is coarse but catches names.
    $where[] = '(s.submitter_email LIKE ? OR s.data_json LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
}
$whereSql = implode(' AND ', $where);

// Pagination
$perPage    = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalSubs  = (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions s
       JOIN forms_definitions f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
      WHERE $whereSql", $params);
$totalPages = max(1, (int)ceil($totalSubs / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = Database::rows(
    "SELECT s.*, f.title AS form_title, f.slug AS form_slug
       FROM forms_submissions s
       JOIN forms_definitions f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
      WHERE $whereSql
   ORDER BY s.created_at DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── "Today" summary stats (tenant-wide, independent of filters) ──
$statToday = (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND DATE(created_at) = CURDATE()", [$tid]);
$statWeek = (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND created_at >= (NOW() - INTERVAL 7 DAY)", [$tid]);
$statUnread = (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND read_at IS NULL", [$tid]);
$statOpen = (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND status <> 'done'", [$tid]);

$pageTitle  = __('forms_submissions', 'Submissions');
$currentNav = 'forms-submissions';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms'), 'href' => plugin_url('forms', 'admin/index.php')],
    ['label' => __('forms_submissions', 'Submissions')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('forms_submissions', 'Submissions') ?></h1>
        <p class="page-header-sub"><?= number_format($totalSubs) ?> <?= __('forms_submissions_subtitle', 'entries across your published forms.') ?></p>
    </div>
    <?php if ($formId > 0 && Auth::can('forms.export')): ?>
        <a href="?export=csv&form_id=<?= (int)$formId ?>" class="btn btn-primary"><?= __('export_csv', 'Export CSV') ?></a>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="subs-stats">
    <div class="subs-stat<?= $statToday > 0 ? ' is-alert' : '' ?>">
        <div class="subs-stat-num"><?= number_format($statToday) ?></div>
        <div class="subs-stat-label"><?= __('forms_spam_today', 'Today') ?></div>
    </div>
    <div class="subs-stat">
        <div class="subs-stat-num"><?= number_format($statWeek) ?></div>
        <div class="subs-stat-label"><?= __('forms_this_week', 'This week') ?></div>
    </div>
    <div class="subs-stat<?= $statUnread > 0 ? ' is-alert' : '' ?>">
        <div class="subs-stat-num"><?= number_format($statUnread) ?></div>
        <div class="subs-stat-label"><?= __('forms_unread', 'Unread') ?></div>
    </div>
    <div class="subs-stat">
        <div class="subs-stat-num"><?= number_format($statOpen) ?></div>
        <div class="subs-stat-label"><?= __('forms_open_status', 'Open') ?></div>
    </div>
</div>

<style>
/* ── Submissions accordion list ────────────────────────────── */
.subs-card { padding: 0; overflow: hidden; }
.subs-bulkbar {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    background: var(--accent-soft, var(--surface-2));
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
}
.subs-bulkbar.is-active { display: flex; }
.subs-bulkbar-count { font-size: 13px; font-weight: 600; color: var(--text); }
.subs-bulkbar-spacer { flex: 1; }
.subs-bulkbar-status select { min-height: 30px; padding: 4px 26px 4px 9px; font-size: 12.5px; }

/* Shared column widths — header cells and row cells use the same flex
   bases so everything lines up into clean columns. */
.subs-col-ava    { flex: 0 0 38px; }
.subs-col-who    { flex: 1 1 240px; min-width: 0; }
.subs-col-form   { flex: 1 1 190px; min-width: 0; }
.subs-col-date   { flex: 0 0 150px; }
.subs-col-status { flex: 0 0 78px; }
.subs-col-chev   { flex: 0 0 18px; }

/* List header — select-all + column labels */
.subs-listhead {
    display: flex; align-items: center; gap: 12px;
    padding: 9px 16px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-2);
    font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--muted); font-weight: 600;
}
.subs-head-main { flex: 1; min-width: 0; display: flex; align-items: center; gap: 11px; }
.subs-head-avatar  { flex: 0 0 38px; }
.subs-head-who     { flex: 1 1 240px; min-width: 0; }
.subs-head-form    { flex: 1 1 190px; min-width: 0; }
.subs-head-date    { flex: 0 0 150px; }
.subs-head-status  { flex: 0 0 78px; }
.subs-head-chevron { flex: 0 0 18px; }
.subs-check { flex: none; display: grid; place-items: center; width: 18px; }
.subs-check input { cursor: pointer; margin: 0; }

/* Row */
.subs-row { border-bottom: 1px solid var(--border); }
.subs-row:last-child { border-bottom: 0; }
.subs-row.is-selected { background: var(--accent-soft, var(--surface-sunken)); }
.subs-row-main { display: flex; align-items: center; gap: 12px; padding: 11px 16px; }
.subs-row-main:hover { background: var(--surface-2); }
.subs-row.is-selected .subs-row-main:hover { background: transparent; }

.subs-toggle {
    flex: 1; min-width: 0;
    display: flex; align-items: center; gap: 11px;
    background: none; border: 0; padding: 0; margin: 0;
    text-align: left; cursor: pointer; font: inherit; color: inherit;
    border-radius: 8px;
}
.subs-toggle:focus { outline: none; }
.subs-toggle:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.subs-ava {
    position: relative;
    flex: 0 0 38px; width: 38px; height: 38px;
    border-radius: 999px; overflow: hidden;
    display: grid; place-items: center;
    font-size: 12.5px; font-weight: 700; color: #fff;
    letter-spacing: 0.01em;
}
.subs-ava.is-new  { background: linear-gradient(135deg, #6366F1, #8B5CF6); }
.subs-ava.is-read { background: linear-gradient(135deg, #94A3B8, #64748B); }
.subs-ava img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }

.subs-who-text { flex: 1 1 240px; min-width: 0; line-height: 1.25; }
.subs-name { display: block; font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.subs-email { display: block; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.subs-sub { display: none; font-size: 11.5px; color: var(--subtle); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.subs-toggle:hover .subs-name { color: var(--accent); }

.subs-cell { font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.subs-cell-form { flex: 1 1 190px; min-width: 0; }
.subs-cell-date { flex: 0 0 150px; }

.subs-status { flex: 0 0 78px; display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; }
.subs-status::before { content: ""; width: 7px; height: 7px; border-radius: 999px; flex: none; }
.subs-status.s-new  { color: var(--accent, #4F46E5); }
.subs-status.s-new::before  { background: var(--accent, #6366F1); }
.subs-status.s-prog { color: #B45309; }
.subs-status.s-prog::before { background: #F59E0B; }
.subs-status.s-done { color: #15803D; }
.subs-status.s-done::before { background: #22C55E; }

/* Today summary stat strip */
.subs-stats {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px; margin-bottom: 16px;
}
.subs-stat {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius, 12px); padding: 14px 16px;
}
.subs-stat-num { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1.1; font-family: var(--font-display, inherit); }
.subs-stat-label { font-size: 11.5px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-top: 4px; }
.subs-stat.is-alert .subs-stat-num { color: var(--accent, #4F46E5); }
@media (max-width: 680px) {
    .subs-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .subs-stat-num { font-size: 20px; }
}

.subs-chevron { flex: 0 0 18px; color: var(--subtle); transition: transform .18s ease; }
.subs-row.is-open .subs-chevron { transform: rotate(180deg); }

/* Expandable detail */
.subs-detail { padding: 4px 16px 16px 66px; }
.subs-detail-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px 20px; margin: 0 0 14px;
}
.subs-detail-grid dt { font-size: 10.5px; letter-spacing: 0.05em; text-transform: uppercase; color: var(--muted); font-weight: 600; }
.subs-detail-grid dd { margin: 2px 0 0; font-size: 13px; color: var(--text); word-break: break-word; }
.subs-detail-actions { display: flex; gap: 8px; flex-wrap: wrap; }

/* Tablet: drop the date column, keep form */
@media (max-width: 860px) {
    .subs-head-date, .subs-cell-date { display: none; }
}
/* Mobile: collapse columns into the stacked sub-line under the email */
@media (max-width: 680px) {
    .subs-head-form, .subs-head-date, .subs-head-status { display: none; }
    .subs-cell-form, .subs-cell-date { display: none; }
    .subs-sub { display: block; }
    .subs-status-text { display: none; }
    .subs-detail { padding-left: 16px; }
    /* Keep the status as a small dot chip, pinned right next to the chevron. */
    .subs-status { flex: none; }
    /* Tighter gutters + bigger tap rows on phones. */
    .subs-row-main { padding: 12px; gap: 10px; }
    .subs-listhead { padding: 9px 12px; }
    .subs-toggle { gap: 10px; }
    .subs-ava { flex-basis: 40px; width: 40px; height: 40px; }
    /* Bulk bar: count on its own line, actions fill the row and wrap. */
    .subs-bulkbar { padding: 10px 12px; gap: 6px; }
    .subs-bulkbar-count { flex: 1 1 100%; }
    .subs-bulkbar-spacer { display: none; }
    .subs-bulkbar .btn { flex: 1 1 auto; justify-content: center; }
    .subs-bulkbar-status { flex: 1 1 100%; }
    .subs-bulkbar-status select { width: 100%; }
    /* Stat strip a touch more compact. */
    .subs-stat { padding: 12px 13px; }
}
</style>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="q"><?= __('search', 'Search') ?></label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('forms_search_name_email_ph', 'Name or email…')) ?>">
        </div>
        <div class="field">
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
            <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
            <select id="status" name="status">
                <option value=""            <?= $status === ''            ? 'selected' : '' ?>><?= __('forms_list_any_status', 'Any status') ?></option>
                <option value="new"         <?= $status === 'new'         ? 'selected' : '' ?>><?= __('forms_status_new', 'New') ?></option>
                <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>><?= __('forms_status_in_progress', 'In progress') ?></option>
                <option value="done"        <?= $status === 'done'        ? 'selected' : '' ?>><?= __('forms_status_done', 'Done') ?></option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="show"><?= __('forms_read_state', 'Read state') ?></label>
            <select id="show" name="show">
                <option value="all"    <?= $showRead === 'all'    ? 'selected' : '' ?>><?= __('all', 'All') ?></option>
                <option value="unread" <?= $showRead === 'unread' ? 'selected' : '' ?>><?= __('forms_unread', 'Unread') ?></option>
                <option value="read"   <?= $showRead === 'read'   ? 'selected' : '' ?>><?= __('forms_status_read', 'Read') ?></option>
            </select>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn"><?= __('filter', 'Filter') ?></button>
            <a href="<?= e(plugin_url('forms', 'admin/submissions.php')) ?>" class="btn btn-ghost"><?= __('reset', 'Reset') ?></a>
        </div>
    </form>
</div>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= $q !== '' ? __('forms_no_matching_submissions', 'No matching submissions') : __('forms_no_subs', 'No submissions yet') ?></div>
            <p class="text-sm">
                <?= $q !== ''
                    ? __('forms_list_try_different_search', 'Try a different search or reset the filters.')
                    : __('forms_submissions_auto_desc', "When someone fills out a form, it'll show up here.") ?>
            </p>
        </div>
    </div>
<?php else: ?>

    <form method="post" id="subs-bulk" class="card subs-card"
          onsubmit="return !(event.submitter &amp;&amp; event.submitter.value === 'delete') || confirm('<?= e(__('forms_confirm_delete_submissions', 'Delete the selected submissions? This cannot be undone.')) ?>');">
        <?= csrf_field() ?>

        <div class="subs-bulkbar" id="subs-bulkbar">
            <span class="subs-bulkbar-count"><span id="subs-sel-count">0</span> <?= __('forms_selected', 'selected') ?></span>
            <div class="subs-bulkbar-spacer"></div>
            <label class="subs-bulkbar-status">
                <select name="status" aria-label="<?= e(__('forms_set_status', 'Set status')) ?>">
                    <option value="new"><?= __('forms_status_new', 'New') ?></option>
                    <option value="in_progress"><?= __('forms_status_in_progress', 'In progress') ?></option>
                    <option value="done"><?= __('forms_status_done', 'Done') ?></option>
                </select>
            </label>
            <button type="submit" name="_action" value="set_status"  class="btn btn-sm"><?= __('forms_set_status', 'Set status') ?></button>
            <button type="submit" name="_action" value="mark_read"   class="btn btn-sm"><?= __('forms_mark_read', 'Mark read') ?></button>
            <button type="submit" name="_action" value="mark_unread" class="btn btn-sm"><?= __('forms_mark_unread', 'Mark unread') ?></button>
            <?php if (Auth::can('forms.manage')): ?>
                <button type="submit" name="_action" value="delete" class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
            <?php endif; ?>
        </div>

        <div class="subs-listhead">
            <span class="subs-check">
                <input type="checkbox" id="subs-check-all" aria-label="<?= e(__('forms_select_all_submissions_aria', 'Select all submissions')) ?>">
            </span>
            <span class="subs-head-main">
                <span class="subs-head-avatar" aria-hidden="true"></span>
                <span class="subs-head-who"><?= __('forms_submitter', 'Submitter') ?></span>
                <span class="subs-head-form"><?= __('forms_form', 'Form') ?></span>
                <span class="subs-head-date"><?= __('forms_received', 'Received') ?></span>
                <span class="subs-head-status"><?= __('status', 'Status') ?></span>
                <span class="subs-head-chevron" aria-hidden="true"></span>
            </span>
        </div>

        <?php foreach ($rows as $r):
            $data    = json_decode($r['data_json'] ?? '{}', true) ?: [];
            $email   = (string)($r['submitter_email'] ?? '');
            $name    = forms_submission_name($data, $email);
            $isRead  = !empty($r['read_at']);
            $grav    = forms_gravatar_url($email, 38);

            $st      = $r['status'] ?? 'new';
            $stMeta  = [
                'new'         => [__('forms_status_new', 'New'),         's-new'],
                'in_progress' => [__('forms_status_in_progress', 'In progress'), 's-prog'],
                'done'        => [__('forms_status_done', 'Done'),        's-done'],
            ][$st] ?? [__('forms_status_new', 'New'), 's-new'];

            $detailUrl = plugin_url('forms', 'admin/submission.php') . '?id=' . (int)$r['id'];
            $pdfUrl    = $detailUrl . '&download=pdf';
            $panelId   = 'subs-panel-' . (int)$r['id'];
        ?>
            <div class="subs-row" data-row>
                <div class="subs-row-main">
                    <span class="subs-check">
                        <input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"
                               class="subs-row-check" aria-label="<?= e(sprintf(__('forms_select_submission_aria', 'Select submission from %s'), $name !== '' ? $name : $email)) ?>">
                    </span>
                    <button type="button" class="subs-toggle" aria-expanded="false" aria-controls="<?= $panelId ?>">
                        <span class="subs-ava <?= $isRead ? 'is-read' : '' ?>" aria-hidden="true"
                              style="<?= $isRead ? '' : 'background:' . e(FormsAPI::avatarGradient($email !== '' ? $email : $name)) . ';' ?>"><?= e(forms_initials($name, $email)) ?><?php if ($grav !== ''): ?><img src="<?= e($grav) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                        <span class="subs-who-text">
                            <span class="subs-name"><?= e($name !== '' ? $name : __('forms_no_name', '(no name)')) ?></span>
                            <span class="subs-email"><?= e($email !== '' ? $email : '—') ?></span>
                            <span class="subs-sub"><?= e($r['form_title']) ?> · <?= e(I18n::localDate('j M Y, H:i', strtotime($r['created_at']))) ?></span>
                        </span>
                        <span class="subs-cell subs-cell-form"><?= e($r['form_title']) ?></span>
                        <span class="subs-cell subs-cell-date"><?= e(I18n::localDate('j M Y, H:i', strtotime($r['created_at']))) ?></span>
                        <span class="subs-status <?= $stMeta[1] ?>"><span class="subs-status-text"><?= e($stMeta[0]) ?></span></span>
                        <svg class="subs-chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>
                </div>
                <div class="subs-detail" id="<?= $panelId ?>" hidden>
                    <dl class="subs-detail-grid">
                        <div><dt><?= __('status', 'Status') ?></dt><dd><?= e($stMeta[0]) ?></dd></div>
                        <div><dt><?= __('forms_read_state', 'Read state') ?></dt><dd><?= $isRead ? __('forms_status_read', 'Read') : __('forms_unread', 'Unread') ?></dd></div>
                        <div><dt><?= __('forms_form', 'Form') ?></dt><dd><?= e($r['form_title']) ?></dd></div>
                        <div><dt><?= __('forms_received', 'Received') ?></dt><dd><?= e(I18n::localDate('j M Y, H:i', strtotime($r['created_at']))) ?></dd></div>
                        <div><dt><?= __('forms_reference', 'Reference') ?></dt><dd><code><?= e($r['ref']) ?></code></dd></div>
                        <div><dt><?= __('forms_spam_ip', 'IP') ?></dt><dd><?= e($r['ip'] ?: '—') ?></dd></div>
                        <div><dt><?= __('forms_email_sent', 'Email sent') ?></dt><dd><?= (int)$r['email_sent'] === 1 ? __('yes', 'Yes') : __('no', 'No') ?></dd></div>
                    </dl>
                    <div class="subs-detail-actions">
                        <a href="<?= e($detailUrl) ?>" class="btn btn-sm btn-primary"><?= __('notif_open', 'Open') ?></a>
                        <a href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm"><?= __('forms_pdf_label', 'PDF') ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </form>

    <script>
    (function () {
        var form  = document.getElementById('subs-bulk');
        if (!form) return;
        var all   = document.getElementById('subs-check-all');
        var bar   = document.getElementById('subs-bulkbar');
        var count = document.getElementById('subs-sel-count');
        var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.subs-row-check')); };

        function sync() {
            var checked = boxes().filter(function (b) { return b.checked; });
            count.textContent = checked.length;
            bar.classList.toggle('is-active', checked.length > 0);
            boxes().forEach(function (b) {
                b.closest('[data-row]').classList.toggle('is-selected', b.checked);
            });
            var total = boxes().length;
            all.checked = checked.length > 0 && checked.length === total;
            all.indeterminate = checked.length > 0 && checked.length < total;
        }
        all.addEventListener('change', function () {
            boxes().forEach(function (b) { b.checked = all.checked; });
            sync();
        });
        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('subs-row-check')) sync();
        });

        // Expand / collapse rows
        form.addEventListener('click', function (e) {
            var btn = e.target.closest('.subs-toggle');
            if (!btn) return;
            var row    = btn.closest('.subs-row');
            var panel  = row.querySelector('.subs-detail');
            var isOpen = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            row.classList.toggle('is-open', !isOpen);
            if (panel) panel.hidden = isOpen;
        });

        sync();
    })();
    </script>

    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalSubs, 'per_page' => $perPage, 'label' => __('forms_submissions_pager_label', 'submissions'),
    ]); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
