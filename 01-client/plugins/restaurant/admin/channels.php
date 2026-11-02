<?php
/**
 * Restaurant — order channels.
 *
 * The list shows each channel (an aggregator or other system that sends
 * orders in our canonical JSON), whether it has ever been heard from, and
 * how many of its SKUs still need mapping; plus the menu feed token. A
 * channel's page holds its settings and signing secret, the SKU map (each
 * SKU to a menu item, and each modifier SKU to one of that item's options),
 * orders it sent that were not accepted (with Retry), and the status
 * callbacks sent back. RestaurantChannels (includes/channels.php) does the
 * work and documents the canonical shape. A Deliverect channel signs with a
 * secret Deliverect issues (pasted here) and takes status updates through
 * Deliverect's API, so its page asks for those instead of a status URL.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/channels.php';

Auth::require();
Auth::requirePerm('restaurant.channels');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid   = current_tenant_id();
$self  = plugin_url('restaurant', 'admin/channels.php');
$chSlug  = strtolower(trim((string)($_GET['channel'] ?? '')));
$chan  = $chSlug !== '' ? RestaurantChannels::channel($chSlug) : null;
$flash = null;
$shown = null;   // a secret or token shown once, right after it is made
$intakeBase = rtrim(SLATE_URL, '/') . '/order-channel/';
$feedUrl    = rtrim(SLATE_URL, '/') . '/menu-feed';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')];
    } else {
        try {
            switch ((string)($_POST['_action'] ?? '')) {
                case 'add':
                    $new = strtolower(trim((string)($_POST['slug'] ?? '')));
                    if (RestaurantChannels::channel($new)) throw new \RuntimeException(__('restaurant_ch_err_exists', 'A channel with that ID already exists.'));
                    RestaurantChannels::saveChannel($new, ['name' => (string)($_POST['name'] ?? ''), 'is_enabled' => 0, 'kind' => (string)($_POST['kind'] ?? '')]);
                    header('Location: ' . $self . '?channel=' . rawurlencode($new) . '&added=1'); exit;
                case 'save':
                    RestaurantChannels::saveChannel($chSlug, ['name' => (string)($_POST['name'] ?? ''), 'is_enabled' => !empty($_POST['is_enabled']),
                        'callback_url' => (string)($_POST['callback_url'] ?? ($chan['callback_url'] ?? ''))]);
                    $flash = ['type' => 'success', 'msg' => __('restaurant_ch_saved', 'Channel saved.')];
                    break;
                case 'paste_secret':
                    RestaurantChannels::setSecret($chSlug, (string)($_POST['secret'] ?? ''));
                    $flash = ['type' => 'success', 'msg' => __('restaurant_ch_secret_saved', 'Secret saved.')];
                    break;
                case 'dl_api':
                    RestaurantDeliverectAdapter::saveSettings($chSlug, (string)($_POST['env'] ?? ''), (string)($_POST['client_id'] ?? ''), (string)($_POST['client_secret'] ?? ''));
                    $flash = ['type' => 'success', 'msg' => __('restaurant_dl_api_saved', 'Deliverect API settings saved.')];
                    break;
                case 'secret':
                    $shown = ['label' => __('restaurant_ch_secret_new', 'New signing secret. Copy it into the channel now; it will not be shown again.'),
                              'value' => RestaurantChannels::rotateSecret($chSlug)];
                    break;
                case 'map':
                    $mods = [];
                    foreach ((array)($_POST['mod'] ?? []) as $msku => $v) {
                        if (preg_match('/^(modifier|choice):(\d+)$/', (string)$v, $m)) $mods[(string)$msku] = ['type' => $m[1], 'id' => (int)$m[2]];
                    }
                    RestaurantChannels::saveMapping($chSlug, (string)($_POST['sku'] ?? ''), (int)($_POST['item_id'] ?? 0) ?: null, $mods);
                    $flash = ['type' => 'success', 'msg' => sprintf(__('restaurant_ch_mapped', '%s mapped.'), (string)($_POST['sku'] ?? ''))];
                    break;
                case 'retry':
                    [$code, $body] = RestaurantChannels::retry((int)($_POST['intake_id'] ?? 0));
                    $flash = $code === 200
                        ? ['type' => 'success', 'msg' => sprintf(__('restaurant_ch_retry_ok', 'Accepted as order #%s.'), $body['order_no'])]
                        : ['type' => 'error', 'msg' => (string)($body['error'] ?? __('restaurant_pos_failed', 'That did not work. Try again.'))];
                    break;
                case 'feed_token':
                    $shown = ['label' => __('restaurant_ch_feed_new', 'New feed token. Give it to the channel now; it will not be shown again, and the old one stops working.'),
                              'value' => RestaurantChannels::rotateFeedToken()];
                    break;
            }
        } catch (\RuntimeException $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
        $chan = $chSlug !== '' ? RestaurantChannels::channel($chSlug) : null;
    }
}
if (!empty($_GET['added'])) $flash = ['type' => 'success', 'msg' => __('restaurant_ch_added', 'Channel added. Make its signing secret, then switch it on.')];
if ($chSlug !== '' && !$chan) http_response_code(404);

$pageTitle  = __('restaurant_channels', 'Channels');
$currentNav = 'restaurant-channels';
require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs(array_values(array_filter([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_channels', 'Channels'), 'href' => $chan ? $self : null],
    $chan ? ['label' => $chan['name']] : null,
])));
if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif;
if ($shown): ?>
    <div class="alert alert-warning rc-secret" role="status"><div><?= e($shown['label']) ?></div>
        <code id="rcShown"><?= e($shown['value']) ?></code>
        <button type="button" class="btn btn-sm" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('rcShown').textContent)"><?= e(__('restaurant_ch_copy', 'Copy')) ?></button></div>
<?php endif;

$connected = fn(array $c) => !empty($c['last_verified_at']);

if ($chan):
    $skus   = RestaurantChannels::skus($chSlug);
    $items  = Database::rows("SELECT i.id, i.name, c.name AS category FROM restaurant_items i LEFT JOIN restaurant_menu_categories c ON c.id = i.category_id
                               WHERE i.tenant_id = ? ORDER BY c.sort_order, c.name, i.sort_order, i.name", [$tid]);
    $rejected = Database::rows("SELECT * FROM restaurant_channel_orders WHERE tenant_id = ? AND channel = ? AND intake_status IN ('rejected','received') ORDER BY id DESC LIMIT 50", [$tid, $chSlug]);
    $recent   = Database::rows("SELECT co.*, o.order_no, o.fulfillment_status, o.flag_reason FROM restaurant_channel_orders co
                                  LEFT JOIN restaurant_orders o ON o.id = co.order_id
                                 WHERE co.tenant_id = ? AND co.channel = ? AND co.intake_status = 'created' ORDER BY co.id DESC LIMIT 20", [$tid, $chSlug]);
    $events   = Database::rows("SELECT e.*, co.external_order_id FROM restaurant_channel_events e JOIN restaurant_channel_orders co ON co.id = e.channel_order_id
                                 WHERE e.tenant_id = ? AND co.channel = ? ORDER BY e.id DESC LIMIT 20", [$tid, $chSlug]);
    $unmapped = RestaurantChannels::unmappedCount($chSlug);
    $hasSecret = trim((string)($chan['secret'] ?? '')) !== '';
    $isDl = ($chan['kind'] ?? '') === 'deliverect';
    $cfg = $isDl ? RestaurantChannels::config($chSlug) : [];
?>
    <div class="page-header">
        <div><h1><?= e($chan['name']) ?></h1>
            <p class="page-header-sub"><?= e($chan['slug']) ?> ·
                <?= $connected($chan) ? e(sprintf(__('restaurant_ch_last_heard', 'last verified order %s'), date('M j, g:ia', strtotime((string)$chan['last_verified_at']))))
                                      : e(__('restaurant_ch_never', 'no verified order received yet')) ?></p></div>
        <div class="toolbar">
            <span class="badge badge-<?= !empty($chan['is_enabled']) ? 'active' : 'inactive' ?>"><?= e(!empty($chan['is_enabled']) ? __('restaurant_ch_on', 'On') : __('restaurant_ch_off', 'Off')) ?></span>
            <?php if ($connected($chan)): ?><span class="badge badge-installed"><?= e(__('restaurant_ch_connected', 'Connected')) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="rc-grid">
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_settings', 'Connection')) ?></h2>
        <form method="post" class="rc-form">
            <?= csrf_field() ?><input type="hidden" name="_action" value="save">
            <div class="field"><label class="field-label" for="name"><?= e(__('restaurant_ch_name', 'Name')) ?></label>
                <input type="text" id="name" name="name" maxlength="120" value="<?= e($chan['name']) ?>" required></div>
            <?php if (!$isDl): ?>
            <div class="field"><label class="field-label" for="callback_url"><?= e(__('restaurant_ch_callback', 'Status URL')) ?></label>
                <input type="url" id="callback_url" name="callback_url" maxlength="500" value="<?= e((string)($chan['callback_url'] ?? '')) ?>" placeholder="https://…">
                <p class="field-hint"><?= e(__('restaurant_ch_callback_hint', 'Where accepted / preparing / ready / completed / cancelled updates go, unless an order names its own. Leave empty if the channel does not take updates.')) ?></p></div>
            <?php endif; ?>
            <label class="field-check rc-check"><input type="checkbox" name="is_enabled" value="1" <?= !empty($chan['is_enabled']) ? 'checked' : '' ?><?= $hasSecret ? '' : ' disabled' ?>>
                <span><?= e($hasSecret ? __('restaurant_ch_enable', 'Take orders from this channel')
                    : ($isDl ? __('restaurant_dl_enable_need', 'Paste the Deliverect secret before switching the channel on') : __('restaurant_ch_enable_need', 'Make a signing secret before switching the channel on'))) ?></span></label>
            <button class="btn btn-primary"><?= e(__('restaurant_ch_save', 'Save')) ?></button>
        </form>
    </section>
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_endpoint', 'What the channel needs')) ?></h2>
        <dl class="rc-dl">
            <div><dt><?= e(__('restaurant_ch_intake_url', 'Order URL (POST, JSON)')) ?></dt><dd><code><?= e($intakeBase . $chan['slug']) ?></code></dd></div>
            <div><dt><?= e(__('restaurant_ch_sig', 'Signature header')) ?></dt><dd><code><?= $isDl ? 'x-server-authorization-hmac-sha256: &lt;hex HMAC-SHA256 of the body&gt;' : 'X-Channel-Signature: sha256=&lt;HMAC-SHA256 of the body&gt;' ?></code></dd></div>
            <div><dt><?= e(__('restaurant_ch_secret', 'Signing secret')) ?></dt><dd><?= e($hasSecret ? __('restaurant_ch_secret_set', 'Set (hidden)') : ($isDl ? __('restaurant_dl_secret_none', 'Not pasted yet') : __('restaurant_ch_secret_none', 'Not made yet'))) ?></dd></div>
        </dl>
        <?php if ($isDl): ?>
        <form method="post" class="rc-paste">
            <?= csrf_field() ?><input type="hidden" name="_action" value="paste_secret">
            <input type="password" name="secret" maxlength="500" autocomplete="off" required placeholder="<?= e(__('restaurant_dl_secret_ph', 'HMAC secret from Deliverect')) ?>" aria-label="<?= e(__('restaurant_dl_secret_ph', 'HMAC secret from Deliverect')) ?>">
            <button class="btn"><?= e($hasSecret ? __('restaurant_dl_secret_replace', 'Replace secret') : __('restaurant_dl_secret_save', 'Save secret')) ?></button>
        </form>
        <p class="field-hint" style="margin-top:10px;"><?= e(__('restaurant_dl_secret_hint', 'Deliverect signs every order. In its staging environment the secret is your channelLink ID; a certified integration uses the production HMAC secret from Deliverect\'s integration settings. Map Deliverect PLUs below.')) ?></p>
        <?php else: ?>
        <form method="post" data-confirm="<?= e(__('restaurant_ch_secret_confirm', 'Make a new secret? Orders signed with the old one will be refused.')) ?>" onsubmit="return <?= $hasSecret ? 'confirm(this.dataset.confirm)' : 'true' ?>">
            <?= csrf_field() ?><input type="hidden" name="_action" value="secret">
            <button class="btn"><?= e($hasSecret ? __('restaurant_ch_secret_rotate', 'Make a new secret') : __('restaurant_ch_secret_make', 'Make the signing secret')) ?></button>
        </form>
        <p class="field-hint" style="margin-top:10px;"><?= e(__('restaurant_ch_shape_hint', 'Orders use the canonical shape documented in the Help page (Channels): external order ID, type, customer, items by SKU with modifier SKUs, the total charged and an optional status URL.')) ?></p>
        <?php endif; ?>
    </section>
    </div>

    <?php if ($isDl): $dlEnv = (string)($cfg['env'] ?? 'staging'); $dlHasKey = trim((string)($cfg['client_secret'] ?? '')) !== ''; ?>
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_dl_api', 'Status updates to Deliverect')) ?></h2>
        <p class="text-sm" style="color:var(--muted);margin-top:0;"><?= e(__('restaurant_dl_api_hint', 'Accepted, preparing, ready, completed and cancelled go to Deliverect\'s order status API, and an order we cannot take is reported as Failed so the marketplace hears of it. Use the API client ID and secret Deliverect issued for this integration.')) ?></p>
        <form method="post" class="rc-api">
            <?= csrf_field() ?><input type="hidden" name="_action" value="dl_api">
            <div class="field"><label class="field-label" for="dl_env"><?= e(__('restaurant_dl_env', 'Environment')) ?></label>
                <select id="dl_env" name="env"><?php foreach (['staging' => __('restaurant_dl_staging', 'Staging (testing)'), 'production' => __('restaurant_dl_production', 'Production')] as $k => $l): ?>
                    <option value="<?= e($k) ?>" <?= $dlEnv === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="field-label" for="dl_cid"><?= e(__('restaurant_dl_client_id', 'Client ID')) ?></label>
                <input type="text" id="dl_cid" name="client_id" maxlength="200" autocomplete="off" value="<?= e((string)($cfg['client_id'] ?? '')) ?>"></div>
            <div class="field"><label class="field-label" for="dl_cs"><?= e(__('restaurant_dl_client_secret', 'Client secret')) ?></label>
                <input type="password" id="dl_cs" name="client_secret" maxlength="500" autocomplete="off" placeholder="<?= e($dlHasKey ? __('restaurant_dl_keep', 'Set (leave empty to keep)') : '') ?>"></div>
            <button class="btn btn-primary"><?= e(__('restaurant_ch_save', 'Save')) ?></button>
        </form>
    </section>
    <?php endif; ?>

    <section class="card">
        <header class="rc-sec-h"><h2 class="rc-h"><?= e(__('restaurant_ch_map', 'SKU map')) ?></h2>
            <?php if ($unmapped): ?><span class="badge badge-warning"><?= e(sprintf(__('restaurant_ch_unmapped_n', '%d to map'), $unmapped)) ?></span><?php endif; ?></header>
        <p class="text-sm" style="color:var(--muted);margin-top:0;"><?= e(__('restaurant_ch_map_hint', 'Each SKU the channel sends must point at a menu item, and each modifier SKU at one of that item\'s options. An order with any unmapped SKU is not accepted. SKUs appear here as soon as an order uses them; you can also add one by hand.')) ?></p>
        <div class="data-list" data-single-open>
        <?php foreach ($skus as $sku => $info):
            $m = $info['mapping']; $itemId = (int)($m['item_id'] ?? 0);
            $mm = json_decode((string)($m['modifier_map'] ?? ''), true) ?: [];
            $missing = !$itemId ? 1 : count(array_filter($info['modifiers'], fn($x) => !isset($mm[$x])));
            $opts = $itemId ? RestaurantChannels::mappableOptions($itemId) : [];
            $itemName = '';
            foreach ($items as $it) if ((int)$it['id'] === $itemId) $itemName = $it['name'];
            ob_start(); ?>
            <form method="post" class="rc-map">
                <?= csrf_field() ?><input type="hidden" name="_action" value="map"><input type="hidden" name="sku" value="<?= e($sku) ?>">
                <label class="field-label"><?= e(__('restaurant_ch_map_item', 'Menu item')) ?></label>
                <select name="item_id"><option value=""><?= e(__('restaurant_ch_not_mapped', '— not mapped —')) ?></option>
                    <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" <?= (int)$it['id'] === $itemId ? 'selected' : '' ?>><?= e(($it['category'] ? $it['category'] . ' › ' : '') . $it['name']) ?></option><?php endforeach; ?></select>
                <?php if ($info['modifiers']): ?>
                    <label class="field-label" style="margin-top:8px;"><?= e(__('restaurant_ch_map_mods', 'Modifier SKUs')) ?></label>
                    <?php foreach ($info['modifiers'] as $msku): $cur = isset($mm[$msku]) ? $mm[$msku]['type'] . ':' . $mm[$msku]['id'] : ''; ?>
                        <div class="rc-modrow"><code><?= e($msku) ?></code>
                            <select name="mod[<?= e($msku) ?>]"><option value=""><?= e($itemId ? __('restaurant_ch_not_mapped', '— not mapped —') : __('restaurant_ch_pick_item_first', 'map the item first')) ?></option>
                                <?php foreach ($opts as $k => $label): ?><option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <button class="btn btn-sm btn-primary" style="margin-top:8px;"><?= e(__('restaurant_ch_save_map', 'Save mapping')) ?></button>
            </form>
            <?php $form = (string) ob_get_clean();
            slate_data_row([
                'avatar' => $missing ? '!' : '✓', 'avatar_color' => $missing ? 'warning' : 'success',
                'title'  => $sku,
                'meta'   => implode(' · ', array_filter([$itemName !== '' ? '→ ' . $itemName : __('restaurant_ch_not_mapped_short', 'not mapped'),
                              $info['modifiers'] ? sprintf(__('restaurant_ch_mods_n', '%d modifier SKUs'), count($info['modifiers'])) : '',
                              $info['seen'] ? sprintf(__('restaurant_ch_seen_n', 'in %d orders'), $info['seen']) : ''])),
                'badge'  => $missing ? [sprintf(__('restaurant_ch_missing_n', '%d missing'), $missing), 'warning'] : null,
                'detail' => [['label' => __('restaurant_ch_mapping', 'Mapping'), 'html' => $form]],
            ]);
        endforeach; ?>
        </div>
        <?php if (!$skus): ?><p class="text-sm" style="color:var(--muted);"><?= e(__('restaurant_ch_no_skus', 'No SKUs yet. They appear when the channel sends its first order, or add one below.')) ?></p><?php endif; ?>
        <form method="post" class="rc-add-sku">
            <?= csrf_field() ?><input type="hidden" name="_action" value="map">
            <input type="text" name="sku" maxlength="120" placeholder="<?= e(__('restaurant_ch_sku_ph', 'Channel SKU, e.g. BRISKET-PLATE')) ?>" required>
            <select name="item_id"><option value=""><?= e(__('restaurant_ch_map_item', 'Menu item')) ?>…</option>
                <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>"><?= e($it['name']) ?></option><?php endforeach; ?></select>
            <button class="btn"><?= e(__('restaurant_ch_add_sku', 'Add SKU')) ?></button>
        </form>
    </section>

    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_rejected', 'Orders not accepted')) ?></h2>
        <?php if (!$rejected): ?><p class="text-sm" style="color:var(--muted);margin:0;"><?= e(__('restaurant_ch_none_rejected', 'None. Every order this channel sent was accepted.')) ?></p>
        <?php else: ?><div class="data-list" data-single-open>
            <?php foreach ($rejected as $r) slate_data_row([
                'avatar' => '!', 'avatar_color' => 'danger',
                'title'  => (string)$r['external_order_id'],
                'meta'   => date('M j, g:ia', strtotime((string)$r['created_at'])) . ' · ' . (string)($r['reject_reason'] ?? __('restaurant_ch_stuck', 'not finished')),
                'detail' => [['label' => __('restaurant_ch_reason', 'Reason'), 'value' => (string)($r['reject_reason'] ?? '—')],
                             ['label' => __('restaurant_ch_claimed', 'Channel total'), 'value' => $r['claimed_total_cents'] !== null ? RestaurantAPI::money((int)$r['claimed_total_cents']) : '—']],
                'actions'=> '<form method="post" style="margin:0">' . csrf_field() . '<input type="hidden" name="_action" value="retry"><input type="hidden" name="intake_id" value="' . (int)$r['id'] . '">'
                          . '<button class="btn btn-sm btn-primary">' . e(__('restaurant_ch_retry', 'Retry now')) . '</button></form>',
            ]); ?></div>
        <?php endif; ?>
    </section>

    <div class="rc-grid">
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_recent', 'Recent orders')) ?></h2>
        <?php if (!$recent): ?><p class="text-sm" style="color:var(--muted);margin:0;"><?= e(__('restaurant_ch_none_recent', 'None yet.')) ?></p>
        <?php else: ?><div class="data-list"><?php foreach ($recent as $r) slate_data_row([
            'avatar' => '#', 'avatar_color' => 'info', 'title' => '#' . $r['order_no'] . ' · ' . $r['external_order_id'],
            'meta' => date('M j, g:ia', strtotime((string)$r['created_at'])) . ' · ' . $r['fulfillment_status'] . ($r['flag_reason'] ? ' · ' . $r['flag_reason'] : ''),
            'badge' => $r['flag_reason'] ? [__('restaurant_ch_check_total', 'Check total'), 'warning'] : null,
            'actions' => '<a class="btn btn-sm" href="' . e(plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$r['order_id']) . '">' . e(__('restaurant_pos_details', 'Order details')) . '</a>',
            'detail' => [['label' => __('restaurant_ch_external', 'Channel order ID'), 'value' => (string)$r['external_order_id']]],
        ]); ?></div><?php endif; ?>
    </section>
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_events', 'Status updates sent')) ?></h2>
        <?php if (!$events): ?><p class="text-sm" style="color:var(--muted);margin:0;"><?= e(__('restaurant_ch_none_events', 'None yet.')) ?></p>
        <?php else: ?><div class="data-list"><?php foreach ($events as $ev) slate_data_row([
            'avatar' => $ev['error'] ? '!' : '✓', 'avatar_color' => $ev['error'] ? 'danger' : 'success',
            'title' => $ev['external_order_id'] . ' → ' . $ev['status'],
            'meta' => date('M j, g:ia', strtotime((string)$ev['created_at'])) . ' · ' . ($ev['error'] ?: 'HTTP ' . (int)$ev['http_status']),
        ]); ?></div><?php endif; ?>
    </section>
    </div>

<?php else:
    $chs = RestaurantChannels::channels();
    $token = RestaurantChannels::feedToken(); ?>
    <div class="page-header">
        <div><h1><?= e(__('restaurant_channels', 'Channels')) ?></h1>
            <p class="page-header-sub"><?= e(__('restaurant_ch_sub', 'Systems that send you orders (such as an aggregator like Deliverect), and the menu feed they read.')) ?></p></div>
    </div>
    <div class="alert alert-info"><?= e(__('restaurant_ch_truth', 'Marketplace orders (DoorDash, Uber Eats) reach you only through an aggregator you contract with; Kohevo does not connect to the marketplaces directly. Point the aggregator at a channel here and map its SKUs.')) ?></div>
    <section class="card">
        <?php if (!$chs): ?><p class="text-sm" style="color:var(--muted);margin-top:0;"><?= e(__('restaurant_ch_none', 'No channels yet.')) ?></p>
        <?php else: ?><div class="data-list"><?php foreach ($chs as $c):
            $u = RestaurantChannels::unmappedCount((string)$c['slug']);
            $n = Database::row("SELECT SUM(intake_status = 'created') AS ok, SUM(intake_status = 'rejected') AS bad FROM restaurant_channel_orders WHERE tenant_id = ? AND channel = ?", [$tid, $c['slug']]);
            slate_data_row([
                'avatar' => mb_strtoupper(mb_substr($c['name'], 0, 2)), 'avatar_color' => !empty($c['is_enabled']) ? 'info' : 'muted',
                'title' => $c['name'],
                'meta' => $c['slug'] . (($c['kind'] ?? '') === 'deliverect' ? ' · Deliverect' : '') . ' · ' . ($connected($c) ? __('restaurant_ch_connected', 'Connected') : __('restaurant_ch_never_short', 'never heard from'))
                        . ' · ' . sprintf(__('restaurant_ch_counts', '%1$d accepted, %2$d not accepted'), (int)($n['ok'] ?? 0), (int)($n['bad'] ?? 0)),
                'badge' => $u ? [sprintf(__('restaurant_ch_unmapped_n', '%d to map'), $u), 'warning'] : [!empty($c['is_enabled']) ? __('restaurant_ch_on', 'On') : __('restaurant_ch_off', 'Off'), !empty($c['is_enabled']) ? 'active' : 'inactive'],
                'actions' => '<a class="btn btn-sm btn-primary" href="?channel=' . e(rawurlencode((string)$c['slug'])) . '">' . e(__('restaurant_ch_open', 'Open')) . '</a>',
                'detail' => [['label' => __('restaurant_ch_intake_url', 'Order URL (POST, JSON)'), 'html' => '<code>' . e($intakeBase . $c['slug']) . '</code>']],
            ]);
        endforeach; ?></div><?php endif; ?>
        <form method="post" class="rc-add">
            <?= csrf_field() ?><input type="hidden" name="_action" value="add">
            <input type="text" name="name" maxlength="120" placeholder="<?= e(__('restaurant_ch_name_ph', 'Name, e.g. Deliverect')) ?>" required>
            <input type="text" name="slug" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}" placeholder="<?= e(__('restaurant_ch_slug_ph', 'ID, e.g. deliverect')) ?>" required>
            <select name="kind" aria-label="<?= e(__('restaurant_ch_kind', 'Type')) ?>">
                <option value=""><?= e(__('restaurant_ch_kind_canonical', 'Canonical JSON')) ?></option>
                <option value="deliverect">Deliverect</option>
            </select>
            <button class="btn btn-primary"><?= e(__('restaurant_ch_add', 'Add channel')) ?></button>
        </form>
    </section>
    <section class="card">
        <h2 class="rc-h"><?= e(__('restaurant_ch_feed', 'Menu feed')) ?></h2>
        <p class="text-sm" style="color:var(--muted);margin-top:0;"><?= e(__('restaurant_ch_feed_hint', 'The whole menu as JSON (categories, items with price, tax, photo and 86 state, modifier groups, combos), for a channel to read. Send the token as "Authorization: Bearer <token>".')) ?></p>
        <dl class="rc-dl"><div><dt><?= e(__('restaurant_ch_feed_url', 'Feed URL (GET)')) ?></dt><dd><code><?= e($feedUrl) ?></code></dd></div>
            <div><dt><?= e(__('restaurant_ch_feed_token', 'Token')) ?></dt><dd><?= e($token !== '' ? __('restaurant_ch_secret_set', 'Set (hidden)') : __('restaurant_ch_feed_off', 'None: the feed is off')) ?></dd></div></dl>
        <form method="post" onsubmit="return <?= $token !== '' ? 'confirm(this.dataset.confirm)' : 'true' ?>" data-confirm="<?= e(__('restaurant_ch_feed_confirm', 'Make a new token? The old one stops working at once.')) ?>">
            <?= csrf_field() ?><input type="hidden" name="_action" value="feed_token">
            <button class="btn"><?= e($token !== '' ? __('restaurant_ch_feed_rotate', 'Make a new token') : __('restaurant_ch_feed_make', 'Turn on the feed')) ?></button>
        </form>
    </section>
<?php endif;
slate_data_list_script(); ?>

<style>
.rc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4, 16px); }
.rc-grid > .card { margin: 0 0 var(--space-4, 16px); }
.rc-h { margin: 0 0 10px; font-size: 15px; }
.rc-sec-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.rc-form .field { margin-bottom: 10px; }
.rc-check { display: flex; align-items: center; gap: 8px; margin: 4px 0 12px; }
.rc-dl { display: grid; gap: 8px; margin: 0 0 12px; }
.rc-dl dt { font-size: 12px; font-weight: 600; color: var(--muted); }
.rc-dl dd { margin: 2px 0 0; }
.rc-dl code, .rc-secret code { word-break: break-all; font-size: 12.5px; }
.rc-secret { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; }
.rc-secret code { padding: 4px 8px; border-radius: 6px; background: var(--surface); }
.rc-map select { width: 100%; }
.rc-modrow { display: grid; grid-template-columns: minmax(0, 140px) minmax(0, 1fr); gap: 8px; align-items: center; margin-top: 6px; }
.rc-modrow code { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rc-add, .rc-add-sku, .rc-paste { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.rc-paste { margin-top: 0; }
.rc-paste input { flex: 1; min-width: 180px; }
.rc-api { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 12px; align-items: end; }
.rc-api .field { margin-bottom: 10px; }
.rc-api > .btn { justify-self: start; }
.rc-add > input, .rc-add > select, .rc-add-sku > input, .rc-add-sku > select { flex: 1 1 160px; min-width: 0; width: auto; }
.rc-add > .btn, .rc-add-sku > .btn { flex: 0 0 auto; }
@media (max-width: 860px) { .rc-grid, .rc-api { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 520px) { .rc-modrow { grid-template-columns: minmax(0, 1fr); } }
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
