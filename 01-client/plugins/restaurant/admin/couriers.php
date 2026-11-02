<?php
/**
 * Restaurant — couriers for our own delivery orders.
 *
 * DoorDash Drive settings (environment label, developer ID, key ID, signing
 * secret stored encrypted), Uber Direct settings (customer ID, client ID,
 * client secret and webhook signing key, secrets stored encrypted), the pickup details couriers are given (business
 * name and address from Settings, plus a phone number), the webhook address
 * to paste into the provider's portal, the auto-request switch, and the
 * latest courier jobs. RestaurantDelivery (includes/delivery.php) does the
 * work.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/RestaurantAPI.php';
require_once dirname(__DIR__) . '/includes/delivery.php';

Auth::require();
Auth::requirePerm('restaurant.channels');
ModuleGuard::require('restaurant');
RestaurantAPI::ensureSchema();

$tid   = current_tenant_id();
$self  = plugin_url('restaurant', 'admin/couriers.php');
$flash = null;
$set   = fn(string $k, string $v) => Database::setSetting('restaurant.' . $k, $v);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('restaurant_err_csrf', 'Security check failed. Reload the page and try again.')];
    } else {
        try {
            switch ((string)($_POST['_action'] ?? '')) {
                case 'drive':
                    $env = in_array($_POST['env'] ?? '', ['sandbox', 'production'], true) ? (string)$_POST['env'] : 'sandbox';
                    $set('drive_env', $env);
                    $set('drive_developer_id', mb_substr(trim((string)($_POST['developer_id'] ?? '')), 0, 100));
                    $set('drive_key_id', mb_substr(trim((string)($_POST['key_id'] ?? '')), 0, 100));
                    $secret = trim((string)($_POST['signing_secret'] ?? ''));
                    if ($secret !== '') $set('drive_signing_secret', RestaurantChannels::seal($secret));
                    $set('drive_enabled', !empty($_POST['enabled']) ? '1' : '0');
                    AuditLog::record('restaurant.courier_settings', 'doordash_drive', ['env' => $env, 'enabled' => !empty($_POST['enabled'])]);
                    $flash = ['type' => 'success', 'msg' => __('restaurant_cr_saved', 'DoorDash Drive settings saved.')];
                    break;
                case 'uber':
                    $env = in_array($_POST['env'] ?? '', ['test', 'live'], true) ? (string)$_POST['env'] : 'test';
                    $set('uber_env', $env);
                    $set('uber_customer_id', mb_substr(trim((string)($_POST['customer_id'] ?? '')), 0, 100));
                    $set('uber_client_id', mb_substr(trim((string)($_POST['client_id'] ?? '')), 0, 200));
                    $secret = trim((string)($_POST['client_secret'] ?? ''));
                    if ($secret !== '') $set('uber_client_secret', RestaurantChannels::seal($secret));
                    $hook = trim((string)($_POST['webhook_key'] ?? ''));
                    if ($hook !== '') $set('uber_webhook_key', RestaurantChannels::seal($hook));
                    $set('uber_enabled', !empty($_POST['enabled']) ? '1' : '0');
                    RestaurantUberDirect::forgetToken();
                    AuditLog::record('restaurant.courier_settings', 'uber_direct', ['env' => $env, 'enabled' => !empty($_POST['enabled'])]);
                    $flash = ['type' => 'success', 'msg' => __('restaurant_cr_uber_saved', 'Uber Direct settings saved.')];
                    break;
                case 'check_uber':
                    $r = RestaurantUberDirect::checkCredentials();
                    $flash = $r['ok'] ? ['type' => 'success', 'msg' => __('restaurant_cr_uber_check_ok', 'Uber accepted the credentials.')]
                                      : ['type' => 'error', 'msg' => sprintf(__('restaurant_cr_uber_check_bad', 'Uber did not accept the credentials: %s'), $r['error'])];
                    break;
                case 'check':
                    $r = RestaurantDoorDashDrive::checkCredentials();
                    $flash = $r['ok'] ? ['type' => 'success', 'msg' => __('restaurant_cr_check_ok', 'DoorDash accepted the key.')]
                                      : ['type' => 'error', 'msg' => sprintf(__('restaurant_cr_check_bad', 'DoorDash did not accept the key: %s'), $r['error'])];
                    break;
                case 'general':
                    $set('biz_phone', mb_substr(trim((string)($_POST['biz_phone'] ?? '')), 0, 40));
                    $set('courier_auto', !empty($_POST['courier_auto']) ? '1' : '0');
                    $flash = ['type' => 'success', 'msg' => __('restaurant_cr_saved_general', 'Saved.')];
                    break;
                case 'webhook_token':
                    RestaurantDelivery::rotateWebhookToken();
                    $flash = ['type' => 'success', 'msg' => __('restaurant_cr_hook_made', 'New webhook address made. Update it in the provider\'s portal; the old one stops working.')];
                    break;
            }
        } catch (\RuntimeException $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$pickup  = RestaurantDelivery::pickup();
$drive   = new RestaurantDoorDashDrive();
$dEnv    = (string) RestaurantAPI::setting('drive_env', 'sandbox');
$hasKey  = trim((string) RestaurantAPI::setting('drive_signing_secret', '')) !== '';
$uber    = new RestaurantUberDirect();
$uEnv    = (string) RestaurantAPI::setting('uber_env', 'test');
$hasUSec = trim((string) RestaurantAPI::setting('uber_client_secret', '')) !== '';
$hasUKey = trim((string) RestaurantAPI::setting('uber_webhook_key', '')) !== '';
$token   = RestaurantDelivery::webhookToken();
$hookUrl = fn(string $provider) => $token !== '' ? rtrim(SLATE_URL, '/') . '/courier-webhook/' . $provider . '/' . $token : '';
$jobs    = Database::rows("SELECT j.*, o.order_no FROM restaurant_delivery_jobs j LEFT JOIN restaurant_orders o ON o.id = j.order_id
                            WHERE j.tenant_id = ? ORDER BY j.id DESC LIMIT 25", [$tid]);

$pageTitle  = __('restaurant_couriers', 'Couriers');
$currentNav = 'restaurant-couriers';
require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('restaurant', 'Restaurant'), 'href' => plugin_url('restaurant', 'admin/index.php')],
    ['label' => __('restaurant_couriers', 'Couriers')],
]);
if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="page-header">
    <div><h1><?= e(__('restaurant_couriers', 'Couriers')) ?></h1>
        <p class="page-header-sub"><?= e(__('restaurant_cr_sub', 'Send a courier for your own delivery orders. Staff request one from Online orders; the courier service bills you for each delivery.')) ?></p></div>
</div>

<div class="alert alert-info"><?= e(__('restaurant_cr_truth', 'Each courier service needs your own account with it. DoorDash Drive: DoorDash currently limits Drive API access and reviews every integration before production; its sandbox and Delivery Simulator are for testing. Uber Direct: Uber approves each business and its test credentials only make test deliveries. Orders placed in the DoorDash or Uber Eats apps are a different thing: they reach you through a channel (see Channels).')) ?></div>

<div class="rcu-grid">
<section class="card">
    <header class="rcu-h"><h2>DoorDash Drive</h2>
        <span class="badge badge-<?= $drive->enabled() ? 'active' : 'inactive' ?>"><?= e($drive->enabled() ? ($dEnv === 'production' ? __('restaurant_cr_on_prod', 'On · production') : __('restaurant_cr_on_sandbox', 'On · sandbox')) : __('restaurant_ch_off', 'Off')) ?></span></header>
    <form method="post" class="rcu-form" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="_action" value="drive">
        <div class="field"><label class="field-label" for="d_env"><?= e(__('restaurant_cr_env', 'Environment')) ?></label>
            <select id="d_env" name="env">
                <option value="sandbox" <?= $dEnv === 'sandbox' ? 'selected' : '' ?>><?= e(__('restaurant_cr_sandbox', 'Sandbox (test deliveries)')) ?></option>
                <option value="production" <?= $dEnv === 'production' ? 'selected' : '' ?>><?= e(__('restaurant_cr_production', 'Production')) ?></option>
            </select>
            <p class="field-hint"><?= e(__('restaurant_cr_env_hint', 'DoorDash decides the environment by the key you paste: a sandbox key only makes test deliveries. This label is what staff see.')) ?></p></div>
        <div class="field"><label class="field-label" for="d_dev"><?= e(__('restaurant_cr_dev_id', 'Developer ID')) ?></label>
            <input type="text" id="d_dev" name="developer_id" maxlength="100" value="<?= e((string) RestaurantAPI::setting('drive_developer_id', '')) ?>"></div>
        <div class="field"><label class="field-label" for="d_key"><?= e(__('restaurant_cr_key_id', 'Key ID')) ?></label>
            <input type="text" id="d_key" name="key_id" maxlength="100" value="<?= e((string) RestaurantAPI::setting('drive_key_id', '')) ?>"></div>
        <div class="field"><label class="field-label" for="d_sec"><?= e(__('restaurant_cr_signing_secret', 'Signing secret')) ?></label>
            <input type="password" id="d_sec" name="signing_secret" maxlength="200" placeholder="<?= e($hasKey ? __('restaurant_cr_keep', 'Set (leave empty to keep)') : '') ?>"></div>
        <label class="field-check rcu-check"><input type="checkbox" name="enabled" value="1" <?= RestaurantAPI::setting('drive_enabled', '0') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('restaurant_cr_enable', 'Offer DoorDash couriers on delivery orders')) ?></span></label>
        <div class="rcu-actions"><button class="btn btn-primary"><?= e(__('restaurant_ch_save', 'Save')) ?></button></div>
    </form>
    <?php if ($drive->enabled()): ?>
    <form method="post" class="rcu-actions" style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="_action" value="check">
        <button class="btn btn-sm"><?= e(__('restaurant_cr_check', 'Check the key')) ?></button></form>
    <?php endif; ?>
</section>

<section class="card">
    <header class="rcu-h"><h2>Uber Direct</h2>
        <span class="badge badge-<?= $uber->enabled() ? 'active' : 'inactive' ?>"><?= e($uber->enabled() ? ($uEnv === 'live' ? __('restaurant_cr_on_prod', 'On · production') : __('restaurant_cr_on_test', 'On · test')) : __('restaurant_ch_off', 'Off')) ?></span></header>
    <form method="post" class="rcu-form" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="_action" value="uber">
        <div class="field"><label class="field-label" for="u_env"><?= e(__('restaurant_cr_env', 'Environment')) ?></label>
            <select id="u_env" name="env">
                <option value="test" <?= $uEnv === 'test' ? 'selected' : '' ?>><?= e(__('restaurant_cr_uber_test', 'Test (test deliveries)')) ?></option>
                <option value="live" <?= $uEnv === 'live' ? 'selected' : '' ?>><?= e(__('restaurant_cr_production', 'Production')) ?></option>
            </select>
            <p class="field-hint"><?= e(__('restaurant_cr_uber_env_hint', 'Uber decides the environment by the credentials you paste: test credentials only make test deliveries. This label is what staff see.')) ?></p></div>
        <div class="field"><label class="field-label" for="u_cus"><?= e(__('restaurant_cr_uber_customer', 'Customer ID')) ?></label>
            <input type="text" id="u_cus" name="customer_id" maxlength="100" value="<?= e((string) RestaurantAPI::setting('uber_customer_id', '')) ?>">
            <p class="field-hint"><?= e(__('restaurant_cr_uber_customer_hint', 'In the Uber Direct dashboard, under Developer.')) ?></p></div>
        <div class="field"><label class="field-label" for="u_cid"><?= e(__('restaurant_cr_uber_client', 'Client ID')) ?></label>
            <input type="text" id="u_cid" name="client_id" maxlength="200" value="<?= e((string) RestaurantAPI::setting('uber_client_id', '')) ?>"></div>
        <div class="field"><label class="field-label" for="u_sec"><?= e(__('restaurant_cr_uber_secret', 'Client secret')) ?></label>
            <input type="password" id="u_sec" name="client_secret" maxlength="300" placeholder="<?= e($hasUSec ? __('restaurant_cr_keep', 'Set (leave empty to keep)') : '') ?>"></div>
        <div class="field"><label class="field-label" for="u_hk"><?= e(__('restaurant_cr_uber_hook_key', 'Webhook signing key')) ?></label>
            <input type="password" id="u_hk" name="webhook_key" maxlength="300" placeholder="<?= e($hasUKey ? __('restaurant_cr_keep', 'Set (leave empty to keep)') : '') ?>">
            <p class="field-hint"><?= e(__('restaurant_cr_uber_hook_key_hint', 'From the webhook in the Uber Direct dashboard. With it saved, webhooks without Uber\'s signature are refused.')) ?></p></div>
        <label class="field-check rcu-check"><input type="checkbox" name="enabled" value="1" <?= RestaurantAPI::setting('uber_enabled', '0') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('restaurant_cr_uber_enable', 'Offer Uber couriers on delivery orders')) ?></span></label>
        <div class="rcu-actions"><button class="btn btn-primary"><?= e(__('restaurant_ch_save', 'Save')) ?></button></div>
    </form>
    <?php if ($uber->enabled()): ?>
    <form method="post" class="rcu-actions" style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="_action" value="check_uber">
        <button class="btn btn-sm"><?= e(__('restaurant_cr_uber_check', 'Check the credentials')) ?></button></form>
    <?php endif; ?>
</section>

<section class="card">
    <header class="rcu-h"><h2><?= e(__('restaurant_cr_pickup', 'Pickup and requests')) ?></h2></header>
    <dl class="rc-dl">
        <div><dt><?= e(__('restaurant_cr_pickup_at', 'Couriers collect from')) ?></dt>
            <dd><?= e(trim($pickup['name'] . ' · ' . $pickup['address'], ' ·') ?: '—') ?>
                <a href="<?= e(plugin_url('restaurant', 'admin/settings.php')) ?>" class="text-sm"><?= e(__('restaurant_cr_edit_biz', 'Edit in Settings')) ?></a></dd></div>
    </dl>
    <form method="post" class="rcu-form">
        <?= csrf_field() ?><input type="hidden" name="_action" value="general">
        <div class="field"><label class="field-label" for="biz_phone"><?= e(__('restaurant_cr_biz_phone', 'Restaurant phone for couriers')) ?></label>
            <input type="tel" id="biz_phone" name="biz_phone" maxlength="40" value="<?= e((string) RestaurantAPI::setting('biz_phone', '')) ?>" placeholder="+1 555 010 0000">
            <p class="field-hint"><?= e(__('restaurant_cr_phone_hint', 'The courier and the provider\'s support call this number. Include the country code.')) ?></p></div>
        <label class="field-check rcu-check"><input type="checkbox" name="courier_auto" value="1" <?= RestaurantAPI::setting('courier_auto', '0') === '1' ? 'checked' : '' ?>>
            <span><?= e(__('restaurant_cr_auto', 'Request a courier automatically when an online delivery order is paid')) ?></span></label>
        <p class="field-hint"><?= e(__('restaurant_cr_auto_hint', 'Off: staff press Request courier on the order. Channel orders are never sent automatically, because the marketplace may bring its own courier.')) ?></p>
        <div class="rcu-actions"><button class="btn btn-primary"><?= e(__('restaurant_ch_save', 'Save')) ?></button></div>
    </form>
    <h3 class="rcu-sub"><?= e(__('restaurant_cr_webhook', 'Status webhook')) ?></h3>
    <?php if ($token !== ''): ?>
        <p class="text-sm" style="margin:0 0 2px;">DoorDash</p>
        <p class="text-sm" style="margin:0 0 6px;"><code class="rcu-code"><?= e($hookUrl('doordash_drive')) ?></code></p>
        <p class="text-sm" style="margin:0 0 2px;">Uber</p>
        <p class="text-sm" style="margin:0 0 6px;"><code class="rcu-code"><?= e($hookUrl('uber_direct')) ?></code></p>
    <?php endif; ?>
    <p class="field-hint"><?= e(__('restaurant_cr_webhook_hint2', 'Paste each address into that provider\'s portal (DoorDash Developer Portal, Webhooks; Uber Direct dashboard, Webhooks, delivery status). Each call only tells us which delivery changed; Kohevo then asks the provider for its real status.')) ?></p>
    <form method="post" <?= $token !== '' ? 'onsubmit="return confirm(this.dataset.confirm)"' : '' ?> data-confirm="<?= e(__('restaurant_cr_hook_confirm', 'Make a new address? The old one stops working at once.')) ?>">
        <?= csrf_field() ?><input type="hidden" name="_action" value="webhook_token">
        <button class="btn btn-sm"><?= e($token !== '' ? __('restaurant_cr_hook_rotate', 'Make a new address') : __('restaurant_cr_hook_make', 'Make the webhook address')) ?></button>
    </form>
</section>
</div>

<section class="card">
    <header class="rcu-h"><h2><?= e(__('restaurant_cr_jobs', 'Recent courier requests')) ?></h2></header>
    <?php if (!$jobs): ?><p class="text-sm" style="color:var(--muted);margin:0;"><?= e(__('restaurant_cr_no_jobs', 'None yet. Request one from a delivery order on Online orders.')) ?></p>
    <?php else: ?><div class="data-list" data-single-open><?php foreach ($jobs as $j):
        $v = RestaurantDelivery::view($j);
        $tone = in_array($j['status'], ['failed', 'cancelled'], true) ? 'danger' : ($j['status'] === 'delivered' ? 'success' : 'info');
        slate_data_row([
            'avatar' => mb_strtoupper(mb_substr($v['provider'], 0, 2)), 'avatar_color' => $tone,
            'title'  => '#' . ($j['order_no'] ?? $j['order_id']) . ' · ' . $v['provider'],
            'meta'   => implode(' · ', array_filter([$v['label'], date('M j, g:ia', strtotime((string)$j['created_at'])), $v['fee']])),
            'badge'  => [$v['label'], $tone === 'danger' ? 'warning' : ($tone === 'success' ? 'active' : 'info')],
            'detail' => array_values(array_filter([
                ['label' => __('restaurant_cr_our_id', 'Our delivery ID'), 'value' => (string)$j['external_id']],
                $v['reference'] !== '' ? ['label' => __('restaurant_cr_support_ref', 'Provider reference'), 'value' => $v['reference']] : null,
                $v['pickup_eta'] !== '' ? ['label' => __('restaurant_cr_pickup_eta', 'Pickup'), 'value' => $v['pickup_eta']] : null,
                $v['dropoff_eta'] !== '' ? ['label' => __('restaurant_cr_dropoff_eta', 'Drop-off'), 'value' => $v['dropoff_eta']] : null,
                $v['tracking_url'] !== '' ? ['label' => __('restaurant_cr_tracking', 'Tracking'), 'html' => '<a href="' . e($v['tracking_url']) . '" target="_blank" rel="noopener">' . e(__('restaurant_cr_track', 'Track')) . ' ↗</a>'] : null,
                $v['error'] !== '' ? ['label' => __('restaurant_ch_reason', 'Reason'), 'value' => $v['error']] : null,
            ])),
            'actions' => '<a class="btn btn-sm" href="' . e(plugin_url('restaurant', 'admin/order.php') . '?id=' . (int)$j['order_id']) . '">' . e(__('restaurant_pos_details', 'Order details')) . '</a>',
        ]);
    endforeach; ?></div><?php endif; ?>
</section>
<?php slate_data_list_script(); ?>

<style>
.rcu-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-4, 16px); }
.rcu-grid > .card { margin: 0 0 var(--space-4, 16px); }
.rcu-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.rcu-h h2 { margin: 0; font-size: 15px; }
.rcu-sub { margin: 16px 0 6px; font-size: 13.5px; }
.rcu-form .field { margin-bottom: 10px; }
.rcu-check { display: flex; align-items: center; gap: 8px; margin: 4px 0 8px; }
.rcu-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.rcu-code { word-break: break-all; font-size: 12px; }
.rc-dl { display: grid; gap: 8px; margin: 0 0 12px; }
.rc-dl dt { font-size: 12px; font-weight: 600; color: var(--muted); }
.rc-dl dd { margin: 2px 0 0; }
@media (max-width: 860px) { .rcu-grid { grid-template-columns: minmax(0, 1fr); } }
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
