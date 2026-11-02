<?php
/**
 * Restaurant — Stripe Terminal card-reader management.
 *
 * Register/forget internet readers (Stripe Reader S700 / BBPOS WisePOS E) and
 * the single Terminal Location they belong to. In test mode you can spin up a
 * SIMULATED reader to exercise the full card-present flow without hardware.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';

Auth::require();
Auth::requirePerm('restaurant.manage_settings');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$pageTitle  = 'Card readers';
$currentNav = 'restaurant-readers';
$tid = current_tenant_id();

$stripeReady   = class_exists('StripePaymentAPI') && StripePaymentAPI::isConfigured();
$terminalReady = class_exists('StripeTerminalAPI');
$mode          = $stripeReady ? StripePaymentAPI::mode() : 'test';
$flash         = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $terminalReady && $stripeReady) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'ensure_location') {
            $s = RestaurantAPI::settings();
            $res = StripeTerminalAPI::ensureLocation([
                'line1' => $s['addr_line1'], 'line2' => $s['addr_line2'], 'city' => $s['addr_city'],
                'state' => $s['addr_state'], 'postal_code' => $s['addr_postal'], 'country' => $s['addr_country'],
            ], $s['biz_name'] ?: 'Restaurant');
            $flash = $res['ok']
                ? ['type' => 'success', 'msg' => 'Terminal Location ready: ' . $res['id']]
                : ['type' => 'error', 'msg' => $res['error']];
        } elseif ($action === 'register') {
            $code  = trim((string)($_POST['registration_code'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            $res = StripeTerminalAPI::registerReader($code, $label);
            if ($res['ok']) {
                $r = $res['reader'];
                Database::insert('restaurant_readers', [
                    'tenant_id'        => $tid,
                    'label'            => mb_substr($label !== '' ? $label : ($r['label'] ?? 'Reader'), 0, 120),
                    'stripe_reader_id' => (string)$r['id'],
                    'status'           => (($r['status'] ?? '') === 'online') ? 'online' : 'offline',
                    'last_seen_at'     => slate_db_now(),
                ]);
                AuditLog::record('restaurant.reader_registered', (string)$r['id']);
                $flash = ['type' => 'success', 'msg' => 'Reader registered.'];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error']];
            }
        } elseif ($action === 'add_simulated') {
            $res = StripeTerminalAPI::createSimulatedReader('Simulated reader');
            if ($res['ok']) {
                $r = $res['reader'];
                Database::insert('restaurant_readers', [
                    'tenant_id'        => $tid,
                    'label'            => 'Simulated reader',
                    'stripe_reader_id' => (string)$r['id'],
                    'status'           => 'online',
                    'last_seen_at'     => slate_db_now(),
                ]);
                $flash = ['type' => 'success', 'msg' => 'Simulated reader added.'];
            } else {
                $flash = ['type' => 'error', 'msg' => $res['error']];
            }
        } elseif ($action === 'delete') {
            $id  = (int)($_POST['id'] ?? 0);
            $row = Database::row("SELECT * FROM restaurant_readers WHERE id = ? AND tenant_id = ?", [$id, $tid]);
            if ($row) {
                StripeTerminalAPI::deleteReader((string)$row['stripe_reader_id']);
                Database::delete('restaurant_readers', 'id = ? AND tenant_id = ?', [$id, $tid]);
                $flash = ['type' => 'success', 'msg' => 'Reader removed.'];
            }
        } elseif ($action === 'refresh') {
            // Pull live status from Stripe and update local rows.
            foreach (StripeTerminalAPI::listReaders() as $r) {
                Database::query(
                    "UPDATE restaurant_readers SET status = ?, last_seen_at = ?
                      WHERE stripe_reader_id = ? AND tenant_id = ?",
                    [(($r['status'] ?? '') === 'online' ? 'online' : 'offline'), slate_db_now(), (string)$r['id'], $tid]);
            }
            $flash = ['type' => 'success', 'msg' => 'Reader status refreshed.'];
        }
    }
    if (in_array(($_POST['_action'] ?? ''), ['ensure_location','register','add_simulated','delete','refresh'], true) && $flash && $flash['type'] === 'success') {
        // Stay on the page; flash already set.
    }
}

$locationId = $terminalReady ? StripeTerminalAPI::locationId() : '';
$readers    = Database::rows("SELECT * FROM restaurant_readers WHERE tenant_id = ? ORDER BY label", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => 'Card readers'],
]); ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header"><div><h1>Card readers</h1><p class="page-header-sub">Stripe Terminal readers for card-present payments.</p></div></div>

<?php if (!$stripeReady): ?>
    <div class="alert alert-warning">The Stripe plugin isn't configured. Add your API keys in <a href="<?= e(plugin_url('stripe-payment', 'admin/settings.php')) ?>">Stripe settings</a> before pairing readers.</div>
<?php elseif (!$terminalReady): ?>
    <div class="alert alert-error">The Stripe Terminal helper isn't loaded. Make sure the stripe-payment plugin is up to date.</div>
<?php else: ?>

    <div class="card">
        <div class="card-header"><h2>Terminal Location</h2><span class="badge badge-<?= $mode === 'live' ? 'active' : 'inactive' ?>"><?= e(strtoupper($mode)) ?> mode</span></div>
        <div class="card-body">
            <?php if ($locationId === ''): ?>
                <p class="text-sm">No Location yet. Stripe requires one (even for a single site) before a reader can be paired. It uses the address from your <a href="<?= e(plugin_url('restaurant', 'admin/settings.php')) ?>">settings</a>.</p>
                <form method="post" style="margin:0;">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="ensure_location">
                    <button class="btn btn-primary">Create Terminal Location</button>
                </form>
            <?php else: ?>
                <p class="text-sm" style="margin:0;">Location: <code><?= e($locationId) ?></code></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Pair a reader</h2></div>
        <div class="card-body">
            <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                <?= csrf_field() ?><input type="hidden" name="_action" value="register">
                <div class="field" style="margin:0;width:200px;">
                    <label class="field-label">Pairing code <span class="field-hint">on the device</span></label>
                    <input type="text" name="registration_code" placeholder="quick-brown-fox" <?= $locationId === '' ? 'disabled' : '' ?>>
                </div>
                <div class="field" style="margin:0;flex:1;min-width:160px;">
                    <label class="field-label">Label</label>
                    <input type="text" name="label" placeholder="Front counter" <?= $locationId === '' ? 'disabled' : '' ?>>
                </div>
                <button class="btn btn-primary" <?= $locationId === '' ? 'disabled' : '' ?>>Register</button>
            </form>
            <?php if ($mode === 'test' && $locationId !== ''): ?>
                <form method="post" style="margin-top:12px;">
                    <?= csrf_field() ?><input type="hidden" name="_action" value="add_simulated">
                    <button class="btn">+ Add a simulated reader (test mode)</button>
                    <span class="text-xs text-muted">Exercises the full flow without hardware.</span>
                </form>
            <?php endif; ?>
            <?php if ($locationId === ''): ?>
                <p class="text-xs text-muted" style="margin-top:10px;">Create the Location above first.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Registered readers (<?= count($readers) ?>)</h2>
            <form method="post" style="margin:0;"><?= csrf_field() ?><input type="hidden" name="_action" value="refresh"><button class="btn btn-sm">Refresh status</button></form>
        </div>
        <?php if (!$readers): ?>
            <div class="empty"><div class="empty-title">No readers yet</div></div>
        <?php else: ?>
        <div class="data-list" data-single-open>
            <?php foreach ($readers as $r):
                $badge = $r['status'] === 'online' ? ['Online','active'] : ($r['status'] === 'offline' ? ['Offline','inactive'] : ['Unknown','muted']);
                $sim   = str_contains((string)$r['stripe_reader_id'], 'simulated');
                $actions = '<form method="post" style="display:inline;margin:0;" onsubmit="return confirm(\'Forget this reader?\')">'
                         . csrf_field() . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                         . '<button class="btn btn-sm btn-danger">Forget</button></form>';
                slate_data_row([
                    'avatar'       => $sim ? 'SIM' : 'RD',
                    'avatar_color' => $r['status'] === 'online' ? 'success' : 'muted',
                    'title'        => $r['label'] . ($sim ? ' (simulated)' : ''),
                    'meta'         => $r['stripe_reader_id'],
                    'badge'        => $badge,
                    'detail'       => ['Stripe ID' => ['label'=>'Stripe ID','html'=>'<code>' . e($r['stripe_reader_id']) . '</code>'],
                                       'Status' => ucfirst($r['status']),
                                       'Last seen' => $r['last_seen_at'] ?: '—'],
                    'actions'      => $actions,
                ]);
            endforeach; ?>
        </div>
        <?php slate_data_list_script(); ?>
        <?php endif; ?>
    </div>

<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
