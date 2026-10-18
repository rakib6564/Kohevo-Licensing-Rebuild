#!/usr/bin/env python3
"""Aggregate scanner output + manual dynamic-key expansions into per-key rows."""
import csv, json, os, re, sys, collections

S = os.path.dirname(os.path.abspath(__file__))
WT = os.path.join(os.path.dirname(S), 'wt-final')
TREES = {'client': '01-client', 'licensing': '02-licensing'}

SP = ['test', 'live']
STRIPE_ALL = ['mode', 'publishable_key', 'secret_key', 'webhook_secret', 'allowed_countries', 'show_hosted',
              'show_embedded', 'booking_embedded', 'wallet_apple_pay', 'wallet_google_pay', 'wallet_link',
              'method_us_bank_account', 'method_klarna', 'method_cashapp', 'method_amazon_pay', 'method_bancontact']
SMTP_GET = ['host', 'port', 'user', 'pass', 'encryption', 'from_email', 'from_name', 'auth_type', 'oauth_provider',
            'oauth_client_id', 'oauth_client_secret', 'oauth_email', 'oauth_refresh_token']
REPAIR = ['site_name', 'default_language', 'brand_sublabel', 'brand_accent_color', 'brand_logo_path',
          'brand_login_image_path', 'brand_login_tagline', 'business_name', 'business_email', 'business_phone',
          'business_address', 'business_hours', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_encryption',
          'smtp_from_name', 'smtp_from_email']
# (file, anchor substring, kind, [keys], via)
MANUAL = [
    ('src/Services/Notifications/Mailer.php', "$get = static fn(string $k)", 'read', ['smtp_' + k for k in SMTP_GET] + ['smtp_backup_' + k for k in SMTP_GET], 'Mailer::buildCfg $get (prefix smtp_ / smtp_backup_)'),
    ('src/Services/Content/TenantBranding.php', "\\Database::setting($settingKey)", 'read', ['brand_accent_color', 'brand_text_color', 'brand_canvas_color', 'brand_heading_font', 'brand_body_font'], 'TenantBranding::KEYS loop'),
    ('admin/settings.php', "Database::setSetting($k, '');", 'write', ['smtp_oauth_refresh_token', 'smtp_oauth_access_token', 'smtp_oauth_expires'], 'oauth_disconnect loop'),
    ('admin/repair-settings.php', "Database::setSetting($key, $default);", 'write', REPAIR, 'repair-settings $KNOWN reset'),
    ('admin/repair-settings.php', "$val = Database::setting($key);", 'read', REPAIR, 'repair-settings $KNOWN scan'),
    ('plugins/stripe-payment/admin/settings.php', "Database::setSetting('stripe-payment.' . $k, '');", 'write', ['stripe-payment.' + k for k in ['mode', 'publishable_key', 'secret_key', 'webhook_secret', 'verified_at', 'verified_account', 'verified_mode'] + [m + '_' + x for m in SP for x in ['publishable_key', 'secret_key', 'webhook_secret']]], 'clear_keys loop'),
    ('plugins/stripe-payment/admin/settings.php', "Database::setSetting($k, (string)$v);", 'write', ['stripe-payment.' + k for k in STRIPE_ALL], '$fields save loop'),
    ('plugins/stripe-payment/admin/settings.php', "_publishable_key', $newPub);", 'write', ['stripe-payment.%s_publishable_key' % m for m in SP], 'mode-prefixed slot'),
    ('plugins/stripe-payment/admin/settings.php', "_secret_key',      (string)$secretEnc);", 'write', ['stripe-payment.%s_secret_key' % m for m in SP], 'mode-prefixed slot'),
    ('plugins/stripe-payment/admin/settings.php', "_webhook_secret',  (string)$webhookEnc);", 'write', ['stripe-payment.%s_webhook_secret' % m for m in SP], 'mode-prefixed slot'),
    ('plugins/stripe-payment/admin/settings.php', "$storedSecretKey = (string)(Database::setting('stripe-payment.' . $newMode", 'read', ['stripe-payment.%s_secret_key' % m for m in SP], 'mode-prefixed read'),
    ('plugins/stripe-payment/admin/settings.php', "$storedWebhookSec = (string)(Database::setting('stripe-payment.' . $newMode", 'read', ['stripe-payment.%s_webhook_secret' % m for m in SP], 'mode-prefixed read'),
    ('plugins/stripe-payment/StripePaymentAPI.php', "$modal  = (string) Database::setting('stripe-payment.' . $prefix", 'read', ['stripe-payment.%s_%s' % (m, x) for m in SP for x in ['publishable_key', 'secret_key', 'webhook_secret']], 'keyByMode'),
    ('plugins/stripe-payment/StripePaymentAPI.php', "$raw = $modal !== '' ? $modal : (string) Database::setting('stripe-payment.' . $name);", 'read', ['stripe-payment.publishable_key', 'stripe-payment.secret_key', 'stripe-payment.webhook_secret'], 'keyByMode legacy fallback'),
    ('plugins/stripe-payment/StripePaymentAPI.php', "$n   = (int) Database::setting($pre . '_count');", 'read', ['stripe-payment.wh_ok_count', 'stripe-payment.wh_fail_count'], 'recordWebhookHealth'),
    ('plugins/stripe-payment/StripePaymentAPI.php', "Database::setSetting($pre . '_count'", 'write', ['stripe-payment.wh_ok_count', 'stripe-payment.wh_fail_count'], 'recordWebhookHealth'),
    ('plugins/stripe-payment/StripePaymentAPI.php', "Database::setSetting($pre . '_at'", 'write', ['stripe-payment.wh_ok_at', 'stripe-payment.wh_fail_at'], 'recordWebhookHealth'),
    ('plugins/backups/admin/settings.php', "Database::setSetting($k, $envelope);", 'write', ['backups.google_client_secret'], 'sealed secrets loop'),
    ('plugins/backups/GoogleDriveClient.php', "Database::setSetting('backups.' . $k, null);", 'write', ['backups.google_access_token', 'backups.google_refresh_token', 'backups.google_token_expires_at', 'backups.google_connected_email', 'backups.google_folder_id'], 'disconnect loop (null)'),
    ('plugins/multilang-translate/admin/api.php', "if ($plugin) $plugin->setSetting($f, $val);", 'write', ['multilang-translate.switcher_' + k for k in ['enabled', 'style', 'position', 'bg', 'fg', 'accent']], 'save_switcher_settings loop'),
    ('plugins/booking/Booking.php', "$this->setCapabilityEnabled((string)$capKey, $enabled);", 'write', ['booking.cap.' + c for c in ['client_messaging', 'service_rules', 'slot_restrictions', 'custom_reminders']], 'booking_settings_save -> Plugin::setCapabilityEnabled'),
    ('admin/plugins.php', "PluginLoader::setCapabilityEnabled($slug, (string)$capKey, $isEnabled);", 'write', ['booking.cap.' + c for c in ['client_messaging', 'service_rules', 'slot_restrictions', 'custom_reminders']], 'plugins page -> PluginLoader::setCapabilityEnabled'),
    ('includes/Plugin.php', "$val = $this->setting('cap.' . $cap);", 'read', ['booking.cap.' + c for c in ['client_messaging', 'service_rules', 'slot_restrictions', 'custom_reminders']], 'Plugin::isCapabilityEnabled'),
    ('src/Kernel/Module/PluginLoader.php', "$val = \\Database::setting($slug . '.cap.' . $cap);", 'read', ['booking.cap.' + c for c in ['client_messaging', 'service_rules', 'slot_restrictions', 'custom_reminders']], 'PluginLoader::isCapabilityEnabled (manifest fallback)'),
    ('plugins/forms/FormsAPI.php', "if ((string) Database::setting($flagKey, $tid) === '1') return 0;", 'read', ['forms.contacts_backfilled'], 'contacts backfill flag'),
    ('plugins/forms/FormsAPI.php', "Database::setSetting($flagKey, '1', $tid);", 'write', ['forms.contacts_backfilled'], 'contacts backfill flag'),
    ('plugins/mcp-gateway/McpGatewayAPI.php', "$configured = Database::setting($settingKey);", 'read', ['mcp-gateway.studio_rate_limit_write_per_min', 'mcp-gateway.studio_rate_limit_render_per_min'], 'withinClassRateLimit($settingKey from StudioBuilderMcpHandler)'),
    ('src/Module/StudioBuilder/Http/StudioCodePolicy.php', "$raw = self::setting($key, $tenantId);", 'read', ['studio_code_head_snippet', 'studio_code_footer_snippet'], 'StudioCodePolicy::snippet()'),
]
DYNAMIC = [  # patterns that cannot be expanded to a finite key list
    ('entitlement.<feature>.enabled', 'src/Services/Licensing/EntitlementService.php', '"entitlement.$featureKey.enabled"', 'read (no production writer)'),
    ('studio_page_meta_<pageId>', 'src/Module/StudioBuilder/Meta/PageMeta.php', 'self::SETTING_PREFIX . $pageId', 'read+write'),
    ('<any key> (MCP slate_settings_get/set)', 'plugins/mcp-gateway/CoreMcpTools.php', 'Database::setSetting($key', 'read+write'),
    ('<slug>.cap.<capability> (generic)', 'src/Kernel/Module/PluginLoader.php', ".cap.' . $cap", 'read+write'),
    ('<slug>.<key> (Plugin::setting/setSetting)', 'includes/Plugin.php', "$this->slug . '.' . $key", 'read+write'),
]

def find_line(tree, rel, anchor):
    p = os.path.join(WT, TREES[tree], rel)
    if not os.path.exists(p):
        return None
    for i, l in enumerate(open(p, errors='replace')):
        if anchor in l:
            return i + 1
    return None

data = {}
for tree, js in (('client', 'client.json'), ('licensing', 'lic.json')):
    d = json.load(open(os.path.join(S, js)))
    recs = d['recs']
    for f, anchor, kind, keys, via in MANUAL:
        ln = find_line(tree, f, anchor)
        if ln is None:
            continue
        for k in keys:
            recs.append(dict(key=k, kind=kind, file=f, line=ln, text=anchor, via='manual:' + via))
    dyn = []
    for pat, f, anchor, kind in DYNAMIC:
        ln = find_line(tree, f, anchor)
        if ln:
            dyn.append(dict(pattern=pat, file=f, line=ln, kind=kind))
    data[tree] = dict(recs=recs, dyn=dyn, raw_dyn=d['dyn'], files=d['files'])
json.dump(data, open(os.path.join(S, 'merged.json'), 'w'), indent=1)
for t in data:
    print(t, 'records', len(data[t]['recs']), 'keys', len({r['key'] for r in data[t]['recs']}), 'dyn patterns', len(data[t]['dyn']))
