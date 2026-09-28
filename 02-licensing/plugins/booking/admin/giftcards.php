<?php
/**
 * Booking — gift cards CRUD. Create with an initial balance; balance is
 * debited automatically when applied at checkout.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('booking.manage_payments');
ModuleGuard::require('booking');
BookingAPI::ensureSchema();

$pageTitle  = __('booking_giftcards', 'Gift cards');
$currentNav = 'booking-giftcards';
$tid = current_tenant_id();

$flash  = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isNew  = !empty($_GET['new']);

$editing = null;
if ($editId > 0) {
    $editing = Database::row("SELECT * FROM booking_gift_cards WHERE id = ? AND tenant_id = ?", [$editId, $tid]);
    if (!$editing) { http_response_code(404); $editId = 0; $editing = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            if ($code === '' && $id === 0) $code = 'GC-' . strtoupper(bin2hex(random_bytes(4)));
            if ($id === 0) {
                $initial = max(0, (int)round(((float)($_POST['initial'] ?? 0)) * 100));
                try {
                    $newId = Database::insert('booking_gift_cards', [
                        'tenant_id'     => $tid,
                        'code'          => mb_substr($code, 0, 60),
                        'initial_cents' => $initial,
                        'balance_cents' => $initial,
                        'currency'      => slate_default_currency(),
                        'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
                    ]);
                    AuditLog::record('booking.giftcard_created', (string)$newId);
                    header('Location: ' . plugin_url('booking', 'admin/giftcards.php'));
                    exit;
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => __('booking_giftcard_code_exists', 'That code already exists.')];
                }
            } else {
                Database::update('booking_gift_cards', [
                    'balance_cents' => max(0, (int)round(((float)($_POST['balance'] ?? 0)) * 100)),
                    'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
                ], 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.giftcard_updated', (string)$id);
                header('Location: ' . plugin_url('booking', 'admin/giftcards.php'));
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                Database::delete('booking_gift_cards', 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('booking.giftcard_deleted', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('booking_giftcard_deleted', 'Gift card deleted.')];
                header('Location: ' . plugin_url('booking', 'admin/giftcards.php'));
                exit;
            }
        }
    }
}

$cards = Database::rows("SELECT * FROM booking_gift_cards WHERE tenant_id = ? ORDER BY created_at DESC", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('booking', 'Booking'), 'href' => plugin_url('booking', 'admin/index.php')],
    ['label' => __('booking_giftcards', 'Gift cards')],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($isNew || $editing):
    $g = $editing ?: ['id'=>0,'code'=>'','initial_cents'=>0,'balance_cents'=>0,'currency'=>slate_default_currency(),'is_active'=>1];
    $gInitials = ($editing && trim((string)$editing['code']) !== '') ? mb_strtoupper(mb_substr($editing['code'], 0, 2)) : 'GC';
    $gDepleted = $editing && (int)$g['balance_cents'] <= 0;
?>
<?php booking_editor_css(); ?>

<?php booking_edit_open([
    'title_fallback' => __('booking_new_giftcard', 'New gift card'),
]); ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">

        <?php booking_edit_backlink([
            'back_href'  => plugin_url('booking', 'admin/giftcards.php'),
            'back_label' => __('booking_all_giftcards', 'All gift cards'),
        ]); ?>

        <?php booking_edit_hero([
            'icon'         => 'gift',
            'initials'     => $gInitials,
            'title'        => $editing ? $editing['code'] : __('booking_new_giftcard', 'New gift card'),
            'ref'          => $editing ? (int)$editing['id'] : null,
            'active'       => !empty($g['is_active']) && !$gDepleted,
            'toggle_name'  => 'is_active',
            'toggle_id'    => 'is_active',
            'toggle_title' => __('booking_giftcard_toggle_active', 'Active (redeemable)'),
            'status_on'    => __('booking_giftcard_status_active', 'Active · redeemable'),
            'status_off'   => $gDepleted ? __('booking_empty', 'Empty') : __('inactive', 'Inactive'),
            'stats'        => $editing ? [
                [__('booking_balance', 'Balance'), slate_format_price((int)$g['balance_cents'], $g['currency'])],
                [__('booking_initial', 'Initial'), slate_format_price((int)$g['initial_cents'], $g['currency'])],
                [__('booking_created', 'Created'), !empty($g['created_at']) ? '<small>' . e(I18n::localDate('j M Y', strtotime($g['created_at']))) . '</small>' : '—'],
            ] : [],
        ]); ?>

        <?php booking_edit_card_open(['icon' => 'tag', 'eyebrow' => __('booking_general', 'General'), 'title' => $editing ? __('booking_balance', 'Balance') : __('booking_giftcard_details', 'Gift card details')]); ?>
            <?php if (!$editing): ?>
                <div class="field-row field-row-2" style="margin-bottom:0;">
                    <div class="field" style="margin-bottom:0;">
                        <label class="field-label" for="name"><?= e(__('booking_code', 'Code')) ?> <span class="text-muted text-xs">(<?= e(__('booking_blank_auto', 'blank = auto')) ?>)</span></label>
                        <input type="text" id="name" name="code" maxlength="60" value="" placeholder="<?= e(__('booking_giftcard_code_ph', 'GC-XXXXXXXX')) ?>" style="text-transform:uppercase;">
                    </div>
                    <div class="field" style="margin-bottom:0;">
                        <label class="field-label" for="initial"><?= e(__('booking_initial_amount', 'Initial amount')) ?> <span class="text-muted text-xs">(<?= e(slate_default_currency()) ?>)</span> <span class="field-required">*</span></label>
                        <input type="number" id="initial" name="initial" min="0" step="0.01" required value="">
                    </div>
                </div>
            <?php else: ?>
                <?php booking_edit_card_note(__('booking_giftcard_fixed_note', 'The code and initial amount are fixed once issued. Adjust the remaining balance below.')); ?>
                <div class="field" style="margin-bottom:0;max-width:240px;">
                    <label class="field-label" for="balance"><?= e(sprintf(__('booking_balance_currency', 'Balance (%s)'), $g['currency'])) ?></label>
                    <input type="number" id="balance" name="balance" min="0" step="0.01" value="<?= e(number_format(((int)$g['balance_cents'])/100, 2, '.', '')) ?>">
                </div>
            <?php endif; ?>
        <?php booking_edit_card_close(); ?>

        <?php booking_edit_actionbar([
            'buttons_html' =>
                '<button type="submit" class="btn btn-primary">' . ($editing ? e(__('save_changes', 'Save changes')) : e(__('booking_create_giftcard', 'Create gift card'))) . '</button>'
              . '<a href="' . e(plugin_url('booking', 'admin/giftcards.php')) . '" class="btn btn-ghost">' . e(__('cancel', 'Cancel')) . '</a>',
        ]); ?>

    </form>
<?php booking_edit_close(); ?>

<?php booking_editor_js(); ?>

<?php else: ?>
    <div class="page-header">
        <div><h1><?= e(__('booking_giftcards', 'Gift cards')) ?></h1></div>
        <a href="?new=1" class="btn btn-primary"><?= e(__('booking_new_giftcard', 'New gift card')) ?></a>
    </div>

    <?php if (!$cards): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= e(__('booking_no_giftcards', 'No gift cards yet')) ?></div></div></div>
    <?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($cards as $g):
            $depleted = (int)$g['balance_cents'] <= 0;
            $actions = '<a href="?edit=' . (int)$g['id'] . '" class="btn btn-sm">' . e(__('edit', 'Edit')) . '</a> '
                     . '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'' . e(__('booking_confirm_delete_giftcard', 'Delete this gift card?')) . '\')">'
                     . csrf_field()
                     . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$g['id'] . '">'
                     . '<button class="btn btn-sm btn-danger">' . e(__('delete', 'Delete')) . '</button></form>';
            slate_data_row([
                'avatar'       => mb_substr($g['code'], 0, 1),
                'avatar_color' => ($g['is_active'] && !$depleted) ? 'accent' : 'muted',
                'title'        => $g['code'],
                'meta'         => sprintf(__('booking_giftcard_meta', '%s of %s'), slate_format_price_plain((int)$g['balance_cents'], $g['currency']), number_format($g['initial_cents']/100, 2)),
                'badge'        => $depleted ? [__('booking_empty', 'Empty'), 'inactive'] : ($g['is_active'] ? [__('active', 'Active'), 'active'] : [__('inactive', 'Inactive'), 'inactive']),
                'detail'       => [
                    __('booking_balance', 'Balance') => slate_format_price_plain((int)$g['balance_cents'], $g['currency']),
                    __('booking_initial', 'Initial') => slate_format_price_plain((int)$g['initial_cents'], $g['currency']),
                    __('booking_created', 'Created') => I18n::localDate('j M Y', strtotime($g['created_at'])),
                ],
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
    <?php endif; ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
