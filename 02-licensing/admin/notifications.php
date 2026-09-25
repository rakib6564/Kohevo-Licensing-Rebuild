<?php
/**
 * Slate — notifications page.
 *
 * Full-page view of the tenant notification feed (the topbar bell links
 * here instead of opening a dropdown). Read/unread toggle, delete, mark
 * all read, and "open" (marks read, then redirects to the linked target).
 */
require_once dirname(__DIR__) . '/config.php';

Auth::require();

$tid = current_tenant_id();

// ── "Open" a notification: mark it read, then go to its target ───────
if (isset($_GET['go'])) {
    $id = (int)$_GET['go'];
    $n  = Notifications::get($id);
    Notifications::markRead($id, true);
    $target = ($n && !empty($n['url']))
        ? slate_safe_redirect_target($n['url'], SLATE_URL . '/admin/notifications.php')
        : SLATE_URL . '/admin/notifications.php';
    header('Location: ' . $target);
    exit;
}

// ── POST actions (mark read/unread, delete, mark all) ────────────────
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $id     = (int)($_POST['id'] ?? 0);
        if ($action === 'mark_all_read') {
            Notifications::markAllRead();
            $flash = ['type' => 'success', 'msg' => __('notif_all_marked_read', 'All notifications marked as read.')];
        } elseif ($id > 0 && $action === 'mark_read') {
            Notifications::markRead($id, true);
            $flash = ['type' => 'success', 'msg' => __('notif_marked_read', 'Marked as read.')];
        } elseif ($id > 0 && $action === 'mark_unread') {
            Notifications::markRead($id, false);
            $flash = ['type' => 'success', 'msg' => __('notif_marked_unread', 'Marked as unread.')];
        } elseif ($id > 0 && $action === 'delete') {
            Notifications::delete($id);
            $flash = ['type' => 'success', 'msg' => __('notif_deleted', 'Notification deleted.')];
        } elseif ($action === 'bulk_delete') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            foreach ($ids as $bid) Notifications::delete($bid);
            $flash = ['type' => 'success', 'msg' => sprintf(__('notif_bulk_deleted', '%d notification(s) deleted.'), count($ids))];
        } elseif ($action === 'bulk_read') {
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))));
            foreach ($ids as $bid) Notifications::markRead($bid, true);
            $flash = ['type' => 'success', 'msg' => sprintf(__('notif_bulk_marked_read', '%d marked as read.'), count($ids))];
        } elseif ($action === 'delete_all') {
            if (method_exists('Notifications', 'deleteAll')) {
                Notifications::deleteAll();
            } else {
                foreach (Notifications::all(10000, 0) as $row) Notifications::delete((int)$row['id']);
            }
            $flash = ['type' => 'success', 'msg' => __('notif_all_deleted', 'All notifications deleted.')];
        }
    }
}

// ── Data + pagination ────────────────────────────────────────────────
$perPage    = 30;
$total      = Notifications::total();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = max(1, (int)($_GET['page'] ?? 1));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$rows       = Notifications::all($perPage, $offset);
$unread     = Notifications::unreadCount();

$pageTitle  = __('notifications', 'Notifications');
$currentNav = 'notifications';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('notifications', 'Notifications')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('notifications', 'Notifications') ?></h1>
        <p class="page-header-sub">
            <?= $unread > 0
                ? e(sprintf(__('notif_count_with_unread', '%s total · %s unread.'), number_format($total), number_format($unread)))
                : e(sprintf(__('notif_count', '%s total.'), number_format($total))) ?>
        </p>
    </div>
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <?php if ($unread > 0): ?>
            <form method="post" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="mark_all_read">
                <button class="btn" type="submit"><?= e(__('notif_mark_all_read', 'Mark all read')) ?></button>
            </form>
        <?php endif; ?>
        <?php if (!empty($rows)): ?>
            <form method="post" style="margin:0" onsubmit="return confirm('<?= e(__('notif_confirm_delete_all', 'Delete ALL notifications? This cannot be undone.')) ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="delete_all">
                <button class="btn btn-danger" type="submit"><?= e(__('notif_clear_all', 'Clear all')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($rows)): ?>
<div id="notif-bulkbar" hidden
     style="display:flex;align-items:center;gap:10px;margin:0 0 14px;padding:10px 14px;background:var(--surface-2);border:1px solid var(--line);border-radius:var(--radius-sm);">
    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
        <input type="checkbox" id="notif-all" style="width:17px;height:17px;accent-color:var(--accent);">
        <span id="notif-selcount"><?= e(__('notif_select_all', 'Select all')) ?></span>
    </label>
    <span style="flex:1;"></span>
    <button type="button" id="notif-bulk-read" class="btn btn-sm"><?= e(__('notif_mark_read', 'Mark read')) ?></button>
    <button type="button" id="notif-bulk-del" class="btn btn-sm btn-danger"><?= e(__('notif_delete_selected', 'Delete selected')) ?></button>
</div>
<form method="post" id="notif-bulk-form" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" id="notif-bulk-action" value="">
    <span id="notif-bulk-ids"></span>
</form>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if (empty($rows)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= e(__('notif_all_caught_up', 'You\'re all caught up')) ?></div>
            <p class="text-sm"><?= e(__('notif_will_show_here', 'New notifications will show up here.')) ?></p>
        </div>
    </div>
<?php else: ?>

    <?php
    // Notification titles are stored as "<static label> · <ref>" (e.g.
    // "New booking · BK-1234"), built once at creation time. The translation
    // system (multilang-translate) works by matching exact rendered text —
    // it can't match a string that ends in a different order reference each
    // time, so left as one blob these titles could never be translated. Split
    // on the first " · " so the static label renders as its own text node,
    // separate from the always-different reference, the same as the
    // "When"/"Link" detail labels below already do.
    if (!function_exists('slate_notif_title_html')) {
        function slate_notif_title_html(string $title): string {
            $parts = explode(' · ', $title, 2);
            if (count($parts) !== 2) return e($title);
            // The <span> boundary (not just the " · " text) is what actually
            // makes the two halves separate text nodes to the tokenizer that
            // both harvests and replaces translatable text — string
            // concatenation alone still reads as one continuous run.
            return '<span>' . e($parts[0]) . '</span> · <span>' . e($parts[1]) . '</span>';
        }
    }
    ?>
    <div class="data-list" data-single-open>
        <?php foreach ($rows as $n):
            $nUrl  = (string)($n['url'] ?? '');
            $time  = function_exists('slate_admin_time_ago')
                   ? slate_admin_time_ago((string)($n['created_at'] ?? ''))
                   : I18n::localDate('j M Y, H:i', strtotime((string)($n['created_at'] ?? 'now')));

            $actions = '<label class="notif-pick" title="Select" style="display:inline-flex;align-items:center;margin-right:10px;cursor:pointer;">'
                     . '<input type="checkbox" class="notif-cb" value="' . (int)$n['id'] . '" style="width:17px;height:17px;cursor:pointer;accent-color:var(--accent);"></label>';
            if ($nUrl !== '') {
                $actions .= '<a href="' . e(SLATE_URL . '/admin/notifications.php?go=' . (int)$n['id'])
                          . '" class="btn btn-sm btn-primary">' . e(__('notif_open', 'Open')) . '</a> ';
            }
            $actions .= '<form method="post" style="display:inline; margin:0;">' . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$n['id'] . '">';
            if ((int)$n['is_read'] === 1) {
                $actions .= '<button name="_action" value="mark_unread" class="btn btn-sm">' . e(__('notif_mark_unread', 'Mark unread')) . '</button>';
            } else {
                $actions .= '<button name="_action" value="mark_read" class="btn btn-sm">' . e(__('notif_mark_read', 'Mark read')) . '</button>';
            }
            $actions .= '</form> ';
            $actions .= '<form method="post" style="display:inline; margin:0;" onsubmit="return confirm(\'' . e(__('notif_confirm_delete_one', 'Delete this notification?')) . '\')">'
                      . csrf_field()
                      . '<input type="hidden" name="id" value="' . (int)$n['id'] . '">'
                      . '<button name="_action" value="delete" class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button>'
                      . '</form>';

            $detail = [
                __('notif_when', 'When') => $time,
            ];
            if ($nUrl !== '') {
                $detail['Link'] = ['label' => __('notif_link', 'Link'), 'html' => '<code>' . e($nUrl) . '</code>'];
            }

            slate_data_row([
                'avatar'       => mb_substr((string)($n['title'] ?: 'N'), 0, 1),
                'avatar_color' => (int)$n['is_read'] === 1 ? 'muted' : 'info',
                'title_html'   => slate_notif_title_html((string)$n['title']),
                'meta'         => trim(((string)($n['body'] ?? '')) . ($n['body'] ? ' · ' : '') . $time),
                'badge'        => (int)$n['is_read'] === 1 ? [__('notif_read', 'Read'), 'inactive'] : [__('notif_new', 'New'), 'warning'],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>

    <?php slate_data_list_script(); ?>
    <?php slate_pagination($page, $totalPages, $_GET, [
        'total' => $total, 'per_page' => $perPage, 'label' => __('notif_pager_label', 'notifications'),
    ]); ?>
<?php endif; ?>

<script>
(function(){
    var i18n = <?= json_encode([
        'selectAll'        => __('notif_select_all', 'Select all'),
        'nSelected'        => __('notif_n_selected', '%d selected'),
        'confirmDeleteN'   => __('notif_confirm_delete_n', 'Delete %d notification(s)?'),
        'selectAtLeastOne' => __('notif_select_at_least_one', 'Select at least one notification.'),
    ], JSON_UNESCAPED_UNICODE) ?>;
    var bar = document.getElementById('notif-bulkbar');
    if (!bar) return;
    var all = document.getElementById('notif-all');
    var cnt = document.getElementById('notif-selcount');
    var form = document.getElementById('notif-bulk-form');
    var actEl = document.getElementById('notif-bulk-action');
    var idsBox = document.getElementById('notif-bulk-ids');
    function cbs(){ return Array.prototype.slice.call(document.querySelectorAll('.notif-cb')); }
    function selected(){ return cbs().filter(function(c){ return c.checked; }); }
    function refresh(){
        var s = selected().length, total = cbs().length;
        bar.hidden = false;
        cnt.textContent = s ? i18n.nSelected.replace('%d', s) : i18n.selectAll;
        all.checked = s > 0 && s === total;
        all.indeterminate = s > 0 && s < total;
    }
    document.addEventListener('change', function(e){
        if (e.target && e.target.classList && e.target.classList.contains('notif-cb')) refresh();
    });
    all.addEventListener('change', function(){ cbs().forEach(function(c){ c.checked = all.checked; }); refresh(); });
    function submitBulk(action){
        var ids = selected().map(function(c){ return c.value; });
        if (!ids.length) { alert(i18n.selectAtLeastOne); return; }
        if (action === 'bulk_delete' && !confirm(i18n.confirmDeleteN.replace('%d', ids.length))) return;
        actEl.value = action;
        idsBox.innerHTML = '';
        ids.forEach(function(id){ var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; idsBox.appendChild(i); });
        form.submit();
    }
    document.getElementById('notif-bulk-del').addEventListener('click', function(){ submitBulk('bulk_delete'); });
    document.getElementById('notif-bulk-read').addEventListener('click', function(){ submitBulk('bulk_read'); });
    refresh();
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
