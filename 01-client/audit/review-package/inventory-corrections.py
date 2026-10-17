"""Apply the 2026-10-17 source-verified corrections to the settings/widget inventory.

Each row that did not trace to source was either corrected to the real key
(old name kept in Notes), removed to kohevo-inventory-removed-rows.csv with a
reason, or kept and explicitly marked not implemented. Re-running is a no-op:
it reads the pre-correction file from git when the markers are present.
Usage: python3 -I inventory-corrections.py <01-client dir>
"""
import csv, sys, os

root = sys.argv[1]
inv = os.path.join(root, 'audit/kohevo-settings-widget-inventory.csv')
removed_out = os.path.join(root, 'audit/kohevo-inventory-removed-rows.csv')

# line -> (new key, new storage or None, note)
CORRECT = {
 3: ('site_tagline', None, 'Site tagline (Settings > General)'),
 4: ('APP_URL', 'config (.env APP_URL -> SLATE_URL)', 'Installation base URL; config, not a database setting'),
 5: ('site_admin_email', None, 'Administrative notification address (Settings > General)'),
 19: ('brand_logo_path', None, 'Uploaded logo path (also brand_logo_dark_path)'),
 20: ('brand_favicon_path', None, 'Uploaded favicon path'),
 21: ('session_timeout_minutes', None, 'Admin session inactivity timeout in minutes'),
 23: ('max_login_attempts', None, 'Failed logins before lockout'),
 24: ('lockout_minutes', None, 'Lockout duration in minutes'),
 25: ('smtp_auth_type', None, 'SMTP authentication type (password or OAuth). There is no mail-driver switch: outbound mail is SMTP only'),
 29: ('smtp_user', None, 'SMTP username'),
 30: ('smtp_pass', None, 'Encrypted with slate_encrypt_secret(); explicit clear_smtp_pass checkbox'),
 34: ('smtp_oauth_refresh_token', None, 'OAuth refresh token (with smtp_oauth_access_token / smtp_oauth_expires); stored encrypted'),
 39: ('google_client_id', None, 'Google Drive OAuth client ID (backups.google_client_id)'),
 40: ('google_client_secret', None, 'Write-only; encrypted; save skips the secret and flashes an error if encryption fails'),
 41: ('google_refresh_token', None, 'Google Drive OAuth refresh token (backups.google_refresh_token)'),
 46: ('buffer_min', 'booking services table column (per service)', 'Per-service buffer in minutes, not a global setting (plugins/booking/install.sql)'),
 52: ('google_enabled', None, 'Google Calendar 2-way sync master toggle (booking.google_enabled)'),
 58: ('twilio_sms_from', None, 'Twilio SMS sender (booking.twilio_sms_from; also twilio_whatsapp_from)'),
 59: ('program_plan_id', None, 'Single membership plan tied to the coaching programme (coaching.program_plan_id), not a multi-plan gate'),
 65: ('coaching.settings_updated', 'AuditLog', 'Audit event written by the coaching settings save handler (not a setting)'),
 67: ('sp_captcha_site_key', 'forms_definitions.settings_json spam.captcha_site_key', 'Public CAPTCHA site key, per form'),
 70: ('sp_rate_limit', 'forms_definitions.settings_json spam.rate_limit', 'Submissions per window per IP (window = sp_rate_window seconds), per form'),
 71: ('notify_email', 'forms_definitions.notify_email column', 'Admin notification address, per form'),
 72: ('webhook_urls', 'forms_webhooks table', 'One URL per line in the editor; stored as forms_webhooks rows'),
 75: ('remote_license_cache', 'remote_license_cache table', 'Signed Ed25519 licence state; the only entitlement authority (Phase 13)'),
 76: ('LICENSE_SERVER_URL', 'config (.env)', 'Central licensing server URL; config, not a database setting'),
 77: ('OFFLINE_TOLERANCE_SECONDS', 'const (CommercialLicenseWindow)', '7 days; a code constant, not configurable'),
 # 79 (ai_provider) was first mapped to ai_base_url, which already had its own row; it is removed instead (see the removed-rows file).
 84: ('ALLOWED_MIMES', 'const (Services/Media/Media.php)', 'Upload MIME allow-list; a code constant, not configurable'),
 85: ('media_max_upload_mb', None, 'Core setting (Settings > Media), not a media-library plugin setting'),
 86: ('media_usage', 'media_usage table', 'Usage tracking is always on; there is no toggle'),
 87: ('media_thumb_w', None, 'Variant sizes: media_thumb_w/h, media_medium_w/h, media_large_w/h (core settings)'),
 92: ('enable_attendance', None, 'Membership attendance/check-in toggle (membership.enable_attendance)'),
 98: ('mode', None, 'stripe-payment.mode: test or live'),
 103: ('method_klarna', None, 'One toggle per method: method_us_bank_account, method_klarna, method_cashapp, method_amazon_pay, method_bancontact'),
 110: ('studiobuilder_tokens', 'studiobuilder_tokens table', 'Design tokens written only by StudioThemeService; no preset selector'),
 111: ('core.heading', None, 'Heading block'),
 112: ('core.text', None, 'Text block (core.rich_text also exists)'),
 113: ('core.button', None, 'Button block'),
 114: ('core.image', None, 'Image block'),
 115: ('layout.grid', None, 'Grid layout (layout.flex, layout.container, layout.section also exist); there is no "columns" block'),
 116: ('core.card', None, 'Card block'),
 117: ('core.hero', None, 'Hero block'),
 118: ('core.divider', None, 'Divider block'),
 119: ('core.spacer', None, 'Spacer block'),
 120: ('core.video', None, 'Video block'),
 121: ('forms.embed', None, 'Embeds a Forms plugin form (forms.form_card also exists)'),
 122: ('booking.embed', None, 'Booking widget embed (booking.services also exists)'),
 123: ('membership.plans', None, 'Membership plans block'),
 125: ('payment.button', None, 'Signed Stripe checkout button; payment.link also exists'),
 126: ('portfolio.project_card', None, 'Plugin studio-freelancer-portfolio registers several portfolio.* blocks (project_card, service_card, skill_grid, nav_header, footer, ...)'),
 127: ('acme.testimonial', None, 'Example Widget SDK block from plugin studio-testimonial-widget'),
 134: ('checkboxes', None, 'Multiple-choice checkbox group (FormsAPI::SUPPORTED_TYPES)'),
}
REMOVE = {
 6: 'No user self-registration role setting exists',
 7: 'No self-registration toggle exists',
 8: 'No registration-approval setting exists',
 14: 'Only brand_accent_color exists; there is no primary-colour setting',
 15: 'Only brand_accent_color exists; there is no secondary-colour setting',
 22: 'MFA exists per user (src/Services/Auth/Mfa.php) but there is no platform-wide enforcement setting',
 36: 'Backups are cron-driven; there is no schedule setting',
 37: 'Backups are cron-driven; there is no schedule-time setting',
 38: 'Google Drive is the only destination; there is no destination setting',
 42: 'Backup contents are not configurable',
 43: 'Backup contents are not configurable',
 45: 'No global slot-interval setting exists',
 47: 'No booking minimum-notice setting; the real keys are cancel_min_notice_hours and reschedule_min_notice_hours (different meaning)',
 48: 'No max-future-days setting exists',
 50: 'No deposit setting exists',
 51: 'No deposit setting exists',
 55: 'Not a setting; Google OAuth tokens were not verified as a settings key',
 61: 'No macro-percentage setting exists',
 62: 'No macro-percentage setting exists',
 63: 'No macro-percentage setting exists',
 64: 'No diary-visibility setting exists',
 69: 'The honeypot is always on; there is no toggle',
 78: 'No MCP master switch; plugin activation is the switch',
 79: 'No provider setting exists; the client is configured by ai_base_url + ai_model (already listed)',
 83: 'Tool access is governed by MCP token scopes, not a setting',
 89: 'No grace-period setting exists',
 91: 'No Stripe-sync toggle exists',
 93: 'No wallet/ledger toggle exists',
 95: 'Language configuration was not verified as a settings key',
 96: 'No crawler-protection setting exists',
 97: 'No URL-prefix-mode setting exists',
 104: 'No Studio site-title setting exists (core site_name is used)',
 105: 'No custom head-code setting exists',
 106: 'No custom footer-code setting exists',
 107: 'No tracking head-code setting exists',
 108: 'No tracking body-code setting exists',
 109: 'No global CSS setting exists',
 124: 'No coaching feed block exists',
}
NOT_IMPLEMENTED = {
 73: 'Not implemented: the Forms payment field is deferred (documented in plugins/forms/README.md); Studio payment.button is the shipped alternative',
}

rows = list(csv.reader(open(inv, encoding='utf-8', newline='')))
header, data = rows[0], rows[1:]
idx = {h: i for i, h in enumerate(header)}
assert len(header) == 12
if any('[corrected 2026-10-17' in c for r in data for c in r):
    sys.exit('already corrected')
keep, removed = [header], [['original_csv_line', 'Domain', 'Component', 'Setting_Or_Widget', 'reason']]
for line, r in enumerate(data, start=2):
    if line in REMOVE:
        removed.append([line, r[idx['Domain']], r[idx['Component']], r[idx['Setting_Or_Widget']], REMOVE[line]])
        continue
    if line in CORRECT:
        new, storage, note = CORRECT[line]
        old = r[idx['Setting_Or_Widget']]
        r[idx['Setting_Or_Widget']] = new
        if storage: r[idx['Storage_Key']] = storage
        if r[idx['Domain']] == 'Widget: Studio': r[idx['Storage_Key']] = 'studiobuilder_revisions document JSON'
        if r[idx['Domain']] == 'Field: Forms': r[idx['Storage_Key']] = 'forms_definitions.fields_json'
        r[idx['Notes']] = f"{note} [corrected 2026-10-17: was {old}]"
    if line in NOT_IMPLEMENTED:
        r[idx['Disposition']] = 'Not implemented'
        r[idx['Notes']] = NOT_IMPLEMENTED[line] + ' [corrected 2026-10-17]'
    keep.append(r)
with open(inv, 'w', encoding='utf-8', newline='') as f:
    csv.writer(f, lineterminator='\n').writerows(keep)
with open(removed_out, 'w', encoding='utf-8', newline='') as f:
    csv.writer(f, lineterminator='\n').writerows(removed)
print('kept', len(keep) - 1, 'removed', len(removed) - 1, 'corrected', len(CORRECT), 'not-implemented', len(NOT_IMPLEMENTED))
