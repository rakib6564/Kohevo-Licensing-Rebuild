<?php
/**
 * Studio — admin Recitals.
 *
 * Three nested views on one page, matching how a show is actually run:
 *   (list)        every recital, with how many pieces and tickets are out
 *   ?edit=ID      the show itself + its running order, and add a class to it
 *   ?piece=ID     the costume roster for one piece — sizes, status, paid
 *
 * Costume rows are generated from the class's current enrolment rather than
 * typed, and re-running only adds newcomers, so an admin can press it again
 * after a late registration without losing sizes already collected.
 */

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/StudioAPI.php';
require_once __DIR__ . '/_ui.php';

Auth::require();
Auth::requirePerm('studio.manage_classes');
ModuleGuard::require('dance-studio');

$pageTitle  = __('studio_recitals', 'Studio · Recitals');
$currentNav = 'studio_recitals';

$selfUrl = plugin_url('studio', 'admin/recitals.php');
$isNew   = !empty($_GET['new']);
$editId  = (int) ($_GET['edit'] ?? 0);
$pieceId = (int) ($_GET['piece'] ?? 0);

$editing = $editId > 0 ? StudioAPI::getRecital($editId) : null;
if ($editId > 0 && $editing === null) { http_response_code(404); }

$piece = null;
if ($pieceId > 0) {
    $piece = Database::row(
        "SELECT p.*, s.name AS class_name, r.name AS recital_name, r.id AS recital_id
           FROM studio_recital_pieces p
           JOIN studio_class_series s ON s.id = p.series_id AND s.tenant_id = p.tenant_id
           JOIN studio_recitals r ON r.id = p.recital_id AND r.tenant_id = p.tenant_id
          WHERE p.tenant_id = ? AND p.id = ?",
        [current_tenant_id(), $pieceId]
    );
    if ($piece === null) { http_response_code(404); }
}

$isForm = $isNew || $editing !== null;
$flash  = null;
$money  = static fn (int $c): string => '$' . number_format($c / 100, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $do     = (string) ($_POST['_do'] ?? '');
        try {
            if ($action === 'save_recital') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id > 0) {
                    StudioAPI::updateRecital($id, $_POST);
                    studio_set_flash('success', __('studio_recital_updated', 'Recital updated.'));
                    header('Location: ' . $selfUrl . '?edit=' . $id); exit;
                }
                $id = StudioAPI::createRecital($_POST);
                studio_set_flash('success', __('studio_recital_created', 'Recital created — now add the classes performing in it.'));
                header('Location: ' . $selfUrl . '?edit=' . $id); exit;

            } elseif ($action === 'add_piece') {
                $rid = (int) ($_POST['recital_id'] ?? 0);
                $sid = (int) ($_POST['series_id'] ?? 0);
                if ($sid <= 0) { throw new \InvalidArgumentException(__('studio_pick_a_class', 'Pick a class.')); }
                StudioAPI::addRecitalPiece($rid, $sid, $_POST);
                studio_set_flash('success', __('studio_piece_added', 'Class added to the running order.'));
                header('Location: ' . $selfUrl . '?edit=' . $rid); exit;

            } elseif ($action === 'sync_costumes') {
                $pid  = (int) ($_POST['piece_id'] ?? 0);
                $cost = (int) round(((float) ($_POST['cost'] ?? 0)) * 100);
                $n = StudioAPI::syncCostumesForPiece($pid, $cost);
                studio_set_flash('success', sprintf(__('studio_costumes_made', '%d costume record(s) added.'), $n));
                header('Location: ' . $selfUrl . '?piece=' . $pid); exit;

            } elseif ($action === 'save_costumes') {
                $pid = (int) ($_POST['piece_id'] ?? 0);
                foreach ((array) ($_POST['c'] ?? []) as $cid => $row) {
                    if (!is_array($row)) { continue; }
                    // Sizing and fulfilment only. Costume money lives in
                    // studio_fees (Fees page) — writing a cost here as well is
                    // what billed the same $95 twice.
                    StudioAPI::updateCostume((int) $cid, [
                        'size'   => $row['size']   ?? '',
                        'status' => $row['status'] ?? 'pending',
                    ]);
                }
                studio_set_flash('success', __('studio_costumes_saved', 'Costumes saved.'));
                header('Location: ' . $selfUrl . '?piece=' . $pid); exit;

            } elseif (str_starts_with($do, 'rmpiece_')) {
                $pid = (int) substr($do, 8);
                $rid = (int) Database::value('SELECT recital_id FROM studio_recital_pieces WHERE tenant_id = ? AND id = ?',
                    [current_tenant_id(), $pid]);
                StudioAPI::removeRecitalPiece($pid);
                studio_set_flash('success', __('studio_piece_removed', 'Class removed from the recital.'));
                header('Location: ' . $selfUrl . '?edit=' . $rid); exit;

            } elseif (str_starts_with($do, 'delete_')) {
                StudioAPI::deleteRecital((int) substr($do, 7));
                studio_set_flash('success', __('studio_recital_deleted', 'Recital deleted.'));
                header('Location: ' . $selfUrl); exit;

            } elseif ($do === 'bulk_delete') {
                $n = 0;
                foreach (array_map('intval', (array) ($_POST['ids'] ?? [])) as $id) {
                    if ($id > 0 && StudioAPI::deleteRecital($id)) { $n++; }
                }
                studio_set_flash('success', sprintf(__('studio_bulk_del_recitals', '%d recital(s) deleted.'), $n));
                header('Location: ' . $selfUrl); exit;
            }
        } catch (\Throwable $e) {
            $flash  = ['type' => 'error', 'msg' => $e->getMessage()];
            $isForm = true;
        }
    }
}

$flash = $flash ?? studio_take_flash();

require SLATE_ROOT . '/admin/partials/header.php';
studio_ui_css();
?>

<style>
.srec-run { display:flex; align-items:center; gap:12px; padding:11px 0; border-bottom:1px solid var(--border); }
.srec-run:last-child { border-bottom:0; }
.srec-pos { width:26px; height:26px; flex:none; border-radius:50%; display:grid; place-items:center;
    background:var(--accent-soft); color:var(--accent-ink, var(--accent)); font-size:12px; font-weight:700; }
.srec-run-main { flex:1; min-width:0; }
.srec-run-main b { display:block; font-size:13.5px; }
.srec-run-main span { font-size:12px; color:var(--muted); }
.srec-cost { display:grid; grid-template-columns:1.4fr 110px 130px 90px auto; gap:10px; align-items:center;
    padding:9px 0; border-bottom:1px solid var(--border); }
.srec-cost:last-of-type { border-bottom:0; }
.srec-cost input[type=text], .srec-cost input[type=number], .srec-cost select { width:100%; }
.srec-cost-name { font-weight:600; font-size:13.5px; overflow-wrap:anywhere; }
.srec-head { display:grid; grid-template-columns:1.4fr 110px 130px 90px auto; gap:10px;
    font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); padding-bottom:6px; }
@media (max-width: 720px) {
    .srec-cost, .srec-head { grid-template-columns:1fr 1fr; }
    .srec-head { display:none; }
    .srec-cost-name { grid-column:1 / -1; }
}
</style>

<?php
$crumbs = [
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('studio', 'Studio'),       'href' => plugin_url('studio', 'admin/index.php')],
    ['label' => __('studio_recitals_h', 'Recitals'), 'href' => ($isForm || $piece) ? $selfUrl : null],
];
if ($piece)        { $crumbs[] = ['label' => (string) $piece['recital_name'], 'href' => $selfUrl . '?edit=' . (int) $piece['recital_id']];
                     $crumbs[] = ['label' => (string) $piece['class_name']]; }
elseif ($editing)  { $crumbs[] = ['label' => (string) $editing['name']]; }
elseif ($isNew)    { $crumbs[] = ['label' => __('studio_new_recital', 'New recital')]; }
slate_breadcrumbs($crumbs);
?>

<div class="page-header">
    <div>
        <h1><?= $piece ? e((string) $piece['class_name']) : ($isForm ? ($editing ? e((string) $editing['name']) : __('studio_new_recital', 'New recital')) : __('studio_recitals_h', 'Recitals')) ?></h1>
        <p class="page-header-sub"><?= $piece
            ? __('studio_costume_sub', 'Sizes, order status and payment for this piece.')
            : __('studio_recitals_sub', 'Shows, running order, costumes and tickets.') ?></p>
    </div>
    <div class="toolbar">
        <?php if ($piece): ?>
            <a href="<?= e($selfUrl) ?>?edit=<?= (int) $piece['recital_id'] ?>" class="btn">← <?= __('studio_back_recital', 'Back to recital') ?></a>
        <?php elseif ($isForm): ?>
            <a href="<?= e($selfUrl) ?>" class="btn">← <?= __('studio_back_recitals', 'All recitals') ?></a>
        <?php else: ?>
            <a href="<?= e($selfUrl) ?>?new=1" class="btn btn-primary">+ <?= __('studio_new_recital', 'New recital') ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($piece):
    // ── Costume roster for one piece ──
    $costumes = StudioAPI::getCostumesForPiece($pieceId);
    $statuses = ['pending' => __('studio_cos_pending', 'Pending'), 'measured' => __('studio_cos_measured', 'Measured'),
                 'ordered' => __('studio_cos_ordered', 'Ordered'), 'received' => __('studio_cos_received', 'Received'),
                 'distributed' => __('studio_cos_given', 'Given out')];
?>
    <div class="card">
        <div class="card-header"><h2><?= __('studio_costumes', 'Costumes') ?></h2></div>
        <?php if (!$costumes): ?>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-3)">
                <?= __('studio_costumes_none', 'No costume records yet. Generate one per enrolled dancer — you can re-run this later to pick up new registrations without losing sizes already entered.') ?>
            </p>
        <?php endif; ?>
        <form method="post" class="toolbar" style="gap:var(--space-2);align-items:flex-end">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="sync_costumes">
            <input type="hidden" name="piece_id" value="<?= $pieceId ?>">
            <div class="field" style="margin:0">
                <label class="field-label" for="cost"><?= __('studio_costume_cost', 'Cost each') ?></label>
                <input type="number" step="0.01" min="0" id="cost" name="cost" value="0.00" style="width:120px">
            </div>
            <button type="submit" class="btn"><?= __('studio_costume_generate', 'Add missing dancers') ?></button>
        </form>
    </div>

    <?php if ($costumes): ?>
    <div class="card">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_costumes">
            <input type="hidden" name="piece_id" value="<?= $pieceId ?>">
            <div class="srec-head">
                <span><?= __('studio_dancer', 'Dancer') ?></span>
                <span><?= __('studio_size', 'Size') ?></span>
                <span><?= __('status', 'Status') ?></span>
                <span><?= __('studio_cost', 'Cost') ?></span>
                <span><?= __('studio_paid', 'Paid') ?></span>
            </div>
            <?php foreach ($costumes as $c): $cid = (int) $c['id']; ?>
                <div class="srec-cost">
                    <span class="srec-cost-name"><?= e((string) ($c['student_name'] ?: ('#' . $c['student_id']))) ?></span>
                    <input type="text" name="c[<?= $cid ?>][size]" value="<?= e((string) ($c['size'] ?? '')) ?>"
                           placeholder="<?= e(__('studio_size_ph', 'e.g. Child L')) ?>" aria-label="<?= e(__('studio_size', 'Size')) ?>">
                    <select name="c[<?= $cid ?>][status]" aria-label="<?= e(__('status', 'Status')) ?>">
                        <?php foreach ($statuses as $v => $l): ?>
                            <option value="<?= e($v) ?>" <?= ((string) $c['status']) === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php // Cost and Paid moved to the Fees page — one place for money. ?>
                </div>
            <?php endforeach; ?>
            <div class="toolbar" style="margin-top:var(--space-4)">
                <button type="submit" class="btn btn-primary"><?= __('studio_save_costumes', 'Save costumes') ?></button>
            </div>
        </form>
    </div>
    <?php endif; ?>

<?php elseif ($isForm):
    // ── Recital form (+ running order once it exists) ──
    $ed = $editing ?? [];
    $fv = static fn (string $k, $d = '') => e((string) ($ed[$k] ?? $d));
?>
    <div class="card">
        <div class="card-header"><h2><?= __('studio_recital_details', 'Show details') ?></h2></div>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_recital">
            <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $ed['id'] ?>"><?php endif; ?>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="name"><?= __('studio_recital_name', 'Recital name') ?></label>
                    <input type="text" id="name" name="name" required maxlength="190" value="<?= $fv('name') ?>"
                           placeholder="<?= e(__('studio_recital_ph', 'e.g. Spring Showcase 2026')) ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="recital_date"><?= __('studio_date', 'Date') ?></label>
                    <input type="date" id="recital_date" name="recital_date" value="<?= $fv('recital_date') ?>">
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="venue"><?= __('studio_venue', 'Venue') ?></label>
                    <input type="text" id="venue" name="venue" maxlength="190" value="<?= $fv('venue') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
                    <select id="status" name="status">
                        <?php foreach (['draft' => __('studio_draft', 'Draft'), 'published' => __('studio_published', 'Published'),
                                        'done' => __('studio_done', 'Done'), 'cancelled' => __('cancelled', 'Cancelled')] as $v => $l): ?>
                            <option value="<?= e($v) ?>" <?= ($ed['status'] ?? 'draft') === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label" for="call_time"><?= __('studio_call_time', 'Call time') ?></label>
                    <input type="time" id="call_time" name="call_time" value="<?= $fv('call_time') ?>">
                    <div class="field-hint"><?= __('studio_call_hint', 'When dancers arrive') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="doors_time"><?= __('studio_doors', 'Doors') ?></label>
                    <input type="time" id="doors_time" name="doors_time" value="<?= $fv('doors_time') ?>">
                </div>
                <div class="field">
                    <label class="field-label" for="ticket_price"><?= __('studio_ticket_price', 'Ticket price') ?></label>
                    <input type="number" step="0.01" min="0" id="ticket_price" name="ticket_price"
                           value="<?= e(number_format(((int) ($ed['ticket_price_cents'] ?? 0)) / 100, 2, '.', '')) ?>">
                </div>
            </div>

            <div class="field-row field-row-2">
                <div class="field">
                    <label class="field-label" for="seats_total"><?= __('studio_seats', 'Seats available') ?></label>
                    <input type="number" min="0" id="seats_total" name="seats_total" value="<?= $fv('seats_total') ?>">
                    <div class="field-hint"><?= __('studio_seats_hint', 'Leave blank if you don\'t track a seat count') ?></div>
                </div>
                <div class="field">
                    <label class="field-label" for="notes"><?= __('notes', 'Notes') ?></label>
                    <input type="text" id="notes" name="notes" maxlength="255" value="<?= $fv('notes') ?>">
                </div>
            </div>

            <div class="toolbar">
                <button type="submit" class="btn btn-primary">
                    <?= $editing ? __('studio_save_recital', 'Save recital') : __('studio_create_recital', 'Create recital') ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($editing):
        $pieces  = StudioAPI::getRecitalPieces((int) $ed['id']);
        $summary = StudioAPI::recitalSummary((int) $ed['id']);
        $classes = StudioAPI::getActiveClassSeries();
        $inShow  = array_column($pieces, 'series_id');
    ?>
    <div class="card">
        <div class="card-header"><h2><?= __('studio_at_a_glance', 'At a glance') ?></h2></div>
        <div class="dwidget-kpis">
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_tickets_out', 'Tickets out') ?></div>
                <div class="dwidget-kpi-v"><?= (int) $summary['tickets_sold'] ?><?php if ($summary['seats_total']): ?><span style="font-size:14px;color:var(--muted)"> / <?= (int) $summary['seats_total'] ?></span><?php endif; ?></div>
            </div>
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_ticket_revenue', 'Ticket revenue') ?></div>
                <div class="dwidget-kpi-v"><?= e($money((int) $summary['ticket_revenue_cents'])) ?></div>
            </div>
            <div class="dwidget-kpi">
                <div class="dwidget-kpi-k"><?= __('studio_costumes', 'Costumes') ?></div>
                <div class="dwidget-kpi-v"><?= (int) $summary['costumes_paid'] ?>/<?= (int) $summary['costumes'] ?></div>
                <div class="dwidget-kpi-note"><?= e($money((int) $summary['costume_due_cents'])) ?> <?= __('studio_outstanding_lc', 'outstanding') ?></div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2><?= __('studio_running_order', 'Running order') ?></h2></div>
        <?php if (!$pieces): ?>
            <p class="text-sm text-muted" style="margin:0 0 var(--space-3)"><?= __('studio_no_pieces', 'No classes in this show yet.') ?></p>
        <?php else: foreach ($pieces as $p): ?>
            <div class="srec-run">
                <span class="srec-pos"><?= (int) $p['position'] ?></span>
                <span class="srec-run-main">
                    <b><?= e((string) ($p['title'] ?: $p['class_name'])) ?></b>
                    <span>
                        <?= e((string) $p['class_name']) ?>
                        <?php if ($p['music']): ?> · <?= e((string) $p['music']) ?><?php endif; ?>
                        · <?= (int) $p['costume_paid'] ?>/<?= (int) $p['costume_count'] ?> <?= __('studio_costumes_lc', 'costumes paid') ?>
                    </span>
                </span>
                <a href="<?= e($selfUrl) ?>?piece=<?= (int) $p['id'] ?>" class="btn btn-sm"><?= __('studio_costumes', 'Costumes') ?></a>
                <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <button type="submit" name="_do" value="rmpiece_<?= (int) $p['id'] ?>" class="btn btn-sm btn-danger"
                            data-confirm="<?= e(__('studio_confirm_rmpiece', 'Remove this class from the recital? Its costume records go too.')) ?>">
                        <?= __('remove', 'Remove') ?>
                    </button>
                </form>
            </div>
        <?php endforeach; endif; ?>

        <form method="post" class="toolbar" style="margin-top:var(--space-4);gap:var(--space-2);align-items:flex-end">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="add_piece">
            <input type="hidden" name="recital_id" value="<?= (int) $ed['id'] ?>">
            <div class="field" style="margin:0;min-width:220px">
                <label class="field-label" for="series_id"><?= __('studio_add_class', 'Add a class') ?></label>
                <select id="series_id" name="series_id">
                    <option value=""><?= __('studio_choose_class', '— Choose a class —') ?></option>
                    <?php foreach ($classes as $c): if (in_array($c['id'], $inShow)) continue; ?>
                        <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="margin:0;min-width:180px">
                <label class="field-label" for="music"><?= __('studio_music', 'Music') ?></label>
                <input type="text" id="music" name="music" maxlength="190">
            </div>
            <button type="submit" class="btn"><?= __('studio_add_to_show', 'Add to show') ?></button>
        </form>
    </div>
    <?php endif; ?>

<?php else:
    // ── List ──
    $recitals = StudioAPI::getRecitals();
    // Bulk actions are what make the list a <form>; without one the per-row
    // Delete below would be a submit button with nothing to submit to.
    studio_ui_list_start([], __('studio_search_recitals', 'Search recitals…'), [
        ['value' => 'delete', 'label' => __('delete', 'Delete'), 'danger' => true,
         'confirm' => __('studio_confirm_bulk_del_recital', 'Delete the selected recitals, with their running order, costumes and tickets?')],
    ]);
    foreach ($recitals as $r):
        $when = $r['recital_date'] ? date('D, j M Y', strtotime((string) $r['recital_date'])) : __('studio_no_date', 'No date set');
        $badge = match ((string) $r['status']) {
            'published' => [__('studio_published', 'Published'), 'active'],
            'done'      => [__('studio_done', 'Done'), 'inactive'],
            'cancelled' => [__('cancelled', 'Cancelled'), 'warning'],
            default     => [__('studio_draft', 'Draft'), 'inactive'],
        };
        studio_ui_row([
            'avatar'       => (string) $r['name'],
            'avatar_color' => 'info',
            'title'        => (string) $r['name'],
            'meta'         => $when . ($r['venue'] ? ' · ' . $r['venue'] : ''),
            'badge'        => $badge,
            'value'        => sprintf(__('studio_n_pieces', '%d pieces'), (int) $r['piece_count']),
            'details'      => [
                __('studio_tickets_out', 'Tickets out') => (string) (int) $r['tickets_out'],
                __('studio_ticket_revenue', 'Ticket revenue') => $money((int) $r['ticket_revenue']),
                __('studio_ticket_price', 'Ticket price') => $money((int) $r['ticket_price_cents']),
            ],
            'actions'      => '<a href="' . e($selfUrl) . '?edit=' . (int) $r['id'] . '" class="btn btn-sm btn-primary">'
                            . e(__('studio_open', 'Open')) . '</a>'
                            . '<button type="submit" name="_do" value="delete_' . (int) $r['id'] . '" class="btn btn-sm btn-danger"'
                            . ' data-confirm="' . e(__('studio_confirm_del_recital', 'Delete this recital, its running order, costumes and tickets?')) . '">'
                            . e(__('delete', 'Delete')) . '</button>',
            '_id'          => (int) $r['id'],   // renders the bulk-select checkbox
            '_search'      => strtolower((string) $r['name'] . ' ' . (string) ($r['venue'] ?? '')),
        ]);
    endforeach;
    studio_ui_list_end(count($recitals), __('studio_no_recitals', 'No recitals yet'), [
        'icon'      => 'sparkles',
        'sub'       => __('studio_no_recitals_sub', 'Create a show, add the classes performing in it, then generate costume records for each piece.'),
        'cta_href'  => $selfUrl . '?new=1',
        'cta_label' => __('studio_new_recital', 'New recital'),
    ]);
endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
