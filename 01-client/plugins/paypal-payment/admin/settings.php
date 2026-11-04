<?php
/**
 * PayPal Payment — settings.
 *
 * Credentials come from a PayPal REST app (developer.paypal.com → Apps &
 * Credentials), one set per mode. The client secret is write-only: it is
 * stored as an enc:v1 envelope, never rendered back, and a blank field keeps
 * the stored one. New credentials are checked with PayPal (an OAuth token
 * request) before they are saved; saving fails closed without APP_SECRET.
 *
 * Which modules offer PayPal is decided on Payments → Gateways.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/PayPalPaymentAPI.php';

Auth::require();
Auth::requirePerm('paypal.manage_settings');

$flash = null;
$P = 'paypal-payment.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'test_connection') {
        try {
            PayPalPaymentAPI::accessToken(true);
            Database::setSetting($P . 'verified_at', slate_db_now());
            Database::setSetting($P . 'verified_mode', PayPalPaymentAPI::mode());
            $flash = ['type' => 'success', 'msg' => __('pp_connection_ok', 'PayPal accepted these credentials.')];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    } elseif (($_POST['_action'] ?? '') === 'clear_keys') {
        foreach (['sandbox', 'live'] as $m) {
            foreach (['client_id', 'client_secret', 'webhook_id', 'product_id'] as $k) {
                Database::setSetting($P . $m . '_' . $k, '');
            }
        }
        Database::setSetting($P . 'verified_at', '');
        PayPalPaymentAPI::forgetToken();
        AuditLog::record('paypal.keys_cleared');
        $flash = ['type' => 'success', 'msg' => __('pp_keys_cleared', 'PayPal credentials removed. PayPal is no longer offered at checkout.')];
    } else {
        $mode      = ($_POST['mode'] ?? '') === 'live' ? 'live' : 'sandbox';
        $clientId  = trim((string) ($_POST['client_id'] ?? ''));
        $secretIn  = trim((string) ($_POST['client_secret'] ?? ''));
        $webhookId = trim((string) ($_POST['webhook_id'] ?? ''));

        $storedSecretRaw = (string) Database::setting($P . $mode . '_client_secret');
        $storedSecret    = $storedSecretRaw === '' ? '' : (string) (slate_decrypt_secret($storedSecretRaw) ?? '');
        $storedClientId  = (string) Database::setting($P . $mode . '_client_id');
        $secret          = $secretIn !== '' ? $secretIn : $storedSecret;
        $changed         = $secretIn !== '' || $clientId !== $storedClientId;

        $error = null;
        if ($clientId !== '' && !preg_match('/^[A-Za-z0-9_-]{20,128}$/', $clientId)) {
            $error = __('pp_client_id_format', 'That does not look like a PayPal client id.');
        } elseif ($webhookId !== '' && !preg_match('/^[A-Za-z0-9-]{6,64}$/', $webhookId)) {
            $error = __('pp_webhook_id_format', 'That does not look like a PayPal webhook id.');
        } elseif ($secretIn !== '' && (!function_exists('slate_encrypt_secret') || !slate_has_app_secret())) {
            $error = __('pp_encryption_unavailable', 'Cannot save the secret: APP_SECRET is not configured, and storing it in plain text is disabled.');
        } elseif ($changed && $clientId !== '' && $secret !== '') {
            // Check the pair with PayPal before saving it, in the chosen mode.
            try {
                PayPalPaymentAPI::accessToken(true, $clientId, $secret, $mode);
            } catch (\Throwable $e) {
                $error = sprintf(__('pp_credentials_rejected', 'PayPal rejected these %s credentials: %s'), $mode, $e->getMessage());
            }
        }

        if ($error !== null) {
            $flash = ['type' => 'error', 'msg' => $error];
        } else {
            Database::setSetting($P . 'mode', $mode);
            Database::setSetting($P . $mode . '_client_id', $clientId);
            Database::setSetting($P . $mode . '_webhook_id', $webhookId);
            if ($secretIn !== '') {
                Database::setSetting($P . $mode . '_client_secret', slate_encrypt_secret($secretIn));
            }
            if ($changed) {
                PayPalPaymentAPI::forgetToken();
                // A different app means a different catalog: plans made for
                // the old one cannot be reused.
                Database::setSetting($P . $mode . '_product_id', '');
                Database::query("DELETE FROM settings WHERE tenant_id = ? AND setting_key LIKE ?",
                    [current_tenant_id(), $P . 'plan.' . $mode . '.%']);
                if ($clientId !== '' && $secret !== '') {
                    Database::setSetting($P . 'verified_at', slate_db_now());
                    Database::setSetting($P . 'verified_mode', $mode);
                }
            }
            AuditLog::record('paypal.settings_changed', '', ['mode' => $mode, 'credentials_changed' => $changed]);
            $flash = ['type' => 'success', 'msg' => __('settings_saved', 'Settings saved.')];
        }
    }
}

$mode       = PayPalPaymentAPI::mode();
$clientId   = PayPalPaymentAPI::clientId();
$hasSecret  = (string) Database::setting($P . $mode . '_client_secret') !== '';
$webhookId  = PayPalPaymentAPI::webhookId();
$configured = PayPalPaymentAPI::isConfigured();
$verifiedAt = (string) (Database::setting($P . 'verified_at') ?? '');
$webhookUrl = SLATE_URL . '/plugins/paypal-payment/public/webhook.php';
$wh         = PayPalPaymentAPI::webhookHealth();
$dashUrl    = $mode === 'live' ? 'https://developer.paypal.com/dashboard/applications/live'
                               : 'https://developer.paypal.com/dashboard/applications/sandbox';

$pageTitle  = __('pp_settings_title', 'PayPal');
$currentNav = 'paypal-settings';
require SLATE_ROOT . '/admin/partials/header.php';

$ago = static function (string $ts): string {
    if ($ts === '') return '';
    $d = slate_db_time() - (int) strtotime($ts);
    if ($d < 60)    return __('pp_ago_just_now', 'just now');
    if ($d < 3600)  return sprintf(__('pp_ago_minutes', '%d min ago'), intdiv($d, 60));
    if ($d < 86400) return sprintf(__('pp_ago_hours', '%d hr ago'), intdiv($d, 3600));
    return sprintf(__('pp_ago_days', '%d days ago'), intdiv($d, 86400));
};
$whTone = ['ok' => 'active', 'rejecting' => 'danger', 'never' => 'warning', 'no_webhook_id' => 'danger'][$wh['state']];
$whHead = [
    'ok'            => __('pp_wh_state_ok', 'Receiving events'),
    'rejecting'     => __('pp_wh_state_rejecting', 'Deliveries are being rejected'),
    'never'         => __('pp_wh_state_never', 'No event has ever arrived'),
    'no_webhook_id' => __('pp_wh_state_no_id', 'No webhook id set'),
][$wh['state']];
$whWhy = [
    'ok'            => __('pp_wh_why_ok', 'PayPal is reaching this site and confirms each delivery.'),
    'rejecting'     => __('pp_wh_why_rejecting', 'PayPal is reaching this site, but it does not confirm the deliveries — the webhook id here is probably from another app or mode.'),
    'never'         => __('pp_wh_why_never', 'Either no webhook in your PayPal app points at this URL, or PayPal cannot reach it. Payments still settle when the customer comes back, but one who closes the tab will not be recorded.'),
    'no_webhook_id' => __('pp_wh_why_no_id', 'Every delivery is rejected without one. Add the webhook in your PayPal app, then paste its id below.'),
][$wh['state']];
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('payments_gateways', 'Payment gateways'), 'href' => SLATE_URL . '/admin/payment-gateways.php'],
    ['label' => 'PayPal'],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('pp_settings_title', 'PayPal')) ?></h1>
        <p class="page-header-sub"><?= e(__('pp_settings_sub', 'Connect a PayPal REST app so customers can pay with PayPal, or by card through PayPal.')) ?></p>
    </div>
    <div class="toolbar">
        <a href="<?= e(SLATE_URL . '/admin/payment-gateways.php') ?>" class="btn"><?= e(__('payments_gateways', 'Payment gateways')) ?></a>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1rem;">
    <div class="flex" style="gap:14px;align-items:center;flex-wrap:wrap;">
        <?php if ($configured): ?>
            <span class="badge badge-success">✓ <?= e(__('pp_connected', 'Connected')) ?></span>
            <div class="text-sm">
                <strong><?= e($mode === 'live' ? __('pp_mode_live', 'Live') : __('pp_mode_sandbox', 'Sandbox')) ?></strong>
                <?php if ($verifiedAt !== ''): ?><span class="text-muted"> · <?= e(sprintf(__('pp_verified_ago', 'checked %s'), $ago($verifiedAt))) ?></span><?php endif; ?>
            </div>
            <div style="flex:1;"></div>
            <form method="post" style="margin:0;">
                <?= csrf_field() ?><input type="hidden" name="_action" value="test_connection">
                <button class="btn btn-sm"><?= e(__('pp_retest', 'Re-test')) ?></button>
            </form>
            <form method="post" style="margin:0;" onsubmit="return confirm('<?= e(__('pp_confirm_clear', 'Remove all PayPal credentials? PayPal will no longer be offered at checkout.')) ?>');">
                <?= csrf_field() ?><input type="hidden" name="_action" value="clear_keys">
                <button class="btn btn-sm btn-ghost"><?= e(__('pp_disconnect', 'Disconnect')) ?></button>
            </form>
        <?php else: ?>
            <span class="badge badge-warning"><?= e(__('pp_not_connected', 'Not connected')) ?></span>
            <span class="text-muted text-sm"><?= e(__('pp_paste_prompt', 'Paste your PayPal app\'s client id and secret below to offer PayPal at checkout.')) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="split-layout">
    <div>
        <div class="card">
            <div class="card-header"><h2><?= e(__('pp_step1_title', '1 · Get your app credentials')) ?></h2></div>
            <p class="text-muted text-sm" style="margin:0 0 .75rem;"><?= e(__('pp_step1_desc', 'In the PayPal developer dashboard, open Apps & Credentials, pick Sandbox or Live, and create (or open) a REST app. Copy its client id and secret.')) ?></p>
            <a href="<?= e($dashUrl) ?>" target="_blank" rel="noopener" class="btn btn-primary"><?= e(__('pp_open_dashboard', 'Open PayPal Apps & Credentials ↗')) ?></a>
        </div>

        <form method="post" class="card" style="margin-top:1rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save">
            <div class="card-header"><h2><?= e(__('pp_step2_title', '2 · Paste them here')) ?></h2></div>

            <div class="field">
                <label class="field-label" for="mode"><?= e(__('pp_mode_label', 'Mode')) ?></label>
                <select id="mode" name="mode">
                    <option value="sandbox" <?= $mode === 'sandbox' ? 'selected' : '' ?>><?= e(__('pp_mode_sandbox', 'Sandbox')) ?></option>
                    <option value="live" <?= $mode === 'live' ? 'selected' : '' ?>><?= e(__('pp_mode_live', 'Live')) ?></option>
                </select>
                <div class="field-hint"><?= e(__('pp_mode_hint', 'Sandbox and live credentials are kept separately; switching back keeps the other set.')) ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="client_id"><?= e(__('pp_client_id', 'Client id')) ?></label>
                <input type="text" id="client_id" name="client_id" value="<?= e($clientId) ?>" autocomplete="off" spellcheck="false"
                       style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;">
            </div>

            <div class="field">
                <label class="field-label" for="client_secret">
                    <?= e(__('pp_client_secret', 'Secret')) ?>
                    <?php if ($hasSecret): ?><span class="badge badge-success" style="font-size:10px;margin-left:6px;">✓ <?= e(__('configured', 'Configured')) ?></span><?php endif; ?>
                </label>
                <input type="password" id="client_secret" name="client_secret" value="" autocomplete="new-password" spellcheck="false"
                       placeholder="<?= $hasSecret ? '••••••••  (' . e(__('leave_blank_to_keep', 'Leave blank to keep existing secret')) . ')' : '' ?>"
                       style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;">
                <div class="field-hint"><?= e(__('pp_secret_hint', 'Stored encrypted and never shown again. Leave blank to keep the saved one.')) ?></div>
            </div>

            <div class="field">
                <label class="field-label" for="webhook_id"><?= e(__('pp_webhook_id', 'Webhook id')) ?></label>
                <input type="text" id="webhook_id" name="webhook_id" value="<?= e($webhookId) ?>" autocomplete="off" spellcheck="false"
                       style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;">
                <div class="field-hint"><?= e(__('pp_webhook_id_hint', 'From step 3. PayPal uses it to prove each delivery is genuine.')) ?></div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary"><?= e(__('save', 'Save')) ?></button>
            </div>
        </form>
    </div>

    <div>
        <div class="card" style="margin-bottom:1rem;">
            <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                <h2><?= e(__('pp_webhook_health_title', 'Webhook health')) ?></h2>
                <span class="badge badge-<?= e($whTone) ?>"><?= e($whHead) ?></span>
            </div>
            <p class="text-muted text-sm" style="margin:0 0 .75rem;"><?= e($whWhy) ?></p>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= e(__('pp_last_accepted', 'Last accepted')) ?></span>
                    <span class="kv-value"><?php if ($wh['ok_at'] !== ''): ?><?= e($ago($wh['ok_at'])) ?><?php if ($wh['ok_type'] !== ''): ?><br><span class="text-xs text-muted"><?= e($wh['ok_type']) ?></span><?php endif; ?><?php else: ?>—<?php endif; ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('pp_last_rejected', 'Last rejected')) ?></span>
                    <span class="kv-value"><?php if ($wh['fail_at'] !== ''): ?><?= e($ago($wh['fail_at'])) ?><?php if ($wh['fail_reason'] !== ''): ?><br><span class="text-xs text-muted"><?= e(str_replace('_', ' ', $wh['fail_reason'])) ?></span><?php endif; ?><?php else: ?>—<?php endif; ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= e(__('pp_accepted_rejected_label', 'Accepted / rejected')) ?></span>
                    <span class="kv-value"><?= (int) $wh['ok_count'] ?> / <?= (int) $wh['fail_count'] ?></span></li>
            </ul>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= e(__('pp_step3_title', '3 · Add the webhook')) ?></h2></div>
            <p class="text-muted text-sm"><?= e(__('pp_step3_desc', 'In the same PayPal app, under Webhooks, add this URL and select the events below. Then paste the webhook id PayPal shows into step 2.')) ?></p>
            <code style="display:block;padding:10px;background:var(--surface-sunken);border-radius:6px;word-break:break-all;font-size:12px;"><?= e($webhookUrl) ?></code>
            <p class="text-sm" style="margin:.75rem 0 0;"><code>CHECKOUT.ORDER.APPROVED</code>, <code>PAYMENT.CAPTURE.COMPLETED</code>, <code>PAYMENT.CAPTURE.DENIED</code>, <code>PAYMENT.CAPTURE.REFUNDED</code>, <code>BILLING.SUBSCRIPTION.ACTIVATED</code>, <code>PAYMENT.SALE.COMPLETED</code></p>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
