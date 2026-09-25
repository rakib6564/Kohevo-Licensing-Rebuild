<?php
/**
 * Forms — contacts list.
 *
 * A CRM-style roll-up of unique submitters (by email), built from
 * submissions into the forms_contacts table. Kept separate from core
 * `customers` so form leads don't pollute auth-bearing accounts.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';

Auth::require();
Auth::requirePerm('forms.view');
FormsAPI::ensureSchema();

$tid = current_tenant_id();

// Seed the table from existing submissions on first visit (cheap no-op after).
FormsAPI::backfillContacts($tid);

// ── Bulk POST handlers (delete) ──────────────────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('intval', $ids), fn($x) => $x > 0));
        if (!empty($ids)) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $n     = count($ids);
            if ($action === 'delete' && Auth::can('forms.manage')) {
                Database::delete('forms_contacts',
                    "tenant_id = ? AND id IN ($place)", array_merge([$tid], $ids));
                AuditLog::record('forms.contact_deleted', implode(',', $ids), ['count' => $n]);
                $flash = ['type' => 'success', 'msg' => $n === 1 ? __('forms_contact_deleted', 'Contact deleted.') : sprintf(__('forms_contacts_deleted_n', '%d contacts deleted.'), $n)];
            }
        }
    }
}

// ── CSV export (honours the current search OR selected ids) ──
$q = trim((string)($_GET['q'] ?? ''));

$where  = ['tenant_id = ?'];
$params = [$tid];
if ($q !== '') {
    $where[] = '(email LIKE ? OR name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
$whereSql = implode(' AND ', $where);

if (($_GET['export'] ?? '') === 'csv') {
    Auth::requirePerm('forms.export');
    // When the bulk bar's "Export selected" submits via GET, scope to those ids.
    $selIds = $_GET['ids'] ?? [];
    if (!is_array($selIds)) $selIds = [];
    $selIds = array_values(array_filter(array_map('intval', $selIds), fn($x) => $x > 0));
    if (!empty($selIds)) {
        $place = implode(',', array_fill(0, count($selIds), '?'));
        $rows  = Database::rows(
            "SELECT name, email, phone, submissions_count, first_seen_at, last_seen_at
               FROM forms_contacts WHERE tenant_id = ? AND id IN ($place)
               ORDER BY last_seen_at DESC",
            array_merge([$tid], $selIds));
    } else {
        $rows = Database::rows(
            "SELECT name, email, phone, submissions_count, first_seen_at, last_seen_at
               FROM forms_contacts WHERE $whereSql ORDER BY last_seen_at DESC", $params);
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="forms-contacts-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $csvSafe = static function ($v): string {
        $s = (string)$v;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) return "'" . $s;
        return $s;
    };
    fputcsv($out, ['name', 'email', 'phone', 'submissions', 'first_seen', 'last_seen']);
    foreach ($rows as $r) {
        fputcsv($out, array_map($csvSafe, [
            $r['name'], $r['email'], $r['phone'],
            $r['submissions_count'], $r['first_seen_at'], $r['last_seen_at'],
        ]));
    }
    fclose($out);
    exit;
}

// ── List + pagination ────────────────────────────────────────
$perPage    = 30;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalCts   = (int) Database::value("SELECT COUNT(*) FROM forms_contacts WHERE $whereSql", $params);
$totalPages = max(1, (int)ceil($totalCts / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$contacts = Database::rows(
    "SELECT * FROM forms_contacts WHERE $whereSql
      ORDER BY last_seen_at DESC, id DESC
      LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// ── Summary stats (tenant-wide, independent of search) ───────
$statContacts  = (int) Database::value("SELECT COUNT(*) FROM forms_contacts WHERE tenant_id = ?", [$tid]);
$statNew7      = (int) Database::value("SELECT COUNT(*) FROM forms_contacts WHERE tenant_id = ? AND first_seen_at >= (NOW() - INTERVAL 7 DAY)", [$tid]);
$statReturning = (int) Database::value("SELECT COUNT(*) FROM forms_contacts WHERE tenant_id = ? AND submissions_count > 1", [$tid]);
$statWithPhone = (int) Database::value("SELECT COUNT(*) FROM forms_contacts WHERE tenant_id = ? AND phone IS NOT NULL AND phone <> ''", [$tid]);

$pageTitle  = __('forms_contacts', 'Contacts');
$currentNav = 'forms-contacts';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms'), 'href' => plugin_url('forms', 'admin/index.php')],
    ['label' => __('forms_contacts', 'Contacts')],
]); ?>

<style>
.cts-card { padding: 0; overflow: hidden; }
.cts-bulkbar {
    display: none; align-items: center; gap: 8px;
    padding: 10px 16px; background: var(--accent-soft, var(--surface-2));
    border-bottom: 1px solid var(--border); flex-wrap: wrap;
}
.cts-bulkbar.is-active { display: flex; }
.cts-bulkbar-count { font-size: 13px; font-weight: 600; color: var(--text); }
.cts-bulkbar-spacer { flex: 1; }
.cts-rowwrap {
    display: flex; align-items: center; gap: 13px;
    padding: 12px 16px; border-bottom: 1px solid var(--border);
    transition: background .1s ease;
}
.cts-rowwrap:last-child { border-bottom: 0; }
.cts-rowwrap:hover { background: var(--surface-2); }
.cts-rowwrap.is-selected { background: var(--accent-soft, var(--surface-sunken)); }
.cts-rowwrap.is-selected:hover { background: var(--accent-soft, var(--surface-sunken)); }
.cts-check { flex: none; display: grid; place-items: center; width: 18px; }
.cts-check input { cursor: pointer; margin: 0; }
.cts-link {
    flex: 1; display: flex; align-items: center; gap: 13px;
    text-decoration: none; color: inherit; min-width: 0;
}
.cts-link:hover { text-decoration: none; }
.cts-ava {
    position: relative; flex: 0 0 40px; width: 40px; height: 40px;
    border-radius: 999px; overflow: hidden; display: grid; place-items: center;
    font-size: 13px; font-weight: 700; color: #fff;
    background: linear-gradient(135deg, #6366F1, #8B5CF6);
}
.cts-ava img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; display: block; }
.cts-who { flex: 1 1 240px; min-width: 0; line-height: 1.3; }
.cts-name { display: block; font-weight: 600; font-size: 13.5px; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cts-link:hover .cts-name { color: var(--accent); }
.cts-email { display: block; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.cts-sub { display: none; font-size: 11.5px; color: var(--subtle); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.cts-phone { flex: 0 0 150px; font-size: 12.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cts-count { flex: 0 0 120px; }
.cts-count-badge {
    display: inline-block; font-size: 12px; font-weight: 600; color: var(--text-2);
    background: var(--surface-2); border: 1px solid var(--border);
    border-radius: 999px; padding: 2px 10px;
}
.cts-seen { flex: 0 0 130px; font-size: 12px; color: var(--muted); white-space: nowrap; }
.cts-chev { flex: 0 0 18px; color: var(--subtle); }
.cts-head {
    display: flex; align-items: center; gap: 13px;
    padding: 9px 16px; border-bottom: 1px solid var(--border); background: var(--surface-2);
    font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; color: var(--muted); font-weight: 600;
}
.cts-head-check { flex: none; width: 18px; }
.cts-head-ava { flex: 0 0 40px; }
.cts-head-who { flex: 1 1 240px; }
.cts-head-phone { flex: 0 0 150px; }
.cts-head-count { flex: 0 0 120px; }
.cts-head-seen { flex: 0 0 130px; }
.cts-head-chev { flex: 0 0 18px; }
@media (max-width: 860px) { .cts-phone, .cts-head-phone { display: none; } }
@media (max-width: 680px) {
    .cts-count, .cts-head-count, .cts-seen, .cts-head-seen { display: none; }
    .cts-sub { display: block; }
    .cts-rowwrap { padding: 12px; gap: 11px; }
    .cts-stat { padding: 12px 13px; }
    .cts-bulkbar { padding: 10px 12px; gap: 6px; }
    .cts-bulkbar-count { flex: 1 1 100%; }
    .cts-bulkbar-spacer { display: none; }
    .cts-bulkbar .btn { flex: 1 1 auto; justify-content: center; }
}
.cts-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
.cts-stat { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius, 12px); padding: 14px 16px; }
.cts-stat-num { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1.1; font-family: var(--font-display, inherit); }
.cts-stat-label { font-size: 11.5px; letter-spacing: 0.04em; text-transform: uppercase; color: var(--muted); font-weight: 600; margin-top: 4px; }
.cts-stat.is-alert .cts-stat-num { color: var(--accent, #4F46E5); }
@media (max-width: 680px) { .cts-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; } .cts-stat-num { font-size: 20px; } }
</style>

<div class="page-header">
    <div>
        <h1><?= __('forms_contacts', 'Contacts') ?></h1>
        <p class="page-header-sub"><?= number_format($totalCts) ?> <?= __('forms_contacts_subtitle', "people who've submitted a form.") ?></p>
    </div>
    <?php if (Auth::can('forms.export') && $totalCts > 0): ?>
        <a href="?export=csv<?= $q !== '' ? '&q=' . urlencode($q) : '' ?>" class="btn btn-primary"><?= __('export_csv', 'Export CSV') ?></a>
    <?php endif; ?>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($statContacts > 0): ?>
<div class="cts-stats">
    <div class="cts-stat">
        <div class="cts-stat-num"><?= number_format($statContacts) ?></div>
        <div class="cts-stat-label"><?= __('forms_contacts', 'Contacts') ?></div>
    </div>
    <div class="cts-stat<?= $statNew7 > 0 ? ' is-alert' : '' ?>">
        <div class="cts-stat-num"><?= number_format($statNew7) ?></div>
        <div class="cts-stat-label"><?= __('forms_contacts_new_week', 'New this week') ?></div>
    </div>
    <div class="cts-stat">
        <div class="cts-stat-num"><?= number_format($statReturning) ?></div>
        <div class="cts-stat-label"><?= __('forms_contacts_returning', 'Returning') ?></div>
    </div>
    <div class="cts-stat">
        <div class="cts-stat-num"><?= number_format($statWithPhone) ?></div>
        <div class="cts-stat-label"><?= __('forms_contacts_with_phone', 'With phone') ?></div>
    </div>
</div>
<?php endif; ?>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="q"><?= __('search', 'Search') ?></label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('forms_search_name_email_ph', 'Name or email…')) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn"><?= __('search', 'Search') ?></button>
            <a href="<?= e(plugin_url('forms', 'admin/contacts.php')) ?>" class="btn btn-ghost"><?= __('reset', 'Reset') ?></a>
        </div>
    </form>
</div>

<?php if (empty($contacts)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= $q !== '' ? __('forms_contacts_no_match', 'No matching contacts') : __('forms_contacts_none_yet', 'No contacts yet') ?></div>
            <p class="text-sm">
                <?= $q !== ''
                    ? __('forms_contacts_try_diff', 'Try a different search or reset.')
                    : __('forms_contacts_auto_desc', 'Contacts are built automatically from form submissions.') ?>
            </p>
        </div>
    </div>
<?php else: ?>
    <form method="post" id="cts-bulk" class="card cts-card"
          onsubmit="return !(event.submitter &amp;&amp; event.submitter.value === 'delete') || confirm('<?= e(__('forms_contacts_confirm_delete', 'Delete the selected contacts? This only removes them from the contacts list — their submissions are kept.')) ?>');">
        <?= csrf_field() ?>

        <div class="cts-bulkbar" id="cts-bulkbar">
            <span class="cts-bulkbar-count"><span id="cts-sel-count">0</span> <?= __('forms_selected', 'selected') ?></span>
            <div class="cts-bulkbar-spacer"></div>
            <?php if (Auth::can('forms.export')): ?>
                <button type="submit" formmethod="get" formaction="<?= e(plugin_url('forms', 'admin/contacts.php')) ?>"
                        name="export" value="csv" class="btn btn-sm"><?= __('forms_export_selected', 'Export selected') ?></button>
            <?php endif; ?>
            <?php if (Auth::can('forms.manage')): ?>
                <button type="submit" name="_action" value="delete" class="btn btn-sm btn-danger"><?= __('delete', 'Delete') ?></button>
            <?php endif; ?>
        </div>

        <div class="cts-head">
            <span class="cts-head-check">
                <input type="checkbox" id="cts-check-all" aria-label="<?= e(__('forms_contacts_select_all_aria', 'Select all contacts')) ?>">
            </span>
            <span class="cts-head-ava"></span>
            <span class="cts-head-who"><?= __('forms_contacts_col_contact', 'Contact') ?></span>
            <span class="cts-head-phone"><?= __('forms_contacts_col_phone', 'Phone') ?></span>
            <span class="cts-head-count"><?= __('forms_submissions', 'Submissions') ?></span>
            <span class="cts-head-seen"><?= __('forms_contacts_col_last_seen', 'Last seen') ?></span>
            <span class="cts-head-chev"></span>
        </div>
        <?php foreach ($contacts as $c):
            $email = (string)$c['email'];
            $name  = (string)($c['name'] ?? '');
            if ($name === '') $name = FormsAPI::deriveContactName([], $email);
            $grav  = FormsAPI::gravatarUrl($email, 40);
            $bg    = FormsAPI::avatarGradient($email !== '' ? $email : $name);
            // Their submissions: the inbox search matches submitter_email.
            $subsUrl = plugin_url('forms', 'admin/submissions.php') . '?q=' . urlencode($email);
        ?>
            <div class="cts-rowwrap" data-row>
                <span class="cts-check">
                    <input type="checkbox" name="ids[]" value="<?= (int)$c['id'] ?>"
                           class="cts-row-check" aria-label="<?= e(sprintf(__('forms_select_x_aria', 'Select %s'), $name !== '' ? $name : $email)) ?>">
                </span>
                <a href="<?= e($subsUrl) ?>" class="cts-link">
                    <span class="cts-ava" aria-hidden="true" style="background:<?= e($bg) ?>;"><?= e(FormsAPI::initials($name, $email)) ?><?php if ($grav !== ''): ?><img src="<?= e($grav) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                    <span class="cts-who">
                        <span class="cts-name"><?= e($name !== '' ? $name : __('forms_no_name', '(no name)')) ?></span>
                        <span class="cts-email"><?= e($email) ?></span>
                        <span class="cts-sub"><?= number_format((int)$c['submissions_count']) ?> <?= __('forms_word_submission', 'submission') ?><?= (int)$c['submissions_count'] === 1 ? '' : 's' ?><?= $c['last_seen_at'] ? ' · ' . e(I18n::localDate('j M Y', strtotime($c['last_seen_at']))) : '' ?></span>
                    </span>
                    <span class="cts-phone"><?= e($c['phone'] ?: '—') ?></span>
                    <span class="cts-count">
                        <span class="cts-count-badge"><?= number_format((int)$c['submissions_count']) ?> <?= __('forms_word_submission', 'submission') ?><?= (int)$c['submissions_count'] === 1 ? '' : 's' ?></span>
                    </span>
                    <span class="cts-seen"><?= $c['last_seen_at'] ? e(I18n::localDate('j M Y', strtotime($c['last_seen_at']))) : '—' ?></span>
                    <svg class="cts-chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                </a>
            </div>
        <?php endforeach; ?>
    </form>

    <script>
    (function () {
        var form  = document.getElementById('cts-bulk'); if (!form) return;
        var all   = document.getElementById('cts-check-all');
        var bar   = document.getElementById('cts-bulkbar');
        var count = document.getElementById('cts-sel-count');
        var boxes = function () { return Array.prototype.slice.call(form.querySelectorAll('.cts-row-check')); };

        function sync() {
            var checked = boxes().filter(function (b) { return b.checked; });
            count.textContent = checked.length;
            bar.classList.toggle('is-active', checked.length > 0);
            boxes().forEach(function (b) {
                b.closest('[data-row]').classList.toggle('is-selected', b.checked);
            });
            var total = boxes().length;
            all.checked       = checked.length > 0 && checked.length === total;
            all.indeterminate = checked.length > 0 && checked.length < total;
        }
        all.addEventListener('change', function () {
            boxes().forEach(function (b) { b.checked = all.checked; });
            sync();
        });
        form.addEventListener('change', function (e) {
            if (e.target.classList.contains('cts-row-check')) sync();
        });
        sync();
    })();
    </script>

    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $totalCts, 'per_page' => $perPage, 'label' => __('forms_contacts_pager_label', 'contacts'),
    ]); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
