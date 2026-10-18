#!/usr/bin/env python3
"""Generate settings-full-inventory.csv + stats json from merged.json."""
import csv, json, os, re, collections

S = os.path.dirname(os.path.abspath(__file__))
WT = os.path.join(os.path.dirname(S), 'wt-final')
data = json.load(open(os.path.join(S, 'merged.json')))

ADMIN_PAGE = re.compile(r'^(admin/|plugins/[^/]+/admin/)')
SYSTEM_HINT = ('bin/',)

PERM = {
    'admin/settings.php': ('settings.view page + settings.edit (or super admin) for POST', 'csrf_verify()'),
    'admin/oauth_callback.php': ('settings.edit', 'OAuth state==session nonce (GET callback)'),
    'admin/diag.php': ('platform admin + SLATE_DIAG_ENABLED=true', 'csrf_verify()'),
    'admin/repair-settings.php': ('settings.edit or super admin', 'csrf_verify()'),
    'admin/plugins.php': ('plugins.manage (+client-managed plugin or platform admin)', 'csrf_verify()'),
    'plugins/booking/admin/settings.php': ('booking.manage_settings + ModuleGuard(booking)', 'csrf_verify()'),
    'plugins/booking/BookingPlusAPI.php': ('booking.manage_settings (via booking_settings_save hook)', 'csrf_verify() on host page plugins/booking/admin/settings.php'),
    'plugins/booking/Booking.php': ('booking.manage_settings (via booking_settings_save hook)', 'csrf_verify() on host page plugins/booking/admin/settings.php'),
    'plugins/backups/admin/settings.php': ('super admin only', 'csrf_verify()'),
    'plugins/coaching/admin/settings.php': ('coaching.manage_clients', 'csrf_verify()'),
    'plugins/membership/admin/settings.php': ('membership.manage_settings + ModuleGuard(membership)', 'csrf_verify()'),
    'plugins/mcp-gateway/admin/ai-settings.php': ('mcp-gateway.manage', 'csrf_verify()'),
    'plugins/multilang-translate/admin/api.php': ('mlt.manage', 'csrf_verify($_POST[_csrf])'),
    'plugins/stripe-payment/admin/settings.php': ('stripe.manage_settings', 'csrf_verify()'),
    'plugins/studio-builder/admin/seo.php': ('studio-builder.view + studio-builder.edit', 'csrf_verify()'),
    'plugins/studio-builder/admin/ai-mcp.php': ('studio-builder.view + studio-builder.edit', 'csrf_verify()'),
    'plugins/studio-builder/admin/redirects.php': ('studio-builder.view + studio-builder.edit', 'csrf_verify()'),
    'plugins/studio-builder/admin/types-fields.php': ('studio-builder.view + studio-builder.edit', 'csrf_verify()'),
    'plugins/studio-builder/admin/code-tracking.php': ('studio-builder.admin (or super admin)', 'csrf_verify()'),
    'plugins/studio-builder/admin/content.php': ('NONE beyond Auth::require() (any signed-in admin user)', 'csrf_verify()'),
    'src/Module/StudioBuilder/Menu/StudioMenus.php': ('studio-builder.edit (menu-new.php/menu-edit.php)', 'csrf_verify()'),
    'src/Module/StudioBuilder/Registry/BlockAvailability.php': ('StudioPermissions::ADMIN (StudioApplicationService::saveElementManager)', 'Studio API csrf_verify() (plugins/studio-builder/admin/api.php)'),
    'src/Module/StudioBuilder/Application/StudioApplicationService.php': ('StudioPermissions::ADMIN', 'Studio API csrf_verify()'),
}
SYSTEM_OWNERS = {
    'plugins/booking/GoogleCalendarSync.php', 'plugins/backups/GoogleDriveClient.php', 'includes/SmtpOAuth.php',
    'plugins/stripe-payment/StripePaymentAPI.php', 'plugins/stripe-payment/StripeTerminalAPI.php', 'cron.php',
    'includes/license_sync.php', 'src/Services/Media/Media.php', 'plugins/mcp-gateway/McpGatewayAPI.php',
    'plugins/multilang-translate/includes/Crawler.php', 'plugins/forms/FormsAPI.php', 'plugins/licensing/LicensingAPI.php',
    'plugins/licensing/Licensing.php', 'src/Module/StudioBuilder/Infrastructure/StudioSchemaManager.php',
    'plugins/booking/Booking.php', 'plugins/coaching/Coaching.php', 'plugins/membership/Membership.php',
    'plugins/stripe-payment/StripePayment.php',
}

# Curated validation/bounds for writes whose value is validated before the setSetting line.
V = {
    'site_name': ('strip control chars + all tags, whitespace-normalised, required', '<=120 chars'),
    'default_language': ("regex ^[a-z]{2}$ else 'en'", 'enum-like 2 letters'),
    'currency': ("upper, regex ^[A-Z]{3,8}$ else USD", '3-8 A-Z'),
    'currency_position': ("before|after", 'enum'),
    'permalink_structure': ('allow-list of 3 structures', 'enum; <=100'),
    'permalink_post_base': ('[^a-z0-9_-] stripped, fallback post', '<=50 chars'),
    'timezone': ('DateTimeZone::listIdentifiers() allow-list else UTC', 'enum; <=64'),
    'date_format': ('preset or free-text custom format; trim only', '<=32 chars'),
    'time_format': ('preset or free-text custom format; trim only', '<=32 chars'),
    'start_of_week': ('int, out of range -> 1', '0..6'),
    'smtp_host': ('trim only (any host)', '<=190 chars'),
    'smtp_port': ('int; 0 -> blank', '1..65535 (error otherwise)'),
    'smtp_user': ('trim', '<=190 chars'),
    'smtp_encryption': ('none|tls|ssl (error otherwise)', 'enum'),
    'smtp_from_name': ('trim', '<=120 chars'),
    'smtp_from_email': ('FILTER_VALIDATE_EMAIL when non-empty', '<=190 chars'),
    'smtp_pass': ('write-only; blank keeps; clear checkbox; slate_encrypt_secret (failure: not stored, other fields saved)', 'none'),
    'smtp_backup_enabled': ("checkbox '1'/'0'", 'enum'),
    'smtp_backup_host': ('trim only', '<=190 chars'),
    'smtp_backup_port': ('int; 0 -> blank', '1..65535'),
    'smtp_backup_user': ('trim', '<=190 chars'),
    'smtp_backup_encryption': ('none|tls|ssl', 'enum'),
    'smtp_backup_pass': ('write-only; slate_encrypt_secret (failure: not stored)', 'none'),
    'smtp_auth_type': ("system-set: 'xoauth2' (graph/OAuth callback) or 'password'", 'enum'),
    'smtp_oauth_provider': ('SmtpOAuth::config($provider) must exist (oauth_connect); literal microsoft in save_smtp', 'enum (provider list)'),
    'smtp_oauth_client_id': ('trim; required on connect', '<=255 chars in save_smtp; none on connect'),
    'smtp_oauth_client_secret': ('encrypted (slate_encrypt_secret / sealed); requires APP_SECRET', 'none'),
    'smtp_oauth_email': ('FILTER_VALIDATE_EMAIL on oauth_connect; NOT validated in save_smtp (graph host branch)', '<=190 chars in save_smtp'),
    'smtp_oauth_refresh_token': ('provider response, sealed', 'n/a'),
    'smtp_oauth_access_token': ('provider response, sealed', 'n/a'),
    'smtp_oauth_expires': ('time()+expires_in', 'int'),
    'session_timeout_minutes': ('int clamp', '5..1440'),
    'max_login_attempts': ('int clamp', '1..20'),
    'lockout_minutes': ('int clamp', '1..1440'),
    'force_https': ("checkbox '1'/'0'", 'enum'),
    'brand_accent_color': ('regex ^#[0-9a-fA-F]{6}$ or empty', 'hex'),
    'brand_button_text_mode': ('auto|light|dark', 'enum'),
    'sidebar_theme': ('slate_sidebar_themes() keys else ink', 'enum'),
    'brand_font_family': ('allow-list of 6 families', 'enum'),
    'brand_border_radius': ('none|small|medium|large|pill', 'enum'),
    'brand_logo_path': ('Uploads::handle png/jpeg/webp <=2MB, or MediaLibrary::isManagedPath(picked)', 'upload 2MB'),
    'brand_logo_dark_path': ('Uploads::handle png/jpeg/webp <=2MB, or managed path', 'upload 2MB'),
    'brand_favicon_path': ('Uploads::handle png/ico <=512KB, or managed path', 'upload 512KB'),
    'brand_login_image_path': ('Uploads::handle png/jpeg/webp <=5MB, or managed path', 'upload 5MB'),
    'brand_login_tagline': ('trim', '<=200 chars'),
    'brand_owner': ('trim', '<=120 chars'),
    'brand_owner_url': ('FILTER_VALIDATE_URL when non-empty (scheme not restricted)', 'none'),
    'support_email': ('FILTER_VALIDATE_EMAIL when non-empty', 'none'),
    'support_phone': ('trim', '<=40 chars'),
    'support_url': ('FILTER_VALIDATE_URL (scheme not restricted)', 'none'),
    'docs_url': ('FILTER_VALIDATE_URL (scheme not restricted)', 'none'),
    'privacy_url': ('FILTER_VALIDATE_URL (scheme not restricted)', 'none'),
    'terms_url': ('FILTER_VALIDATE_URL (scheme not restricted)', 'none'),
    'landing_website_url': ('https:// prefixed if no scheme, FILTER_VALIDATE_URL', '<=300 chars'),
    'landing_forms_json': ('per-row: int id, label<=60, blurb<=160, icon allow-list, button<=40; JSON', 'row field caps'),
    'landing_booking_icon': ('icon allow-list', 'enum'),
    'maintenance_mode': ("checkbox '1'/'0'", 'enum'),
    'debug_mode': ("checkbox '1'/'0'", 'enum'),
    'media_thumb_w': ('int clamp', '10..2000'), 'media_thumb_h': ('int clamp', '10..2000'),
    'media_medium_w': ('int clamp', '10..4000'), 'media_medium_h': ('int clamp', '10..4000'),
    'media_large_w': ('int clamp', '10..8000'), 'media_large_h': ('int clamp', '10..8000'),
    'media_webp_convert': ("checkbox '1'/'0'", 'enum'), 'media_max_upload_mb': ('int clamp', '1..500'),
    'embed_allowed_origins': ('slate_normalize_embed_origins()', 'normalised origin list'),
    'booking.reminder_leads': ('CSV of positive int minutes, dedup; fallback filter default 1440,60', '>0 each; count unbounded'),
    'booking.confirmation_mode': ('auto|manual', 'enum'),
    'booking.google_client_secret': ('write-only; slate_seal_posted_secrets (encrypt-first, nothing saved on failure)', 'none'),
    'booking.twilio_token': ('write-only; slate_seal_posted_secrets (encrypt-first)', 'none'),
    'bookingplus.whatsapp_url': ('trim only (input type=url client-side only; no server URL/scheme check)', 'none server-side (maxlength=500 client-side)'),
    'bookingplus.nudge_hours': ('int clamp', '1..72'),
    'backups.google_client_secret': ('write-only; slate_seal_posted_secrets (encrypt-first)', 'none'),
    'mcp-gateway.ai_base_url': ('AiProviderClient::vetBaseUrl (public https, no private hosts); re-vetted at use', '<=300 chars'),
    'mcp-gateway.ai_api_key': ('write-only; slate_seal_posted_secrets; clear checkbox', 'none'),
    'multilang-translate.autotranslate_api_key': ('write-only; MLT_AutoTranslate::resolveStoredApiKey encrypts (fail-closed)', 'none'),
    'stripe-payment.mode': ('test|live', 'enum'),
    'stripe-payment.publishable_key': ('regex ^pk_(test|live)_ + mode/account-marker match when changed', 'format'),
    'stripe-payment.secret_key': ('regex ^sk_(test|live)_, verified with Stripe /v1/balance, encrypted (fail-closed)', 'format'),
    'stripe-payment.webhook_secret': ('write-only, encrypted (fail-closed); NO format check (whsec_)', 'none'),
    'stripe-payment.allowed_countries': ('trim only (no ISO-code validation)', 'none'),
    'membership.terms_url': ('trim only (no URL/scheme validation)', 'none'),
    'membership.expiry_reminder_days': ('[^0-9,] stripped, fallback 7,3', 'none'),
    'membership.insurance_fee_cents': ('float*100 rounded, max(0)', '>=0'),
    'membership.insurance_required_services': ('intval list, unique', 'none'),
    'coaching.program_plan_id': ('int max(0); plan existence not checked', '>=0'),
    'coaching.default_activity_factor': ('float clamp', '1.0..2.5'),
    'studio_code_custom_css': ('StudioCodePolicy::prepareCustomCss / sanitizeCustomCss (re-sanitised at render)', '<=64 KiB'),
    'studio_code_ga4_id': ('StudioCodePolicy::ga4Id format check', 'format'),
    'studio_code_gtm_id': ('StudioCodePolicy::gtmId format check', 'format'),
    'studio_code_head_snippet': ('StudioCodePolicy snippetProblem() allow-list; re-checked at render', 'policy'),
    'studio_code_footer_snippet': ('StudioCodePolicy snippetProblem() allow-list; re-checked at render', 'policy'),
    'studio_code_site_verification': ('VERIFICATION_TOKEN_PATTERN per service; JSON', 'pattern'),
    'studio_seo_title_format': ('trim only', 'none'), 'studio_seo_description': ('trim only', 'none'),
    'studio_seo_og_media_id': ('trim only (is_numeric only on preview)', 'none'), 'studio_seo_og_image_url': ('trim only', 'none'),
    'studio_seo_robots': ('trim only (no allow-list)', 'none'), 'studio_seo_google_verify': ('trim only', 'none'), 'studio_seo_bing_verify': ('trim only', 'none'),
    'studio_profile': ('trim only per field; name required; JSON', 'none'),
    'studio_content_portfolio': ('trim only per field (URLs unchecked); JSON', 'none'),
    'studio_content_service': ('trim only per field; JSON', 'none'),
    'studio_content_testimonial': ('trim; rating clamp 1..5; JSON', 'rating 1..5'),
    'studio_menus': ('StudioMenus::save validation (KEY_PATTERN etc.); JSON', 'see StudioMenus'),
    'studio_disabled_blocks': ('BlockAvailability::save (registered types only)', '<=200 types'),
    **{'booking.cap.' + c: ("checkbox -> '1'/'0'", 'enum; manifest default when unset') for c in ['client_messaging', 'service_rules', 'slot_restrictions', 'custom_reminders']},
    **{'stripe-payment.' + c: ("checkbox isset -> '1'/'0'", 'enum') for c in ['show_hosted', 'show_embedded', 'booking_embedded', 'wallet_apple_pay', 'wallet_google_pay', 'wallet_link', 'method_us_bank_account', 'method_klarna', 'method_cashapp', 'method_amazon_pay', 'method_bancontact']},
    **{'stripe-payment.%s_%s' % (m, x): v for m in ('test', 'live') for x, v in (('publishable_key', ('mirror of publishable_key for the selected mode', 'format')), ('secret_key', ('mirror of secret_key (encrypted, fail-closed)', 'format')), ('webhook_secret', ('mirror of webhook_secret (encrypted, fail-closed; no whsec_ check)', 'none')))},
    'stripe-payment.verified_at': ('system timestamp after Stripe /v1/balance check', 'n/a'),
    'stripe-payment.verified_account': ('derived account marker', 'n/a'),
    'stripe-payment.verified_mode': ('test|live from Stripe livemode', 'enum'),
    'booking.multislot_max': ('int clamp (multi-line write)', '2..20'),
    'permalink_trailing_slash': ("checkbox '1'/'0'", 'enum'),
    'studio_ai_model': ('trim only (free text)', 'none'), 'studio_ai_tone': ('trim only (free text)', 'none'),
    'studio_ai_temperature': ('float clamp', '0.0..1.0'), 'studio_ai_max_tokens': ('int clamp', '1000..16000'),
    'studio_redirects': ('from/to path checks, reserved-route + loop check, code 301|302; JSON list', 'list unbounded'),
    'studio_custom_post_types': ('key format + collision checks; JSON', 'see types-fields.php'),
    'multilang-translate.scan_manual_urls': ('written via admin/api.php save_scan_urls (mlt.manage + csrf); raw textarea stored, filtered at read (normalizeUrl + sameHost)', '<=20000 bytes'),
    'backups.google_connected_email': ('from Google userinfo response', 'n/a'),
    'backups.google_folder_id': ('from Drive API response', 'n/a'),
    'stripe-payment.terminal_location_id': ('from Stripe API response', 'n/a'),
    'brand_sublabel': ("only written by repair-settings reset to 'Pro Admin'", 'n/a'),
    'forms.contacts_backfilled': ("one-shot migration flag '1'", 'n/a'),
    'cron_last_daily': ('system date stamp', 'n/a'), 'mcp-gateway.schema_v': ('schema version', 'n/a'), 'media_schema_v': ('schema version', 'n/a'),
    **{'stripe-payment.wh_' + c: ('system webhook health counter/timestamp/reason', 'n/a') for c in ['ok_at', 'ok_count', 'ok_type', 'fail_at', 'fail_count', 'fail_reason']},
    **{'small-business-kit.' + c: ('CLI argument, unvalidated', 'none') for c in ['footer_about', 'footer_address', 'header_email', 'header_phone', 'header_phone_href']},
    'multilang-translate.switcher_enabled': ('NONE (raw POST string)', 'none'),
    'multilang-translate.switcher_style': ('NONE (raw POST string; e() at render)', 'none'),
    'multilang-translate.switcher_position': ('NONE (raw POST string; e() at render)', 'none'),
    'multilang-translate.switcher_bg': ('NONE (raw POST string; e() into style attr)', 'none'),
    'multilang-translate.switcher_fg': ('NONE (raw POST string; e() into style attr)', 'none'),
    'multilang-translate.switcher_accent': ('NONE (raw POST string; e() into style attr)', 'none'),
}

def heur(texts):
    t = ' '.join(texts)
    if re.search(r"slate_encrypt_secret|\$sealed|Enc\b|envelope|secretEnc|webhookEnc", t):
        return ('encrypted before write', 'none')
    m = re.search(r"max\((\d+),\s*min\((\d+),\s*\(int\)", t)
    if m: return ('int cast + clamp', '%s..%s' % m.groups())
    m = re.search(r"max\((\d+),\s*\(int\)", t)
    if m: return ('int cast', '>=' + m.group(1))
    if re.search(r"(!empty|isset|!isset)\([^)]*\)\)?\s*\?\s*'[01]'\s*:\s*'[01]'", t):
        return ("checkbox '1'/'0'", 'enum')
    if re.search(r"\? '1' : '0'", t):
        return ("boolean '1'/'0'", 'enum')
    m = re.search(r"mb_substr\(.*?,\s*0,\s*(\d+)\)", t)
    if m: return ('trim + length cap', '<=%s chars' % m.group(1))
    if re.search(r"\$this->version|\$version|slate_db_now|time\(\)|date\(", t):
        return ('system-set (version/timestamp)', 'n/a')
    if 'json_encode' in t: return ('JSON-encoded structure', 'none')
    if re.search(r"trim\(\(string\)\s*\(\$_POST|trim\(\(string\)\$_POST", t):
        return ('trim only', 'none')
    return ('system/derived value (not user input) or not determined', 'n/a')

SECRET = re.compile(r"(secret|_token$|_pass$|api_key)")
NOT_SECRET = {'stripe-payment.publishable_key', 'stripe-payment.test_publishable_key', 'stripe-payment.live_publishable_key', 'licensing.signing_public_key'}
SENSITIVE = re.compile(r"(client_id|email|phone|address|twilio_sid|_from$|verified_account|snippet|custom_css|origins|base_url|smtp_host|smtp_backup_host|_user$|ga4|gtm|verification|_verify$|connected_email|folder_id|token_expires|smtp_oauth_expires|whatsapp_url|terms_url|studio_profile|terminal_location|allowed_countries|ai_model)")

def classify(k):
    if k in NOT_SECRET: return 'normal'
    if SECRET.search(k): return 'secret'
    if SENSITIVE.search(k): return 'sensitive'
    return 'normal'

DEF_PATS = [
    lambda k: r"setting\(\s*'%s'\s*(?:,[^)]*)?\)\s*\)?\s*(?:\?\?|\?:)\s*('[^']*'|-?\d+(?:\.\d+)?|true|false)" % re.escape(k),
]

REPAIR_DEF = {'site_name': "'Kohevo'", 'default_language': "'en'", 'brand_sublabel': "'Pro Admin'", 'brand_accent_color': "'#111111'", 'smtp_encryption': "'tls'"}
def default_for(key, reads):
    found = collections.Counter(x for x in (_default_one(key, r) for r in reads) if x)
    if key in ('smtp_host', 'smtp_backup_host'): return "''"
    if found: return found.most_common(1)[0][0]
    return REPAIR_DEF.get(key, '')
def _default_one(key, r):
    short = key.split('.', 1)[1] if '.' in key else key
    for r in [r]:
        t = r['text']
        m = re.search(r"setting\(\s*'%s'\s*(?:,[^)]*)?\)\s*\)?\s*(?:\?\?|\?:)\s*('[^']*'|-?\d+(?:\.\d+)?|true|false)" % re.escape(key), t)
        if m: return m.group(1)
        m = re.search(r"(?:->setting|\$g|\$s|\$on|\$sset|bookpub_book_setting)\(\s*'%s'\s*,\s*('[^']*'|-?\d+|true|false)" % re.escape(short), t)
        if m: return m.group(1)
        if 'bookpub_book_setting' in r['via']: return 'true (unset = enabled)'
    return ''

inv = list(csv.DictReader(open(os.path.join(WT, '01-client/audit/kohevo-settings-widget-inventory.csv'))))
inv_keys = set()
for row in inv:
    inv_keys.add(row['Setting_Or_Widget'])
    if row['Domain'] == 'Plugin':
        inv_keys.add(row['Component'] + '.' + row['Setting_Or_Widget'])

def in_inv(k):
    if k in inv_keys: return 'yes'
    return 'no'

_ks = {}
for _t in ('client', 'licensing'):
    _ks[_t] = {r['key'] for r in data[_t]['recs']}
def PAR(k):
    a, b = k in _ks['client'], k in _ks['licensing']
    return 'both' if a and b else ('client' if a else 'licensing')
rows = []
stats = {}
keysets = {}
for tree in ('client', 'licensing'):
    agg = collections.defaultdict(lambda: {'w': [], 'r': []})
    for r in data[tree]['recs']:
        agg[r['key']]['w' if r['kind'] == 'write' else 'r'].append(r)
    keysets[tree] = set(agg)
    for k in sorted(agg):
        ws, rs = agg[k]['w'], agg[k]['r']
        wfiles = sorted({w['file'] for w in ws})
        LOWPRI = ('admin/diag.php', 'admin/repair-settings.php', 'admin/oauth_callback.php', 'admin/plugins.php')
        admin_w = sorted([w for w in ws if ADMIN_PAGE.match(w['file'])], key=lambda w: (w['file'] in LOWPRI, w['file'] != 'admin/settings.php'))
        owner = (admin_w or [w for w in ws if not w['file'].startswith(SYSTEM_HINT)] or ws or [None])[0]
        owner_files = {w['file'] for w in ws if ADMIN_PAGE.match(w['file'])} or ({owner['file']} if owner else set())
        runtime = [r for r in rs if r['file'] not in owner_files and r['file'] != 'admin/repair-settings.php']
        if not ws:
            used = 'read-only (no production writer)'
        elif not rs:
            used = 'no (dead: never read)'
        elif not runtime and owner and ADMIN_PAGE.match(owner['file']) and not re.search(r'verified_|version|_at$', k):
            used = 'display-only (read back only by its own form)'
        elif not runtime:
            used = 'yes (internal state read by its own module)'
        else:
            used = 'yes'
        val, bnd = V.get(k) or heur([w['text'] for w in ws if owner and w['file'] == owner['file']] or [w['text'] for w in ws]) if ws else ('n/a (no writer)', 'n/a')
        if owner and owner['file'] in PERM:
            perm, csrf_ = PERM[owner['file']]
        elif owner and owner['file'].startswith('bin/'):
            perm, csrf_ = ('CLI script (shell access)', 'n/a')
        elif owner:
            perm, csrf_ = ('n/a (system/runtime write, no user input)', 'n/a')
        else:
            perm, csrf_ = ('n/a (no writer)', 'n/a')
        cls = classify(k)
        notes = []
        others = [f for f in wfiles if owner and f != owner['file']]
        if others: notes.append('also written by: ' + ', '.join(others))
        if cls == 'secret':
            enc = any(re.search(r"encrypt|sealed|Enc|envelope", w['text']) for w in ws) or bool(re.search('encrypt|seal', V.get(k, ('', ''))[0]))
            if not ws: enc = None
            notes.append('no writer (nothing stored)' if enc is None else ('encrypted at rest' if enc else 'ENCRYPTION NOT CONFIRMED'))
        notes.append({'both': 'present in both trees', 'client': 'client tree only', 'licensing': 'licensing tree only'}[PAR(k)])
        vias = sorted({r['via'] for r in ws + rs if r['via'] != 'direct'})
        if vias: notes.append('via ' + '; '.join(vias))
        rows.append(dict(key=k, tree=tree, owner_file=owner['file'] if owner else '', owner_line=owner['line'] if owner else '',
                         readers='; '.join('%s:%s' % (r['file'], r['line']) for r in rs),
                         default=default_for(k, rs), validation=val, bounds=bnd, permission=perm, csrf=csrf_,
                         classification=cls, used=used, in_existing_inventory=in_inv(k), notes=' | '.join(notes),
                         _writers=['%s:%s' % (w['file'], w['line']) for w in ws]))
    for d in data[tree]['dyn']:
        rows.append(dict(key=d['pattern'], tree=tree, owner_file=d['file'], owner_line=d['line'], readers='', default='',
                         validation='dynamic key pattern', bounds='n/a', permission='see notes', csrf='see notes',
                         classification='mixed', used='dynamic', in_existing_inventory='no', notes='dynamic key pattern (' + d['kind'] + ')', _writers=[]))

json.dump(dict(rows=rows, keysets={t: sorted(v) for t, v in keysets.items()}, inv=inv), open(os.path.join(S, 'rows.json'), 'w'), indent=1)
cols = ['key', 'tree', 'owner_file', 'owner_line', 'readers', 'default', 'validation', 'bounds', 'permission', 'csrf', 'classification', 'used', 'in_existing_inventory', 'notes']
with open(os.path.join(S, 'settings-full-inventory.csv'), 'w', newline='') as fh:
    w = csv.DictWriter(fh, fieldnames=cols, extrasaction='ignore')
    w.writeheader()
    for r in rows: w.writerow(r)
for tree in ('client', 'licensing'):
    tr = [r for r in rows if r['tree'] == tree and r['used'] != 'dynamic']
    c = collections.Counter(r['used'] for r in tr)
    print(tree, len(tr), dict(c), 'missing_from_inventory', sum(r['in_existing_inventory'] == 'no' for r in tr),
          'classes', dict(collections.Counter(r['classification'] for r in tr)))
