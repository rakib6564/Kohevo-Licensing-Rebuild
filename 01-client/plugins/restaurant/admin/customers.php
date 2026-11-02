<?php
/**
 * Restaurant — customers: records with their history, import and export.
 *
 * The list shows each customer's orders, total spend (net of refunds and
 * comps), average spend and last visit, merged with any history imported
 * from another system (RestaurantCustomers documents the rules), sortable
 * by name, spend or last visit. A row opens to their details and latest
 * orders, each linking to the order page. Deleting a customer who has
 * orders archives them instead, so the orders keep their guest; archived
 * customers have their own tab and can be restored.
 *
 * Import takes a CSV (Name, Member ID, Email, Phone, Avg. Spend, Total
 * Spend, Last Visit, First Seen), shows a dry run (creates, updates and
 * every bad row by line) and writes only on confirm. Export writes the
 * same columns.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/customers.php';
require_once SLATE_ROOT . '/includes/record_editor.php';

Auth::require();
Auth::requirePerm('restaurant.manage_customers');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = __('restaurant_customers', 'Customers');
$currentNav = 'restaurant-customers';
$tid  = current_tenant_id();
$self = plugin_url('restaurant', 'admin/customers.php');

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="restaurant-customers-' . substr(slate_db_now(), 0, 10) . '.csv"');
    header('Cache-Control: no-store');
    AuditLog::record('restaurant.customers_exported', '');
    echo "\xEF\xBB\xBF" . RestaurantCustomers::exportCsv();
    exit;
}

$flash   = null;
$preview = null;      // dry-run result while an import waits for confirmation
$editId  = (int)($_GET['edit'] ?? 0);
$isNew   = !empty($_GET['new']);
$q       = trim((string)($_GET['q'] ?? ''));
$tab     = ($_GET['tab'] ?? '') === 'archived' ? 'archived' : 'active';
$sort    = in_array($_GET['sort'] ?? '', ['name', 'spend', 'visit'], true) ? (string)$_GET['sort'] : 'name';
$dir     = ($_GET['dir'] ?? '') === 'desc' || (($_GET['dir'] ?? '') === '' && $sort !== 'name') ? 'desc' : 'asc';
$page    = max(1, (int)($_GET['page'] ?? 1));
$per     = 50;

$editing = null;
if ($editId > 0) {
    $editing = RestaurantCustomers::get($editId);
    if (!$editing) { http_response_code(404); $editId = 0; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')];
    } else {
        $action = (string)($_POST['_action'] ?? '');
        if ($action === 'save') {
            $id    = (int)($_POST['id'] ?? 0);
            $name  = preg_replace('/\s+/u', ' ', trim((string)($_POST['name'] ?? '')));
            $phone = mb_substr(trim((string)($_POST['phone'] ?? '')), 0, 40);
            $email = mb_substr(trim((string)($_POST['email'] ?? '')), 0, 200);
            $norm  = RestaurantCustomers::normalizePhone($phone);
            $clash = null;
            if ($norm !== '') {
                foreach (Database::rows("SELECT id, name, email, phone FROM restaurant_customers WHERE tenant_id = ? AND id <> ? AND phone IS NOT NULL", [$tid, $id]) as $o) {
                    if (RestaurantCustomers::normalizePhone($o['phone']) === $norm) { $clash = $o; break; }
                }
            }
            if ($name === '' && $phone === '' && $email === '') {
                $flash = ['type' => 'error', 'msg' => __('restaurant_cust_err_empty', 'Enter a name, phone or email.')];
            } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $flash = ['type' => 'error', 'msg' => __('restaurant_cust_err_email', 'That email address is not valid.')];
            } elseif ($clash) {
                $flash = ['type' => 'error', 'msg' => sprintf(__('restaurant_cust_err_phone', 'That phone number belongs to %s already.'), RestaurantCustomers::displayName($clash))];
            } else {
                $row = [
                    'tenant_id'  => $tid,
                    'name'       => mb_substr($name, 0, 160),
                    'phone'      => $phone !== '' ? $phone : null,
                    'email'      => $email !== '' ? $email : null,
                    'member_ref' => mb_substr(trim((string)($_POST['member_ref'] ?? '')), 0, 80) ?: null,
                    'notes'      => trim((string)($_POST['notes'] ?? '')) ?: null,
                ];
                if ($id > 0) Database::update('restaurant_customers', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                else         $id = Database::insert('restaurant_customers', $row);
                AuditLog::record('restaurant.customer_saved', (string)$id);
                header('Location: ' . $self . '?saved=1'); exit;
            }
            $editing = $editing ?: ($id > 0 ? RestaurantCustomers::get($id) : null);
            $isNew   = !$editing;
        } elseif ($action === 'delete') {
            $res = RestaurantCustomers::delete((int)($_POST['id'] ?? 0));
            header('Location: ' . $self . '?done=' . $res); exit;
        } elseif ($action === 'restore') {
            RestaurantCustomers::restore((int)($_POST['id'] ?? 0));
            header('Location: ' . $self . '?done=restored'); exit;
        } elseif ($action === 'import_preview') {
            $file = $_FILES['csv'] ?? null;
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
                $flash = ['type' => 'error', 'msg' => __('restaurant_imp_err_upload', 'Choose a CSV file to upload.')];
            } elseif ((int)$file['size'] > 5 * 1024 * 1024) {
                $flash = ['type' => 'error', 'msg' => __('restaurant_imp_err_size', 'That file is larger than 5 MB.')];
            } else {
                $csv = (string) file_get_contents((string)$file['tmp_name']);
                if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
                $preview = RestaurantCustomers::plan($csv);
                if ($preview['fatal'] !== null) {
                    $flash = ['type' => 'error', 'msg' => $preview['fatal']];
                    $preview = null;
                } else {
                    $_SESSION['restaurant_customer_import'] = ['token' => bin2hex(random_bytes(12)), 'csv' => $csv,
                        'name' => mb_substr((string)$file['name'], 0, 120), 'tenant' => $tid];
                    $preview['token'] = $_SESSION['restaurant_customer_import']['token'];
                    $preview['file']  = $_SESSION['restaurant_customer_import']['name'];
                }
            }
        } elseif ($action === 'import_confirm') {
            $held = $_SESSION['restaurant_customer_import'] ?? null;
            if (!$held || !hash_equals((string)$held['token'], (string)($_POST['token'] ?? '')) || (int)$held['tenant'] !== $tid) {
                $flash = ['type' => 'error', 'msg' => __('restaurant_imp_err_expired', 'That import has expired. Upload the file again.')];
            } else {
                unset($_SESSION['restaurant_customer_import']);
                try {
                    $res = RestaurantCustomers::import((string)$held['csv']);
                    $_SESSION['restaurant_customer_import_done'] = sprintf(
                        __('restaurant_imp_done', 'Imported %1$d customers: %2$d new, %3$d updated, %4$d rows left out.'),
                        $res['written'], $res['creates'], $res['updates'], count($res['errors']));
                    header('Location: ' . $self . '?done=imported'); exit;
                } catch (\Throwable $e) {
                    slate_log('Restaurant customer import failed: ' . $e->getMessage(), 'error');
                    $flash = ['type' => 'error', 'msg' => __('restaurant_imp_err_failed', 'The import failed and nothing was saved. Check the file and try again.')];
                }
            }
        } elseif ($action === 'import_cancel') {
            unset($_SESSION['restaurant_customer_import']);
            header('Location: ' . $self); exit;
        }
    }
}

$done = (string)($_GET['done'] ?? '');
if (!$flash) {
    $flash = match (true) {
        !empty($_GET['saved'])  => ['type' => 'success', 'msg' => __('restaurant_cust_saved', 'Customer saved.')],
        $done === 'archived'    => ['type' => 'success', 'msg' => __('restaurant_cust_archived', 'This customer has orders, so they were archived: their orders keep their name, and they no longer show in the POS guest lookup. Find them under Archived.')],
        $done === 'deleted'     => ['type' => 'success', 'msg' => __('restaurant_cust_deleted', 'Customer deleted.')],
        $done === 'restored'    => ['type' => 'success', 'msg' => __('restaurant_cust_restored', 'Customer restored.')],
        $done === 'imported'    => ['type' => 'success', 'msg' => (string)($_SESSION['restaurant_customer_import_done'] ?? __('restaurant_imp_done_short', 'Import finished.'))],
        default                 => null,
    };
    if ($done === 'imported') unset($_SESSION['restaurant_customer_import_done']);
}

$money = fn($c) => RestaurantAPI::money((int)$c);
$date  = fn($d) => $d ? date('M j, Y', strtotime((string)$d)) : '—';
$TYPES = ['dine_in' => __('restaurant_rcpt_dine_in', 'Dine in'), 'takeout' => __('restaurant_rcpt_takeout', 'Takeout'), 'delivery' => __('restaurant_rcpt_delivery', 'Delivery')];
$ST    = ['open' => __('restaurant_pos_st_open', 'Open'), 'sent' => __('restaurant_pos_st_sent', 'Sent'), 'paid' => __('restaurant_pos_st_paid', 'Paid'),
          'closed' => __('restaurant_pos_st_closed', 'Closed'), 'voided' => __('restaurant_pos_st_voided', 'Voided')];

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_customers', 'Customers')],
]);
if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif;

// ── Import dry run ─────────────────────────────────────────────────────────
if ($preview): ?>
    <div class="page-header"><div><h1><?= e(__('restaurant_imp_title', 'Check the import')) ?></h1>
        <p class="page-header-sub"><?= e(sprintf(__('restaurant_imp_sub', '%s — nothing has been saved yet.'), $preview['file'])) ?></p></div></div>
    <div class="rc-imp-stats">
        <div><span><?= e(__('restaurant_imp_rows', 'Rows to import')) ?></span><b><?= count($preview['rows']) ?></b></div>
        <div><span><?= e(__('restaurant_imp_creates', 'New customers')) ?></span><b><?= (int)$preview['creates'] ?></b></div>
        <div><span><?= e(__('restaurant_imp_updates', 'Updates')) ?></span><b><?= (int)$preview['updates'] ?></b></div>
        <div class="<?= $preview['errors'] ? 'is-bad' : '' ?>"><span><?= e(__('restaurant_imp_errors', 'Rows left out')) ?></span><b><?= count($preview['errors']) ?></b></div>
    </div>
    <?php if ($preview['errors']): ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:15px;"><?= e(__('restaurant_imp_errors_title', 'These rows will be left out')) ?></h2>
        <p class="text-sm" style="color:var(--muted)"><?= e(__('restaurant_imp_errors_hint', 'Fix them in the file and import it again later; importing twice updates instead of duplicating. The good rows import either way.')) ?></p>
        <div class="data-list">
            <?php foreach ($preview['errors'] as [$line, $why]) slate_data_row([
                'avatar' => '!', 'avatar_color' => 'danger',
                'title' => sprintf(__('restaurant_imp_line_n', 'Line %d'), (int)$line), 'meta' => $why]); ?>
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <h2 style="margin-top:0;font-size:15px;"><?= e(__('restaurant_imp_sample', 'First rows')) ?></h2>
        <div class="data-list" data-single-open>
        <?php foreach (array_slice($preview['rows'], 0, 25) as $r):
            $isNewRow = $r['action'] === 'create';
            $shown    = RestaurantCustomers::displayName($r + ['id' => 0]);
            slate_data_row([
                'avatar' => (string)$r['line'], 'avatar_color' => $isNewRow ? 'success' : 'info',
                'title'  => $shown,
                'meta'   => implode(' · ', array_filter([$r['phone'] !== $shown ? $r['phone'] : '', $r['email'] !== $shown ? $r['email'] : '',
                              $r['hist_orders'] ? sprintf(__('restaurant_cust_orders_n', '%d order(s)'), $r['hist_orders']) : '',
                              $r['hist_last_visit'] ? sprintf(__('restaurant_cust_last_v', 'last %s'), $date($r['hist_last_visit'])) : ''])),
                'value'  => $money($r['hist_spend_cents']),
                'badge'  => [$isNewRow ? __('restaurant_imp_create', 'Create') : __('restaurant_imp_update', 'Update'), $isNewRow ? 'active' : 'installed'],
                'detail' => [
                    ['label' => __('restaurant_imp_line', 'Line'), 'value' => (string)$r['line']],
                    ['label' => __('restaurant_cust_name', 'Name'), 'value' => $r['name'] !== '' ? $r['name'] : __('restaurant_imp_no_name', '(no name)'), 'muted' => $r['name'] === ''],
                    ['label' => __('restaurant_cust_member', 'Member ID'), 'value' => $r['member_ref'] ?: '—'],
                    ['label' => __('restaurant_cust_email', 'Email'), 'value' => $r['email'] ?: '—', 'muted' => !$r['email']],
                    ['label' => __('restaurant_cust_phone', 'Phone'), 'value' => $r['phone'] ?: '—', 'muted' => !$r['phone']],
                    ['label' => __('restaurant_cust_spend', 'Total spend'), 'value' => $money($r['hist_spend_cents'])],
                    ['label' => __('restaurant_cust_visits', 'Visits'), 'value' => (string)$r['hist_orders']],
                    ['label' => __('restaurant_cust_last', 'Last visit'), 'value' => $date($r['hist_last_visit'])],
                    ['label' => __('restaurant_cust_first', 'First seen'), 'value' => $r['first_seen_via'] ?: $date($r['hist_first_seen'])],
                ],
            ]);
        endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php if (count($preview['rows']) > 25): ?><p class="text-sm" style="color:var(--muted)"><?= e(sprintf(__('restaurant_imp_more', 'and %d more.'), count($preview['rows']) - 25)) ?></p><?php endif; ?>
    </div>
    <form method="post" class="rc-imp-go">
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($preview['token']) ?>">
        <button class="btn btn-primary" name="_action" value="import_confirm" <?= $preview['rows'] ? '' : 'disabled' ?>><?= e(sprintf(__('restaurant_imp_confirm', 'Import %d customers'), count($preview['rows']))) ?></button>
        <button class="btn btn-ghost" name="_action" value="import_cancel"><?= e(__('restaurant_cancel', 'Cancel')) ?></button>
    </form>

<?php // ── Editor ────────────────────────────────────────────────────────────
elseif ($isNew || $editing):
    $c = $editing ?: ['id' => 0, 'name' => (string)($_POST['name'] ?? ''), 'phone' => (string)($_POST['phone'] ?? ''), 'email' => (string)($_POST['email'] ?? ''),
                      'member_ref' => (string)($_POST['member_ref'] ?? ''), 'notes' => (string)($_POST['notes'] ?? ''), 'is_active' => 1];
    slate_editor_css();
    slate_edit_open(['title_fallback' => __('restaurant_cust_new', 'New customer')]); ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <?php slate_edit_backlink(['back_href' => $self, 'back_label' => __('restaurant_cust_all', 'All customers')]); ?>
        <?php slate_edit_hero([
            'icon' => 'user', 'title' => $editing ? $editing['display'] : __('restaurant_cust_new', 'New customer'),
            'ref' => $editing ? (int)$editing['id'] : null,
            'status_text' => !empty($c['is_active']) ? __('restaurant_cust_active', 'Customer') : __('restaurant_cust_archived_tag', 'Archived'),
            'status_tone' => !empty($c['is_active']) ? 'on' : 'off',
            'stats' => $editing ? [[__('restaurant_cust_orders', 'Orders'), (string)$editing['orders']], [__('restaurant_cust_spend', 'Total spend'), $money($editing['spend'])],
                                   [__('restaurant_cust_last', 'Last visit'), $date($editing['last_visit'])]] : [],
        ]); ?>
        <?php slate_edit_card_open(['icon' => 'user', 'eyebrow' => __('restaurant_cust_contact', 'Contact'), 'title' => __('restaurant_cust_details', 'Customer details')]); ?>
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="name"><?= e(__('restaurant_cust_name', 'Name')) ?></label>
                    <input type="text" id="name" name="name" maxlength="160" value="<?= e((string)$c['name']) ?>"></div>
                <div class="field"><label class="field-label" for="phone"><?= e(__('restaurant_cust_phone', 'Phone')) ?></label>
                    <input type="text" id="phone" name="phone" maxlength="40" value="<?= e((string)($c['phone'] ?? '')) ?>"></div>
            </div>
            <div class="field-row field-row-2">
                <div class="field"><label class="field-label" for="email"><?= e(__('restaurant_cust_email', 'Email')) ?></label>
                    <input type="email" id="email" name="email" maxlength="200" value="<?= e((string)($c['email'] ?? '')) ?>"></div>
                <div class="field"><label class="field-label" for="member_ref"><?= e(__('restaurant_cust_member', 'Member ID')) ?></label>
                    <input type="text" id="member_ref" name="member_ref" maxlength="80" value="<?= e((string)($c['member_ref'] ?? '')) ?>">
                    <p class="field-hint"><?= e(__('restaurant_cust_member_hint', 'The ID from your previous system, if any. Re-imports match on it.')) ?></p></div>
            </div>
            <div class="field"><label class="field-label" for="notes"><?= e(__('restaurant_cust_notes', 'Notes')) ?></label>
                <textarea id="notes" name="notes" placeholder="<?= e(__('restaurant_cust_notes_ph', 'Allergies, preferences…')) ?>"><?= e((string)($c['notes'] ?? '')) ?></textarea></div>
            <p class="field-hint"><?= e(__('restaurant_cust_need_one', 'A name, phone or email is enough. Without a name the list shows the email, then the phone.')) ?></p>
        <?php slate_edit_card_close(); ?>
        <?php if ($editing): ?>
        <?php slate_edit_card_open(['icon' => 'clock', 'eyebrow' => __('restaurant_cust_history', 'History'), 'title' => __('restaurant_cust_history_title', 'Spend and visits')]); ?>
            <dl class="rc-hist">
                <div><dt><?= e(__('restaurant_cust_orders', 'Orders')) ?></dt><dd><?= (int)$editing['orders'] ?></dd></div>
                <div><dt><?= e(__('restaurant_cust_spend', 'Total spend')) ?></dt><dd><?= e($money($editing['spend'])) ?></dd></div>
                <div><dt><?= e(__('restaurant_cust_avg', 'Average spend')) ?></dt><dd><?= e($money($editing['avg'])) ?></dd></div>
                <div><dt><?= e(__('restaurant_cust_last', 'Last visit')) ?></dt><dd><?= e($date($editing['last_visit'])) ?></dd></div>
                <div><dt><?= e(__('restaurant_cust_first', 'First seen')) ?></dt><dd><?= e($date($editing['first_seen'])) ?><?php if ($editing['first_seen_via']): ?> <small style="font-weight:500;color:var(--muted)"><?= e(sprintf(__('restaurant_cust_via', 'via %s'), $editing['first_seen_via'])) ?></small><?php endif; ?></dd></div>
            </dl>
            <?php if ((int)$editing['hist_orders'] || (int)$editing['hist_spend_cents']): ?>
                <p class="field-hint"><?= e(sprintf(__('restaurant_cust_hist_note', 'Includes imported history: %1$s over %2$d visit(s), last %3$s.'),
                    $money($editing['hist_spend_cents']), (int)$editing['hist_orders'], $date($editing['hist_last_visit']))) ?></p>
            <?php endif; ?>
        <?php slate_edit_card_close(); ?>
        <?php endif; ?>
        <?php slate_edit_actionbar(['buttons_html' =>
            '<button type="submit" class="btn btn-primary">' . e($editing ? __('restaurant_cust_save', 'Save customer') : __('restaurant_cust_create', 'Create customer')) . '</button>'
          . '<a href="' . e($self) . '" class="btn btn-ghost">' . e(__('restaurant_cancel', 'Cancel')) . '</a>']); ?>
    </form>
<?php slate_edit_close(); slate_editor_js(); ?>

<?php // ── List ───────────────────────────────────────────────────────────────
else:
    $list  = RestaurantCustomers::list(['q' => $q, 'sort' => $sort, 'dir' => $dir, 'archived' => $tab === 'archived', 'limit' => $per, 'offset' => ($page - 1) * $per]);
    $pages = max(1, (int) ceil($list['count'] / $per));
    $base  = array_filter(['q' => $q, 'tab' => $tab === 'archived' ? 'archived' : ''], fn($v) => $v !== '');
    $sortLink = function (string $key, string $label) use ($base, $sort, $dir): string {
        $next = $sort === $key ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
        return '<a class="rc-sort' . ($sort === $key ? ' is-on' : '') . '" href="?' . e(http_build_query($base + ['sort' => $key, 'dir' => $next])) . '">'
             . e($label) . ($sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '') . '</a>';
    };
    $archivedCount = (int) Database::value("SELECT COUNT(*) FROM restaurant_customers WHERE tenant_id = ? AND is_active = 0", [$tid]); ?>
    <div class="page-header">
        <div><h1><?= e(__('restaurant_customers', 'Customers')) ?></h1>
            <p class="page-header-sub"><?= e(sprintf(__('restaurant_cust_sub', '%d customer(s)'), $list['count'])) ?></p></div>
        <div class="toolbar">
            <a href="?export=csv" class="btn" download><?= e(__('restaurant_cust_export', 'Export CSV')) ?></a>
            <button type="button" class="btn" onclick="document.getElementById('rcImport').hidden = !document.getElementById('rcImport').hidden"><?= e(__('restaurant_cust_import', 'Import CSV')) ?></button>
            <a href="?new=1" class="btn btn-primary"><?= e(__('restaurant_cust_new', 'New customer')) ?></a>
        </div>
    </div>

    <div class="card" id="rcImport" hidden>
        <form method="post" enctype="multipart/form-data" class="rc-imp-form">
            <?= csrf_field() ?><input type="hidden" name="_action" value="import_preview">
            <div class="field" style="margin:0;flex:1;min-width:220px;">
                <label class="field-label" for="csv"><?= e(__('restaurant_imp_file', 'Customer list (CSV)')) ?></label>
                <input type="file" id="csv" name="csv" accept=".csv,text/csv" required>
                <p class="field-hint"><?= e(__('restaurant_imp_hint', 'Columns: Name, Member ID, Email, Phone, Avg. Spend, Total Spend, Last Visit, First Seen (first row; any order; others ignored), as SpotOn\'s customer directory exports them. "-" and "N/A" count as empty. A row updates the customer with the same phone, else the same Member ID. You will see what happens before anything is saved.')) ?></p>
            </div>
            <button class="btn btn-primary"><?= e(__('restaurant_imp_check', 'Check file')) ?></button>
        </form>
    </div>

    <div class="rc-bar">
        <nav class="settings-tabs rc-tabs" aria-label="<?= e(__('restaurant_customers', 'Customers')) ?>">
            <a href="?<?= e(http_build_query(array_filter(['q' => $q]))) ?>" class="settings-tab<?= $tab === 'active' ? ' is-active' : '' ?>"<?= $tab === 'active' ? ' aria-current="page"' : '' ?>><?= e(__('restaurant_cust_tab_active', 'Active')) ?></a>
            <a href="?<?= e(http_build_query(array_filter(['q' => $q, 'tab' => 'archived']))) ?>" class="settings-tab<?= $tab === 'archived' ? ' is-active' : '' ?>"<?= $tab === 'archived' ? ' aria-current="page"' : '' ?>><?= e(sprintf(__('restaurant_cust_tab_archived', 'Archived (%d)'), $archivedCount)) ?></a>
        </nav>
        <form method="get" class="rc-search">
            <?php if ($tab === 'archived'): ?><input type="hidden" name="tab" value="archived"><?php endif; ?>
            <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('restaurant_cust_search_ph', 'Search name, phone, email or member ID…')) ?>">
        </form>
        <div class="rc-sorts"><span><?= e(__('restaurant_cust_sort', 'Sort:')) ?></span>
            <?= $sortLink('name', __('restaurant_cust_name', 'Name')) ?><?= $sortLink('spend', __('restaurant_cust_spend', 'Total spend')) ?><?= $sortLink('visit', __('restaurant_cust_last', 'Last visit')) ?></div>
    </div>

    <?php if (!$list['rows']): ?>
        <div class="card"><div class="empty"><div class="empty-title"><?= e($q !== '' ? __('restaurant_cust_none_match', 'No customers match') : ($tab === 'archived' ? __('restaurant_cust_none_archived', 'No archived customers') : __('restaurant_cust_none', 'No customers yet'))) ?></div>
            <?php if ($q === '' && $tab === 'active'): ?><p class="text-sm"><?= e(__('restaurant_cust_none_hint', 'Guests are added from the POS and online orders, or bring your list over with Import CSV.')) ?></p><?php endif; ?></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($list['rows'] as $c):
            $recent = RestaurantCustomers::recentOrders((int)$c['id']);
            $ordersHtml = $recent ? '<ul class="rc-orders">' . implode('', array_map(fn($o) =>
                '<li><a href="' . e(plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$o['id']) . '">#' . e($o['order_no']) . '</a> · '
                . e(date('M j, Y', strtotime((string)$o['opened_at']))) . ' · ' . e($TYPES[$o['type']] ?? $o['type']) . ' · ' . e($ST[$o['status']] ?? $o['status'])
                . ' · <b>' . e($money((int)$o['total_cents'] - (int)$o['refunded_cents'] - (int)$o['comp_cents'])) . '</b></li>', $recent)) . '</ul>'
                : '<span style="color:var(--muted)">' . e(__('restaurant_cust_no_orders', 'No orders in Kohevo yet.')) . '</span>';
            $isArch = empty($c['is_active']);
            $actions = '<a href="?edit=' . (int)$c['id'] . '" class="btn btn-sm">' . e(__('restaurant_edit', 'Edit')) . '</a>'
                     . ($c['phone'] ? ' <a href="' . e(plugin_url('restaurant', 'admin/orders.php') . '?' . http_build_query(['q' => $c['phone'], 'range' => 'all'])) . '" class="btn btn-sm">' . e(__('restaurant_cust_all_orders', 'All orders')) . '</a>' : '')
                     . ' <form method="post" style="display:inline;margin:0;"' . ($isArch ? '' : ' data-confirm="' . e((int)$c['orders'] > 0 || $recent ? __('restaurant_cust_archive_confirm', 'This customer has orders, so they will be archived, not deleted. Continue?') : __('restaurant_cust_delete_confirm', 'Delete this customer?')) . '" onsubmit="return confirm(this.dataset.confirm)"') . '>'
                     . csrf_field() . '<input type="hidden" name="id" value="' . (int)$c['id'] . '">'
                     . ($isArch ? '<button class="btn btn-sm" name="_action" value="restore">' . e(__('restaurant_cust_restore', 'Restore')) . '</button>'
                                : '<button class="btn btn-sm btn-danger" name="_action" value="delete">' . e(__('restaurant_cust_delete', 'Delete')) . '</button>')
                     . '</form>';
            $meta = array_filter([(string)($c['phone'] ?? ''), $c['display'] !== (string)($c['email'] ?? '') ? (string)($c['email'] ?? '') : '',
                sprintf(__('restaurant_cust_orders_n', '%d order(s)'), $c['orders']),
                $c['last_visit'] ? sprintf(__('restaurant_cust_last_v', 'last %s'), $date($c['last_visit'])) : '']);
            slate_data_row([
                'avatar' => mb_strtoupper(mb_substr($c['display'], 0, 2)), 'avatar_color' => $isArch ? 'muted' : 'info',
                'title'  => $c['display'],
                'meta'   => implode(' · ', $meta),
                'value'  => $money($c['spend']),
                'badge'  => $isArch ? [__('restaurant_cust_archived_tag', 'Archived'), 'inactive'] : null,
                'detail' => [
                    ['label' => __('restaurant_cust_spend', 'Total spend'), 'value' => $money($c['spend'])],
                    ['label' => __('restaurant_cust_avg', 'Average spend'), 'value' => $c['orders'] ? $money($c['avg']) : '—', 'muted' => !$c['orders']],
                    ['label' => __('restaurant_cust_orders', 'Orders'), 'value' => (string)$c['orders']],
                    ['label' => __('restaurant_cust_last', 'Last visit'), 'value' => $date($c['last_visit'])],
                    ['label' => __('restaurant_cust_first', 'First seen'), 'value' => $date($c['first_seen']) . ($c['first_seen_via'] ? ' · ' . sprintf(__('restaurant_cust_via', 'via %s'), $c['first_seen_via']) : '')],
                    ['label' => __('restaurant_cust_member', 'Member ID'), 'value' => (string)($c['member_ref'] ?: '—'), 'muted' => !$c['member_ref']],
                    ['label' => __('restaurant_cust_email', 'Email'), 'value' => (string)($c['email'] ?: '—'), 'muted' => !$c['email']],
                    ['label' => __('restaurant_cust_notes', 'Notes'), 'value' => (string)($c['notes'] ?: '—'), 'muted' => !$c['notes']],
                    ['label' => __('restaurant_cust_recent', 'Latest orders'), 'html' => $ordersHtml],
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php if ($pages > 1) slate_pagination($page, $pages, $base + ['sort' => $sort, 'dir' => $dir]); ?>
    <?php endif; ?>
<?php endif; ?>

<style>
.rc-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin-bottom: 14px; }
.rc-tabs { margin: 0; }
.rc-search { flex: 1; min-width: 220px; max-width: 360px; margin: 0; }
.rc-search input { width: 100%; }
.rc-sorts { display: flex; align-items: center; gap: 4px; font-size: 13px; color: var(--muted); }
.rc-sort { padding: 4px 9px; border-radius: 999px; color: var(--text-2); text-decoration: none; }
.rc-sort:hover { background: var(--surface-2); color: var(--text); }
.rc-sort.is-on { background: var(--accent-soft); color: var(--accent-deep); font-weight: 600; }
.rc-orders { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; }
.rc-hist { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin: 0; }
.rc-hist dt { font-size: 12px; color: var(--muted); } .rc-hist dd { margin: 2px 0 0; font-weight: 700; font-size: 17px; }
.rc-imp-form { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; }
.rc-imp-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: var(--space-4, 16px); }
.rc-imp-stats > div { padding: 12px 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); display: flex; flex-direction: column; }
.rc-imp-stats span { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
.rc-imp-stats b { font-size: 22px; } .rc-imp-stats .is-bad b { color: var(--danger); }
.rc-imp-go { display: flex; gap: 8px; margin-top: var(--space-4, 16px); }
.rr-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
