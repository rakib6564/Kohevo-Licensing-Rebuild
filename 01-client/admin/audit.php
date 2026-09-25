<?php
/**
 * Slate — audit log viewer.
 *
 * Lists `audit_log` rows newest first, with filter by action prefix
 * (e.g. `forms.`, `booking.`) and user. Right-rail summarises the
 * day's activity.
 */
require_once dirname(__DIR__) . '/config.php';

Auth::require();
Auth::requirePerm('audit.view');

$pageTitle  = __('audit_log', 'Audit log');
$currentNav = 'audit';

$tid = current_tenant_id();

// ── Clear the audit log (audit.manage, or super-admin) ────────
// POST → delete, then PRG-redirect so a refresh can't re-submit. The clear
// is itself recorded, so there's always a trace of who purged the log.
// Delegable separately from the page's own audit.view — viewing and
// purging are very different risk levels (Phase 0I RBAC audit).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'clear') {
    if (csrf_verify() && (Auth::can('audit.manage') || Auth::isSuperAdmin())) {
        $scope = ($_POST['scope'] ?? 'all') === 'window' ? 'window' : 'all';
        if ($scope === 'window') {
            $d = max(1, min(90, (int)($_POST['days'] ?? 7)));
            $deleted = Database::delete('audit_log',
                'tenant_id = ? AND created_at < NOW() - INTERVAL ' . (int)$d . ' DAY', [$tid]);
        } else {
            $deleted = Database::delete('audit_log', 'tenant_id = ?', [$tid]);
        }
        AuditLog::record('audit.cleared', $scope, ['deleted' => $deleted]);
        header('Location: ' . SLATE_URL . '/admin/audit.php?cleared=' . (int)$deleted);
        exit;
    }
    header('Location: ' . SLATE_URL . '/admin/audit.php?clear_denied=1');
    exit;
}

$flash = null;
if (isset($_GET['cleared'])) {
    $flash = ['type' => 'success',
              'msg'  => sprintf(__('audit_cleared', 'Cleared %s audit entries.'), number_format((int)$_GET['cleared']))];
} elseif (isset($_GET['clear_denied'])) {
    $flash = ['type' => 'error',
              'msg'  => __('audit_clear_denied', 'You do not have permission to clear the audit log.')];
}

$actionPrefix = trim((string)($_GET['action'] ?? ''));
$userId       = (int)($_GET['user_id'] ?? 0);
$days         = max(1, min(90, (int)($_GET['days'] ?? 7)));

$where  = ['a.tenant_id = ?', 'a.created_at >= NOW() - INTERVAL ' . (int)$days . ' DAY'];
$params = [$tid];
if ($actionPrefix !== '') {
    $where[]  = 'a.action LIKE ?';
    $params[] = $actionPrefix . '%';
}
if ($userId > 0) {
    $where[]  = 'a.user_id = ?';
    $params[] = $userId;
}

// Pagination — the log can hold thousands of rows; show a page at a time.
$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));

$total = (int) Database::value(
    "SELECT COUNT(*) FROM audit_log a WHERE " . implode(' AND ', $where),
    $params
);
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$rows = Database::rows(
    "SELECT a.*, u.name AS user_name, u.email AS user_email
       FROM audit_log a
  LEFT JOIN users u ON u.id = a.user_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY a.created_at DESC
      LIMIT $perPage OFFSET $offset",
    $params
);

// Day summary for aside
$summary = Database::rows(
    "SELECT DATE(created_at) AS day, COUNT(*) AS n
       FROM audit_log
      WHERE tenant_id = ? AND created_at >= NOW() - INTERVAL " . (int)$days . " DAY
   GROUP BY DATE(created_at)
   ORDER BY day DESC",
    [$tid]
);

$users = Database::rows(
    "SELECT id, name FROM users WHERE tenant_id = ? ORDER BY name",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('audit_log', 'Audit log')],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="page-header">
    <div>
        <h1>Audit log</h1>
        <p class="page-header-sub">Everything Kohevo has recorded across the last <?= (int)$days ?> days.</p>
    </div>
</div>

<div class="card tight">
    <form method="get" class="filter-row">
        <div class="field filter-search">
            <label class="field-label" for="action">Action prefix</label>
            <input type="text" id="action" name="action" placeholder="e.g. forms. or booking." value="<?= e($actionPrefix) ?>">
        </div>
        <div class="field">
            <label class="field-label" for="user_id">User</label>
            <select id="user_id" name="user_id">
                <option value="0">Anyone</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= $userId === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="days">Window</label>
            <select id="days" name="days">
                <?php foreach ([1,7,30,90] as $d): ?>
                    <option value="<?= $d ?>" <?= $d === 7 ? 'data-default' : '' ?> <?= $days === $d ? 'selected' : '' ?>>Last <?= $d ?> day<?= $d > 1 ? 's' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions"><button class="btn">Filter</button> <a href="?" class="btn btn-ghost">Reset</a></div>
    </form>
</div>

<?php slate_page_layout('with-aside'); ?>
    <div class="page-main">
        <?php if (!$rows): ?>
            <div class="card"><div class="empty"><div class="empty-title">No audit entries match</div></div></div>
        <?php else: ?>
            <div class="card">
                <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <h2><?= number_format($total) ?> entries</h2>
                    <?php if (Auth::can('audit.manage') || Auth::isSuperAdmin()): ?>
                        <form method="post" style="margin:0;"
                              onsubmit="return confirm(<?= e(json_encode(__('confirm_clear_audit',
                                  "Permanently delete ALL audit entries for this site? This cannot be undone."))) ?>);">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="clear">
                            <input type="hidden" name="scope" value="all">
                            <button type="submit" class="btn btn-ghost" style="color:var(--danger);">
                                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
                                     style="margin-right:5px;vertical-align:-2px;">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg><?= __('clear_log', 'Clear log') ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <ol class="audit-trail">
                    <?php foreach ($rows as $r):
                        $meta = json_decode($r['meta_json'] ?? '{}', true) ?: [];
                        $metaStr = '';
                        if ($meta) {
                            $bits = [];
                            foreach ($meta as $k => $v) {
                                if (is_scalar($v)) $bits[] = $k . '=' . $v;
                            }
                            $metaStr = implode(' · ', $bits);
                        }
                    ?>
                        <li class="audit-trail-item<?= empty($r['user_id']) ? ' is-muted' : '' ?>">
                            <div class="audit-trail-action">
                                <code style="font-family:var(--font-mono);font-size:12.5px;"><?= e($r['action']) ?></code>
                                <?php if (!empty($r['target'])): ?>
                                    <span class="text-muted"> · <?= e($r['target']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="audit-trail-meta">
                                <?= e(I18n::localDate('j M Y, H:i:s', strtotime($r['created_at']))) ?>
                                <?php if ($r['user_name']): ?>
                                    · <?= e($r['user_name']) ?>
                                <?php elseif ($r['user_id']): ?>
                                    · user #<?= (int)$r['user_id'] ?>
                                <?php else: ?>
                                    · system
                                <?php endif; ?>
                            </div>
                            <?php if ($metaStr !== ''): ?>
                                <div class="audit-trail-detail"><?= e($metaStr) ?></div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php slate_pagination($page, $totalPages, $_GET, [
                'total' => $total, 'per_page' => $perPage, 'label' => 'entries',
            ]); ?>
        <?php endif; ?>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title">Volume by day</div>
            <ul class="kv-list">
                <?php foreach ($summary as $s): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= e(I18n::localDate('D j M', strtotime($s['day']))) ?></span>
                        <span class="kv-value kv-mono"><?= (int)$s['n'] ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$summary): ?><li class="kv-row"><span class="kv-label">No activity</span><span class="kv-value">—</span></li><?php endif; ?>
            </ul>
        </div>
        <div class="aside-card">
            <div class="aside-card-title">About the audit log</div>
            <p class="text-sm text-muted" style="margin:0;">
                Records are append-only. Anything calling <code>AuditLog::record()</code> shows up here —
                core, plugins, and customer flows.
            </p>
        </div>
    </aside>
<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
