<?php
/**
 * Forms — create / edit a form.
 *
 * Uses a textarea DSL for the field list (parsed by FormsAPI). The
 * full drag-drop builder is a follow-up session. Webhook URLs are
 * one-per-line in another textarea — save replaces the list.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/FormsAPI.php';
require_once __DIR__ . '/_editor_ui.php';   // reusable record-editor UI kit

Auth::require();
Auth::requirePerm('forms.manage');
FormsAPI::ensureSchema();

$tid = current_tenant_id();

// ── Live preview: render the public form from the builder's current JSON
// (no save). The editor's "Preview" button POSTs fields_json_data here. ──
if (($_GET['preview'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $arr = json_decode((string)($_POST['fields_json_data'] ?? ''), true);
    $pf  = is_array($arr) ? FormsAPI::normalizeFields($arr)['fields'] : [];
    $title = trim((string)($_POST['title'] ?? '')) ?: __('forms_edit_preview_default_title', 'Form preview');
    $desc  = trim((string)($_POST['description'] ?? ''));
    $pvSet = FormsAPI::formSettings((string)($_POST['settings_json'] ?? ''));
    $cssUrl = plugin_url('forms', 'assets/css/public.css') . '?v=' . FormsAPI::ASSET_VERSION;
    $jsUrl  = plugin_url('forms', 'assets/js/forms-logic.js') . '?v=' . FormsAPI::ASSET_VERSION;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . __('forms_edit_preview', 'Preview') . ' · ' . e($title) . '</title>';
    slate_ui_emit_css();
    echo '<link rel="stylesheet" href="' . e($cssUrl) . '">';
    // Preview chrome — a polished toolbar + responsive device frame wrapped
    // around the real public form (the form itself uses public.css untouched).
    echo <<<'CSS'
<style>
.fbpv-bar{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:14px;padding:10px 16px;
  background:rgba(255,255,255,.82);-webkit-backdrop-filter:saturate(1.5) blur(10px);backdrop-filter:saturate(1.5) blur(10px);
  border-bottom:1px solid #E4E7EC;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;}
.fbpv-brand{display:flex;align-items:center;gap:9px;min-width:0;}
.fbpv-dot{width:8px;height:8px;border-radius:99px;background:#F59E0B;box-shadow:0 0 0 3px rgba(245,158,11,.18);flex:none;}
.fbpv-kicker{font-weight:700;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#B45309;}
.fbpv-title{font-weight:600;font-size:13px;color:#0F172A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:38vw;}
.fbpv-title::before{content:"·";margin:0 9px 0 3px;color:#CBD5E1;}
.fbpv-seg{margin-left:auto;display:flex;gap:2px;padding:3px;background:#F1F3F6;border:1px solid #E4E7EC;border-radius:11px;}
.fbpv-seg button{appearance:none;-webkit-appearance:none;border:0;background:transparent;cursor:pointer;display:inline-flex;align-items:center;gap:6px;
  padding:6px 11px;border-radius:8px;font:600 12px/1 inherit;color:#475467;transition:background .15s,color .15s,box-shadow .15s;}
.fbpv-seg button svg{width:15px;height:15px;}
.fbpv-seg button[aria-pressed="true"]{background:#fff;color:#0F172A;box-shadow:0 1px 2px rgba(16,24,40,.14);}
.fbpv-note{font-size:11px;color:#94A3B8;white-space:nowrap;}
.fbpv-frame{margin:0 auto;width:100%;transition:width .28s cubic-bezier(.4,0,.2,1);}
.fbpv-frame[data-device="tablet"]{width:768px;}
.fbpv-frame[data-device="mobile"]{width:396px;}
.fbpv-frame[data-device="tablet"],.fbpv-frame[data-device="mobile"]{margin-top:26px;
  border:1px solid #E4E7EC;border-radius:26px;box-shadow:0 28px 64px -32px rgba(16,24,40,.32);overflow:hidden;}
.fbpv-frame[data-device="tablet"] .forms-public-shell,.fbpv-frame[data-device="mobile"] .forms-public-shell{padding:34px 14px;}
@media(max-width:660px){.fbpv-title,.fbpv-note{display:none;}}
</style>
CSS;
    echo '</head><body class="forms-public">';
    $pvIcon = [
        'desktop' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>',
        'tablet'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>',
        'mobile'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M12 18h.01"/></svg>',
    ];
    echo '<div class="fbpv-bar">'
       . '<span class="fbpv-brand"><span class="fbpv-dot"></span><span class="fbpv-kicker">' . __('forms_edit_preview', 'Preview') . '</span>'
       . '<span class="fbpv-title">' . e($title) . '</span></span>'
       . '<span class="fbpv-seg" id="fbpv-seg" role="group" aria-label="' . e(__('forms_edit_preview_width', 'Preview width')) . '">'
       . '<button type="button" data-device="desktop" aria-pressed="true" title="' . e(__('forms_edit_desktop_view', 'Desktop view')) . '">' . $pvIcon['desktop'] . __('forms_edit_desktop', 'Desktop') . '</button>'
       . '<button type="button" data-device="tablet" aria-pressed="false" title="' . e(__('forms_edit_tablet_view', 'Tablet view')) . '">' . $pvIcon['tablet'] . __('forms_edit_tablet', 'Tablet') . '</button>'
       . '<button type="button" data-device="mobile" aria-pressed="false" title="' . e(__('forms_edit_mobile_view', 'Mobile view')) . '">' . $pvIcon['mobile'] . __('forms_edit_mobile', 'Mobile') . '</button>'
       . '</span>'
       . '<span class="fbpv-note">' . __('forms_edit_changes_not_saved', 'Changes aren\'t saved') . '</span>'
       . '</div>';
    $pvMulti = false;
    foreach ($pf as $pff) { if (($pff['type'] ?? '') === 'step') { $pvMulti = true; break; } }
    echo '<div class="fbpv-frame" id="fbpv-frame" data-device="desktop">';
    echo '<main class="forms-public-shell"><article ' . FormsAPI::publicCardAttrs($pvSet) . '>';
    if (!$pvMulti) {
        echo '<header class="forms-public-header"><h1>' . e($title) . '</h1>';
        if ($desc !== '') echo '<p class="forms-public-desc">' . nl2br(e($desc)) . '</p>';
        echo '</header>';
    }
    echo '<form onsubmit="return false;" data-animate="' . ($pvSet['animate'] ? '1' : '0') . '" data-validate="' . ($pvSet['validate'] ? '1' : '0') . '">';
    if (!$pf) {
        echo '<p style="color:#64748B;">' . __('forms_edit_no_fields_yet', 'No fields yet — add some in the builder.') . '</p>';
    } else {
        $pvLogoPath = (string)Database::setting('brand_logo_path');
        $pvBrand = ['name' => (string)(Database::setting('site_name') ?: ''), 'logo' => $pvLogoPath !== '' ? SLATE_URL . '/' . ltrim($pvLogoPath, '/') : ''];
        echo FormsAPI::renderFormBody($pf, [], [], __('forms_submit', 'Submit'), ['rail' => $pvSet['rail'], 'title' => $title, 'summary' => !empty($pvSet['summary']), 'brand' => $pvBrand]);
    }
    echo '</form></article></main></div>';
    echo '<script>(function(){var f=document.getElementById("fbpv-frame"),s=document.getElementById("fbpv-seg");if(!f||!s)return;s.addEventListener("click",function(e){var b=e.target.closest("button[data-device]");if(!b)return;f.setAttribute("data-device",b.getAttribute("data-device"));var all=s.querySelectorAll("button");for(var i=0;i<all.length;i++){all[i].setAttribute("aria-pressed",all[i]===b?"true":"false");}});})();</script>';
    echo '<script src="' . e($jsUrl) . '" defer></script>';
    echo '</body></html>';
    exit;
}

$id   = (int)($_GET['id'] ?? 0);
$form = null;
$webhookUrlsText = '';

if ($id > 0) {
    $form = Database::row(
        "SELECT * FROM forms_definitions WHERE id = ? AND tenant_id = ?",
        [$id, $tid]
    );
    if (!$form) {
        http_response_code(404);
        require SLATE_ROOT . '/admin/partials/header.php';
        echo '<div class="card"><h1>' . __('form_not_found', 'Form not found') . '</h1></div>';
        require SLATE_ROOT . '/admin/partials/footer.php';
        exit;
    }
    $hooks = Database::rows(
        "SELECT url FROM forms_webhooks WHERE form_id = ? AND tenant_id = ? AND is_active = 1 ORDER BY id",
        [$id, $tid]
    );
    $webhookUrlsText = implode("\n", array_column($hooks, 'url'));
}

$isNew = !$form;

// Defaults / form state
$state = [
    'title'             => $form['title']             ?? '',
    'slug'              => $form['slug']              ?? '',
    'description'       => $form['description']       ?? '',
    'fields_dsl'        => $form ? FormsAPI::fieldsToDsl(json_decode($form['fields_json'] ?? '[]', true) ?: []) : "text|full_name|Your name|required\nemail|email|Email|required\ntextarea|message|Message|required",
    'fields_json'       => $form['fields_json'] ?? '',
    'submit_label'      => $form['submit_label']      ?? 'Submit',
    'success_message'   => $form['success_message']   ?? 'Thanks — we got your submission. We\'ll be in touch.',
    'redirect_url'      => $form['redirect_url']      ?? '',
    'notify_email'      => $form['notify_email']      ?? '',
    'confirm_submitter' => (int)($form['confirm_submitter'] ?? 0),
    'confirm_subject'   => $form['confirm_subject']   ?? '',
    'confirm_body'      => $form['confirm_body']      ?? '',
    'submission_limit'  => $form['submission_limit']  ?? '',
    'status'            => $form['status']            ?? 'draft',
    'webhook_urls'      => $webhookUrlsText,
];

// Seed a brand-new form from a starter template (?template=slug). GET-only
// so a form submission never re-seeds. Everything stays fully editable.
if ($isNew && $_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['template'])) {
    $tpl = FormTemplates::get(preg_replace('/[^a-z0-9_-]/', '', (string)$_GET['template']));
    if ($tpl) {
        $norm = FormsAPI::normalizeFields($tpl['fields'] ?? []);
        if (!empty($norm['fields'])) {
            $state['title']       = (string)($tpl['title'] ?? $state['title']);
            $state['fields_json'] = json_encode($norm['fields'], JSON_UNESCAPED_UNICODE);
            $state['fields_dsl']  = FormsAPI::fieldsToDsl($norm['fields']);
        }
    }
}

// Per-form appearance settings (density / animations / validation / rail).
$fset = FormsAPI::formSettings($form['settings_json'] ?? null);

// A starter template can ship its own modern look — apply it on first load.
if ($isNew && isset($tpl) && is_array($tpl) && !empty($tpl['settings'])) {
    $fset = FormsAPI::formSettings(json_encode($tpl['settings']));
}

$flash      = null;
$fieldErrors = [];

// Flash after a Post/Redirect/Get save (see redirect below).
if (($_GET['saved'] ?? '') === 'created') {
    $flash = ['type' => 'success', 'msg' => __('forms_edit_created', 'Form created.')];
} elseif (($_GET['saved'] ?? '') === 'saved') {
    $flash = ['type' => 'success', 'msg' => __('forms_edit_saved', 'Form saved.')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        // Soak up posted state
        foreach (array_keys($state) as $k) {
            if (array_key_exists($k, $_POST)) {
                $state[$k] = is_string($_POST[$k]) ? $_POST[$k] : $state[$k];
            }
        }
        $state['confirm_submitter'] = !empty($_POST['confirm_submitter']) ? 1 : 0;

        // Appearance settings
        $fset['density']  = (($_POST['fs_density'] ?? '') === 'compact') ? 'compact' : 'comfortable';
        $fset['animate']  = !empty($_POST['fs_animate']);
        $fset['validate'] = !empty($_POST['fs_validate']);
        $fset['rail']     = !empty($_POST['fs_rail']);
        $fset['scroll_fields'] = !empty($_POST['fs_scroll_fields']);
        $fset['full_width']    = !empty($_POST['fs_full_width']);
        $numOrNull = function ($key, $max) {
            $v = $_POST[$key] ?? '';
            return ($v !== '' && is_numeric($v)) ? max(0, min($max, (int)$v)) : null;
        };
        $fset['pad']       = $numOrNull('fs_pad', 80);
        $fset['field_gap'] = $numOrNull('fs_field_gap', 60);
        $fset['col_gap']   = $numOrNull('fs_col_gap', 80);
        if (!empty($_POST['fs_accent_on'])) {
            $fset['accent']       = FormsAPI::sanitizeHex((string)($_POST['fs_accent'] ?? ''));
            $fset['accent_hover'] = FormsAPI::sanitizeHex((string)($_POST['fs_accent_hover'] ?? ''));
        } else {
            $fset['accent'] = '';
            $fset['accent_hover'] = '';
        }
        $fset['field_style'] = (($_POST['fs_field_style'] ?? '') === 'filled') ? 'filled' : 'outline';
        $fset['shape']       = in_array(($_POST['fs_shape'] ?? 'rounded'), ['rounded', 'pill', 'square'], true) ? $_POST['fs_shape'] : 'rounded';
        $fset['labels']      = !empty($_POST['fs_hide_labels']) ? 'hide' : 'show';
        $fset['summary']     = !empty($_POST['fs_summary']);
        $fset['pdf_attach']  = !empty($_POST['fs_pdf_attach']);
        $fset['pdf_page']    = in_array(($_POST['fs_pdf_page'] ?? 'a4'), ['a4', 'letter', 'legal'], true) ? $_POST['fs_pdf_page'] : 'a4';
        $fset['pdf_save_btn'] = in_array(($_POST['fs_pdf_save_btn'] ?? 'always'), ['always', 'never'], true) ? $_POST['fs_pdf_save_btn'] : 'always';
        $fset['pdf_sign']      = !empty($_POST['fs_pdf_sign']);
        $fset['pdf_sign_both'] = !empty($_POST['fs_pdf_sign_both']);
        // PDF customizer overrides (heading / recital / footer note).
        $fset['pdf_heading']     = trim((string)($_POST['fs_pdf_heading']     ?? ''));
        $fset['pdf_intro']       = trim((string)($_POST['fs_pdf_intro']       ?? ''));
        $fset['pdf_footer_note'] = trim((string)($_POST['fs_pdf_footer_note'] ?? ''));
        // Admin notification email template overrides.
        $fset['email_subject']      = trim((string)($_POST['fs_email_subject']      ?? ''));
        $fset['email_header_label'] = trim((string)($_POST['fs_email_header_label'] ?? ''));
        $fset['email_intro']        = trim((string)($_POST['fs_email_intro']        ?? ''));
        $fset['email_outro']        = trim((string)($_POST['fs_email_outro']        ?? ''));
        $fset['email_cta_label']    = trim((string)($_POST['fs_email_cta_label']    ?? ''));
        $fset['email_show_table']   = !empty($_POST['fs_email_show_table']);
        $sigPosted = (string)($_POST['fs_sender_sig'] ?? '');
        $fset['sender'] = [
            'name'    => trim((string)($_POST['fs_sender_name'] ?? '')),
            'company' => trim((string)($_POST['fs_sender_company'] ?? '')),
            'email'   => trim((string)($_POST['fs_sender_email'] ?? '')),
            'sig'     => str_starts_with($sigPosted, 'data:image/png;base64,') ? $sigPosted : (string)($fset['sender']['sig'] ?? ''),
        ];

        // Spam & security settings (textareas come in as strings; normalize()
        // splits them into clean lists and clamps every value to a safe shape).
        $fset['spam'] = FormsSpamGuard::normalize([
            'captcha_provider' => $_POST['sp_captcha_provider'] ?? 'none',
            'captcha_site_key' => $_POST['sp_captcha_site_key'] ?? '',
            'captcha_secret'   => $_POST['sp_captcha_secret']   ?? '',
            'recaptcha_min'    => $_POST['sp_recaptcha_min']    ?? 0.5,
            'country_mode'     => $_POST['sp_country_mode']     ?? 'off',
            'country_list'     => $_POST['sp_country_list']     ?? '',
            'geo_method'       => $_POST['sp_geo_method']       ?? 'auto',
            'maxmind_db'       => $_POST['sp_maxmind_db']       ?? '',
            'keywords'         => $_POST['sp_keywords']         ?? '',
            'max_links'        => $_POST['sp_max_links']        ?? 0,
            'block_disposable' => !empty($_POST['sp_block_disposable']),
            'ip_blocklist'     => $_POST['sp_ip_blocklist']     ?? '',
            'email_blocklist'  => $_POST['sp_email_blocklist']  ?? '',
            'rate_limit'       => $_POST['sp_rate_limit']       ?? 5,
            'rate_window'      => $_POST['sp_rate_window']      ?? 60,
            'min_seconds'      => $_POST['sp_min_seconds']      ?? 0,
            'log_blocked'      => !empty($_POST['sp_log_blocked']),
        ]);

        // Validate
        $title = trim($state['title']);
        if ($title === '') {
            $fieldErrors['title'] = __('title_required', 'Title is required.');
        }
        if ($state['notify_email'] !== '' && !filter_var($state['notify_email'], FILTER_VALIDATE_EMAIL)) {
            $fieldErrors['notify_email'] = __('notify_email_invalid', 'Notify email is not a valid email address.');
        }
        if ($state['redirect_url'] !== '' && !filter_var($state['redirect_url'], FILTER_VALIDATE_URL)) {
            $fieldErrors['redirect_url'] = __('forms_edit_redirect_url_invalid', 'Redirect URL is not valid.');
        }

        // Prefer the visual builder's JSON (carries advanced field metadata —
        // conditions, formulas, steps); fall back to the DSL textarea.
        $jsonRaw = (string)($_POST['fields_json_data'] ?? '');
        $jsonArr = $jsonRaw !== '' ? json_decode($jsonRaw, true) : null;
        if (is_array($jsonArr) && $jsonArr) {
            $parsed = FormsAPI::normalizeFields($jsonArr);
        } else {
            $parsed = FormsAPI::parseFieldDsl($state['fields_dsl']);
        }
        if (!empty($parsed['errors'])) {
            $fieldErrors['fields_dsl'] = implode(' ', $parsed['errors']);
        }
        if (empty($parsed['fields'])) {
            $fieldErrors['fields_dsl'] = ($fieldErrors['fields_dsl'] ?? '') . ' ' . __('forms_edit_at_least_one_field', 'At least one field is required.');
        }
        // Canonical JSON for the builder to re-read on re-render (keeps advanced props).
        $state['fields_json'] = json_encode($parsed['fields'], JSON_UNESCAPED_UNICODE);

        // Parse webhook URLs
        $hookUrls = [];
        foreach (preg_split('/\r\n|\n|\r/', $state['webhook_urls']) as $line) {
            $u = trim($line);
            if ($u === '') continue;
            if (!filter_var($u, FILTER_VALIDATE_URL)) {
                $fieldErrors['webhook_urls'] = __('forms_edit_webhook_urls_invalid', 'One or more webhook URLs are invalid.');
                continue;
            }
            $hookUrls[] = $u;
        }

        if (empty($fieldErrors)) {
            $slug = trim($state['slug']);
            if ($slug === '') {
                $slug = FormsAPI::slugify($title, $isNew ? null : $id);
            } else {
                // Normalise + de-dup
                $slug = FormsAPI::slugify($slug, $isNew ? null : $id);
            }

            $row = [
                'tenant_id'         => $tid,
                'slug'              => $slug,
                'title'             => $title,
                'description'       => $state['description'] !== '' ? $state['description'] : null,
                'fields_json'       => json_encode($parsed['fields'], JSON_UNESCAPED_UNICODE),
                'submit_label'      => $state['submit_label'] !== '' ? $state['submit_label'] : 'Submit',
                'success_message'   => $state['success_message'] !== '' ? $state['success_message'] : null,
                'redirect_url'      => $state['redirect_url'] !== '' ? $state['redirect_url'] : null,
                'notify_email'      => $state['notify_email'] !== '' ? $state['notify_email'] : null,
                'confirm_submitter' => (int)$state['confirm_submitter'],
                'confirm_subject'   => $state['confirm_subject'] !== '' ? $state['confirm_subject'] : null,
                'confirm_body'      => $state['confirm_body'] !== '' ? $state['confirm_body'] : null,
                'submission_limit'  => $state['submission_limit'] !== '' && is_numeric($state['submission_limit'])
                                        ? (int)$state['submission_limit'] : null,
                'status'            => in_array($state['status'], ['draft','published','archived'], true)
                                        ? $state['status'] : 'draft',
                'settings_json'     => json_encode($fset, JSON_UNESCAPED_UNICODE),
            ];

            if ($isNew) {
                $id = Database::insert('forms_definitions', $row);
                AuditLog::record('forms.created', (string)$id, ['slug' => $slug]);
                $flash = ['type' => 'success', 'msg' => __('forms_edit_created', 'Form created.')];
                $wasNew = true;
                $isNew = false;
            } else {
                Database::update('forms_definitions', $row, 'id = ? AND tenant_id = ?', [$id, $tid]);
                AuditLog::record('forms.updated', (string)$id);
                $flash = ['type' => 'success', 'msg' => __('forms_edit_saved', 'Form saved.')];
                $wasNew = false;
            }

            // Sync webhooks: simple replace strategy.
            Database::delete('forms_webhooks', 'form_id = ? AND tenant_id = ?', [$id, $tid]);
            foreach ($hookUrls as $u) {
                Database::insert('forms_webhooks', [
                    'tenant_id' => $tid,
                    'form_id'   => $id,
                    'url'       => $u,
                    'is_active' => 1,
                ]);
            }

            // Post/Redirect/Get: a bare POST to edit.php (no ?id=) re-runs as a
            // brand-new create on refresh or a double-click, inserting the form
            // twice. Redirect to the canonical ?id= URL so a reload re-fetches
            // (GET) the saved record instead of re-submitting the form.
            $savedFlag = $wasNew ? 'created' : 'saved';
            if (!headers_sent()) {
                header('Location: ' . plugin_url('forms', 'admin/edit.php') . '?id=' . (int)$id . '&saved=' . $savedFlag);
                exit;
            }

            // Re-read the saved state so the form reflects what's stored
            $form = Database::row("SELECT * FROM forms_definitions WHERE id = ?", [$id]);
            $state['slug']        = $form['slug'];
            $state['fields_dsl']  = FormsAPI::fieldsToDsl($parsed['fields']);
            $state['webhook_urls'] = implode("\n", $hookUrls);
        } else {
            $flash = ['type' => 'error', 'msg' => __('forms_edit_fix_highlighted', 'Please fix the highlighted fields.')];
        }
    }
}

$pageTitle  = $isNew ? __('forms_new', 'New form') : ($state['title'] !== '' ? $state['title'] : __('forms_edit', 'Edit form'));
$currentNav = 'forms';

// Hero stats + live status pill mapping.
$subCount   = (!$isNew && $id) ? (int) Database::value(
    "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND form_id = ?", [$tid, $id]) : 0;
$fieldCount = count(FormsAPI::parseFieldDsl($state['fields_dsl'])['fields'] ?? []);
$statusMap  = [
    'published' => ['tone' => 'on',   'text' => __('published', 'Published')],
    'draft'     => ['tone' => 'warn', 'text' => __('draft', 'Draft')],
    'archived'  => ['tone' => 'off',  'text' => __('archived', 'Archived')],
];
$curStatus  = $statusMap[$state['status']] ?? $statusMap['draft'];

require SLATE_ROOT . '/admin/partials/header.php';

$publicUrl = ($state['slug'] !== '' && !$isNew) ? SLATE_URL . '/forms/' . $state['slug'] : null;
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('forms', 'Forms'), 'href' => plugin_url('forms', 'admin/index.php')],
    ['label' => $isNew ? __('forms_new', 'New form') : $state['title']],
]); ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php forms_editor_css(); ?>

<?php forms_edit_open(['title_fallback' => __('forms_new', 'New form')]); ?>

<form method="post">
    <?= csrf_field() ?>

    <?php forms_edit_backlink([
        'back_href'  => plugin_url('forms', 'admin/index.php'),
        'back_label' => __('forms_spam_all_forms', 'All forms'),
    ]); ?>

    <?php
    // Compact, self-contained editor header: identity + Save/Cancel on top,
    // a slim stats/links strip below. Replaces the kit hero + the separate
    // action bar (kept the [data-title]/[data-status] hooks so the shared
    // editor JS still live-updates the title and status pill).
    $createdTxt = (!$isNew && !empty($form['created_at'])) ? I18n::localDate('j M Y', strtotime($form['created_at'])) : '';
    ?>
    <div class="fhead">
        <div class="fhead-top">
            <div class="fhead-idwrap">
                <span class="fhead-ico"><?= forms_edit_icon('box', 22) ?></span>
                <div class="fhead-id">
                    <h1 class="fhead-title" data-title><?= e($isNew ? __('forms_new', 'New form') : ($state['title'] !== '' ? $state['title'] : __('forms_edit_untitled', 'Untitled form'))) ?></h1>
                    <div class="fhead-meta">
                        <?php if (!$isNew): ?><span class="fhead-ref">#<?= e(str_pad((string)(int)$id, 4, '0', STR_PAD_LEFT)) ?></span><?php endif; ?>
                        <span class="pv-status <?= e($curStatus['tone']) ?>" data-status data-status-map="<?= e(json_encode($statusMap)) ?>">
                            <span class="dot"></span><span data-status-text><?= e($curStatus['text']) ?></span>
                        </span>
                    </div>
                </div>
            </div>
            <div class="fhead-actions">
                <a href="<?= e(plugin_url('forms', 'admin/index.php')) ?>" class="btn btn-ghost"><?= __('cancel', 'Cancel') ?></a>
                <button type="submit" class="btn btn-primary"><?= $isNew ? __('create_form', 'Create form') : __('save_changes', 'Save changes') ?></button>
            </div>
        </div>
        <?php if (!$isNew): ?>
        <div class="fhead-sub">
            <div class="fhead-stats">
                <span><b><?= number_format($subCount) ?></b> <?= __('forms_submissions', 'Submissions') ?></span>
                <span class="fhead-bullet"></span>
                <span><b><?= number_format($fieldCount) ?></b> <?= __('fields', 'Fields') ?></span>
                <?php if ($createdTxt !== ''): ?><span class="fhead-bullet"></span><span><?= __('forms_edit_created_on', 'Created') ?> <b><?= e($createdTxt) ?></b></span><?php endif; ?>
            </div>
            <div class="fhead-links">
                <a href="<?= e(plugin_url('forms', 'admin/submissions.php')) ?>?form_id=<?= (int)$id ?>"><?= forms_edit_icon('mail', 14) ?> <?= __('forms_submissions', 'Submissions') ?></a>
                <?php if ($state['status'] === 'published' && $publicUrl): ?>
                    <a href="<?= e($publicUrl) ?>" target="_blank"><?= forms_edit_icon('link', 14) ?> <?= __('forms_edit_view_live', 'View live') ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php forms_edit_tabs([
        ['id' => 'general',    'label' => __('general', 'General'),      'icon' => 'box'],
        ['id' => 'fields',     'label' => __('fields', 'Fields'),       'icon' => 'list'],
        ['id' => 'appearance', 'label' => __('forms_edit_appearance', 'Appearance'),   'icon' => 'star'],
        ['id' => 'after',      'label' => __('forms_edit_after_submit', 'After submit'), 'icon' => 'check'],
        ['id' => 'notify',     'label' => __('notifications', 'Notifications'),'icon' => 'mail'],
        ['id' => 'webhooks',   'label' => __('forms_edit_webhooks', 'Webhooks'),     'icon' => 'link'],
        ['id' => 'security',   'label' => __('forms_spam_security_tab', 'Spam & Security'), 'icon' => 'shield'],
    ]); ?>

            <div class="pv-panel" data-panel="general">
            <?php forms_edit_card_open(['icon' => 'box', 'eyebrow' => __('general', 'General'), 'title' => __('forms_edit_form_details', 'Form details')]); ?>

                <div class="field">
                    <label class="field-label" for="title"><?= __('title', 'Title') ?> <span class="field-required">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="200"
                           value="<?= e($state['title']) ?>">
                    <?php if (!empty($fieldErrors['title'])): ?>
                        <div class="field-error"><?= e($fieldErrors['title']) ?></div>
                    <?php endif; ?>
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="slug"><?= __('forms_edit_url_slug', 'URL slug') ?></label>
                        <input type="text" id="slug" name="slug" maxlength="80"
                               value="<?= e($state['slug']) ?>"
                               placeholder="<?= e($isNew ? __('forms_edit_auto_generated_from_title', 'auto-generated from title') : '') ?>">
                        <div class="field-hint"><?= __('forms_edit_slug_hint', 'Lowercase letters / digits / hyphens. Leave blank to auto-generate.') ?></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="status"><?= __('status', 'Status') ?></label>
                        <select id="status" name="status">
                            <option value="draft"     <?= $state['status'] === 'draft'     ? 'selected' : '' ?>><?= __('forms_edit_status_draft', 'Draft (not public)') ?></option>
                            <option value="published" <?= $state['status'] === 'published' ? 'selected' : '' ?>><?= __('forms_edit_status_published', 'Published (live)') ?></option>
                            <option value="archived"  <?= $state['status'] === 'archived'  ? 'selected' : '' ?>><?= __('archived', 'Archived') ?></option>
                        </select>
                    </div>
                </div>

                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="description"><?= __('description', 'Description') ?></label>
                    <textarea id="description" name="description" maxlength="1000"><?= e($state['description']) ?></textarea>
                    <div class="field-hint"><?= __('forms_edit_desc_hint', 'Shown above the form on the public page.') ?></div>
                </div>

            <?php forms_edit_card_close(); ?>

            <?php if (!$isNew && $state['status'] === 'published' && $publicUrl): ?>
                <?php forms_edit_card_open(['icon' => 'link', 'eyebrow' => __('forms_edit_live', 'Live'), 'title' => __('forms_edit_share_embed', 'Share & embed')]); ?>
                    <div class="field">
                        <label class="field-label"><?= __('forms_list_public_url', 'Public URL') ?></label>
                        <p style="margin:0;"><a href="<?= e($publicUrl) ?>" target="_blank"><?= e($publicUrl) ?></a></p>
                    </div>
                    <div class="field">
                        <label class="field-label" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                            <span><?= __('forms_list_embed_snippet', 'Embed snippet') ?> <span class="text-muted text-xs">— <?= __('forms_edit_inline_iframe', 'inline iframe') ?></span></span>
                            <button type="button" class="btn btn-sm snippet-copy"><?= __('copy', 'Copy') ?></button>
                        </label>
                        <pre class="snippet" style="white-space:pre-wrap;">&lt;iframe src=&quot;<?= e($publicUrl) ?>?embed=1&quot;
  style=&quot;width:100%;border:0;min-height:520px&quot;&gt;
&lt;/iframe&gt;</pre>
                    </div>
                    <?php
                    // Popup-button widget: a button that opens the form full-screen
                    // in an overlay iframe. Self-contained — paste anywhere.
                    $popupSnippet =
'<button class="slate-form-popup" data-form="' . $publicUrl . '?embed=1"'
. ' style="padding:12px 22px;border:0;border-radius:10px;background:#2563EB;color:#fff;font:600 15px/1 sans-serif;cursor:pointer">' . __('forms_edit_open_form', 'Open form') . '</button>' . "\n"
. '<script>(function(){document.addEventListener("click",function(e){'
. 'var b=e.target.closest(".slate-form-popup");if(!b)return;var url=b.getAttribute("data-form");'
. 'var o=document.createElement("div");o.style.cssText="position:fixed;inset:0;z-index:2147483600;background:#f4f5f7;display:flex;align-items:center;justify-content:center;padding:clamp(8px,3vw,28px);overflow:auto";'
. 'var m=document.createElement("div");m.style.cssText="position:relative;width:100%;max-width:720px;max-height:94vh";'
. 'var f=document.createElement("iframe");f.src=url;f.style.cssText="display:block;width:100%;height:600px;max-height:94vh;border:0;background:transparent;transition:height .2s ease";'
. 'var c=document.createElement("button");c.setAttribute("aria-label","' . __('close', 'Close') . '");'
. 'c.innerHTML=\'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>\';'
. 'c.style.cssText="position:absolute;top:18px;right:18px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;padding:0;border:0;border-radius:999px;background:rgba(15,23,42,.92);color:#fff;box-shadow:0 6px 22px rgba(0,0,0,.4);cursor:pointer;z-index:2";'
. 'function onMsg(ev){var d=ev.data;if(d&&d.type==="cb-form-height"&&d.height){f.style.height=Math.min(d.height,Math.round(window.innerHeight*0.94))+"px";}}'
. 'function close(){window.removeEventListener("message",onMsg);o.remove();}'
. 'window.addEventListener("message",onMsg);c.addEventListener("click",close);'
. 'o.addEventListener("click",function(ev){if(ev.target===o)close();});'
. 'm.appendChild(f);o.appendChild(m);o.appendChild(c);document.body.appendChild(o);'
. '});})();</script>';
                    ?>
                    <div class="field" style="margin-bottom:0;">
                        <label class="field-label" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                            <span><?= __('forms_edit_popup_button', 'Popup button') ?> <span class="text-muted text-xs">— <?= __('forms_edit_popup_button_desc', 'opens the form full-screen on click') ?></span></span>
                            <button type="button" class="btn btn-sm snippet-copy"><?= __('copy', 'Copy') ?></button>
                        </label>
                        <pre class="snippet" style="white-space:pre-wrap;"><?= e($popupSnippet) ?></pre>
                        <div class="field-hint" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                            <span><?= __('forms_edit_popup_hint', 'Paste this where you want the button. Customise the label/colour in the first line.') ?></span>
                            <button type="button" class="btn btn-sm btn-primary" id="popup-demo" data-form="<?= e($publicUrl) ?>?embed=1"><?= __('forms_edit_preview_popup', 'Preview popup ↗') ?></button>
                        </div>
                    </div>
                <?php forms_edit_card_close(); ?>
            <?php endif; ?>
            </div>

            <div class="pv-panel" data-panel="fields" hidden>
            <?php forms_edit_card_open([
                'icon'       => 'list',
                'eyebrow'    => __('fields', 'Fields'),
                'title'      => __('forms_form_fields', 'Form fields'),
                'aside_html' => '<button type="button" class="btn btn-sm fb-toolbar-toggle" id="fb-compact-toggle" title="' . e(__('forms_edit_collapse_fields', 'Collapse fields to single lines')) . '">' . __('forms_edit_compact_view', 'Compact view') . '</button> <button type="button" class="btn btn-sm btn-primary" id="fb-preview">' . __('forms_edit_preview', 'Preview') . '</button> <button type="button" class="btn btn-sm" id="fb-toggle">' . __('forms_edit_advanced_dsl', 'Advanced (DSL)') . '</button>',
            ]); ?>
            <div id="fb-card">
                <input type="hidden" name="fields_json_data" id="fields_json_data" value="<?= e($state['fields_json']) ?>">

                <?php
                // ── Field palette (icon tiles, grouped) ──────────────
                $fbIcons = [
                    'text'   => '<path d="M5 7h14M5 7v-2h14v2M12 5v14M9 19h6"/>',
                    'para'   => '<path d="M4 6h16M4 11h16M4 16h10"/>',
                    'mail'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
                    'phone'  => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3.1-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
                    'hash'   => '<path d="M4 9h16M4 15h16M10 3L8 21M16 3l-2 18"/>',
                    'select' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 11l4 3 4-3"/>',
                    'radio'  => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5" fill="currentColor" stroke="none"/>',
                    'check'  => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 12l3 3 5-6"/>',
                    'date'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
                    'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
                    'globe'  => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18z"/>',
                    'slider' => '<path d="M4 8h10M18 8h2M4 16h2M10 16h10"/><circle cx="16" cy="8" r="2.4"/><circle cx="8" cy="16" r="2.4"/>',
                    'star'   => '<path d="M12 3l2.6 5.6L21 9.3l-4.5 4.2L17.7 21 12 17.6 6.3 21l1.2-7.5L3 9.3l6.4-.7z"/>',
                    'calc'   => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 12h2M8 16h2M14 12h2M14 16h2"/>',
                    'file'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 9l5-5 5 5M12 4v12"/>',
                    'sign'   => '<path d="M3 19c3-1 5-9 7-9s2 6 4 6 3-3 5-3"/><path d="M3 21h18"/>',
                    'section'=> '<path d="M4 7h16M4 12h16M4 17h16" opacity=".4"/><path d="M4 12h16"/>',
                    'page'   => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
                    'hidden' => '<path d="M3 3l18 18M10.6 10.6a2 2 0 0 0 2.8 2.8M9.4 5.2A9.6 9.6 0 0 1 12 5c5 0 9 4.5 9 7a12 12 0 0 1-2.2 3M6.1 6.1A12.7 12.7 0 0 0 3 12c0 2.5 4 7 9 7a9.6 9.6 0 0 0 3-.5"/>',
                    'accept' => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/>',
                ];
                $fbIcon = function (string $n) use ($fbIcons): string {
                    $p = $fbIcons[$n] ?? '<circle cx="12" cy="12" r="9"/>';
                    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" '
                         . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
                };
                $fbGroups = [
                    ['label' => __('forms_edit_group_standard', 'Standard fields'), 'items' => [
                        ['text', __('forms_edit_field_text', 'Single Line Text'), 'text'],
                        ['textarea', __('forms_edit_field_textarea', 'Paragraph Text'), 'para'],
                        ['select', __('forms_edit_field_select', 'Drop Down'), 'select'],
                        ['radio', __('forms_edit_field_radio', 'Radio Buttons'), 'radio'],
                        ['checkboxes', __('forms_edit_field_checkboxes', 'Multi-select'), 'check'],
                        ['checkbox', __('forms_edit_field_checkbox', 'Checkbox (single)'), 'check'],
                        ['number', __('forms_edit_field_number', 'Number'), 'hash'],
                        ['email', __('email', 'Email'), 'mail'],
                        ['tel', __('auth_phone', 'Phone'), 'phone'],
                        ['intlphone', __('forms_edit_field_intlphone', 'Phone (intl)'), 'phone'],
                        ['address', __('forms_edit_field_address', 'Address (autocomplete)'), 'home'],
                        ['date', __('forms_edit_field_date', 'Date'), 'date'],
                        ['time', __('forms_edit_field_time', 'Time'), 'clock'],
                        ['url', __('forms_edit_field_url', 'Website'), 'globe'],
                    ]],
                    ['label' => __('forms_edit_group_advanced', 'Advanced fields'), 'items' => [
                        ['range', __('forms_edit_field_range', 'Slider'), 'slider'],
                        ['rating', __('forms_edit_field_rating', 'Rating'), 'star'],
                        ['calc', __('forms_edit_field_calc', 'Calculated'), 'calc'],
                        ['file', __('forms_edit_field_file', 'File Upload'), 'file'],
                        ['signature', __('forms_signature', 'Signature'), 'sign'],
                        ['disclaimer', __('forms_edit_field_disclaimer', 'Acceptance'), 'accept'],
                        ['heading', __('forms_edit_field_heading', 'Section'), 'section'],
                        ['step', __('forms_edit_field_step', 'Page Break'), 'page'],
                        ['hidden', __('forms_edit_field_hidden', 'Hidden'), 'hidden'],
                    ]],
                ];
                ?>
                <div class="fb2" id="fb-builder">
                    <div class="fb2-canvas" id="fb-canvas">
                        <div class="fb-list" id="fb-list"></div>
                        <div class="fb-empty" id="fb-empty">
                            <svg class="fb-empty-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                            <div class="fb-empty-t"><?= __('forms_edit_drag_field_here', 'Drag a field here to start building your form') ?></div>
                            <div class="fb-empty-s"><?= __('forms_edit_or_tap_field', '…or tap a field on the right to add it.') ?></div>
                        </div>
                    </div>
                    <aside class="fb2-side" id="fb-side">
                        <!-- Add-fields palette (shown when no field is selected) -->
                        <div id="fb-side-palette">
                            <div class="fb2-hint">
                                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l7 18 2-8 8-2z"/></svg>
                                <span><?= __('forms_edit_tap_or_drag_hint', 'Tap or drag a field onto the canvas. Select a field to edit its settings here.') ?></span>
                            </div>
                            <?php foreach ($fbGroups as $gi => $g): ?>
                            <div class="fb2-group is-open" data-fb-group>
                                <button type="button" class="fb2-group-head" data-fb-group-toggle>
                                    <span><?= e($g['label']) ?></span>
                                    <svg class="fb2-chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="fb2-tiles">
                                    <?php foreach ($g['items'] as $it): ?>
                                    <button type="button" class="fb-add fb2-tile" data-type="<?= e($it[0]) ?>" title="<?= e($it[1]) ?>">
                                        <span class="fb2-tile-ico"><?= $fbIcon($it[2]) ?></span>
                                        <span class="fb2-tile-label"><?= e($it[1]) ?></span>
                                    </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Field settings (shown when a field is selected) -->
                        <div id="fb-side-settings" hidden>
                            <div class="fb2-set-head">
                                <span class="fb2-set-badge" id="fb-set-type"><?= __('forms_edit_field_word', 'Field') ?></span>
                                <span class="fb2-set-title"><?= __('settings', 'settings') ?></span>
                                <span class="fb2-set-sp"></span>
                                <button type="button" class="fb-move" id="fb-set-up" title="<?= e(__('forms_edit_move_up', 'Move up')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 15 12 9 18 15"/></svg></button>
                                <button type="button" class="fb-move" id="fb-set-down" title="<?= e(__('forms_edit_move_down', 'Move down')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg></button>
                                <button type="button" class="fb2-set-done" id="fb-set-done"><?= __('forms_status_done', 'Done') ?></button>
                            </div>
                            <div id="fb-set-body"></div>
                        </div>
                    </aside>
                </div>

                <!-- Always-posted source of truth; the builder writes here. Power
                     users can edit it directly via the Advanced toggle. -->
                <div class="field" id="fb-advanced" hidden>
                    <label class="field-label" for="fields_dsl"><?= __('forms_edit_field_dsl_label', 'Field DSL') ?> — <code>type|name|label|required|placeholder|options</code></label>
                    <textarea id="fields_dsl" name="fields_dsl" rows="14"
                              style="font-family:var(--font-mono); font-size:12.5px; min-height:240px;"
                              spellcheck="false"><?= e($state['fields_dsl']) ?></textarea>
                    <div class="field-hint">
                        <?= __('forms_edit_dsl_supported_types', 'Supported types: text, email, tel, url, number, date, time, textarea, select, radio, checkbox, range, rating, file, signature, heading, hidden.') ?>
                        <?= __('forms_edit_dsl_options_col', 'Options column = comma-separated choices') ?> (<code>select</code>/<code>radio</code>),
                        <code>min,max,step</code> <?= __('forms_edit_dsl_range', '(range) or max-stars (rating). Switch back to the visual builder to sync.') ?>
                    </div>
                    <ul class="kv-list" style="margin-top:12px;border-top:1px solid var(--border);padding-top:12px;">
                        <li class="kv-row"><span class="kv-label kv-mono">type</span><span class="kv-value">text · email · tel · url · number · date · time · textarea · select · radio · checkbox · range · rating · calc · file · signature · heading · step · hidden</span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">advanced</span><span class="kv-value"><?= __('forms_edit_dsl_advanced_note', 'conditional visibility, calculated formulas and step breaks are set in the') ?> <strong><?= __('forms_edit_visual_builder', 'visual builder') ?></strong> <?= __('forms_edit_dsl_not_dsl', '(not the DSL)') ?></span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">name</span><span class="kv-value"><?= __('forms_edit_dsl_name_hint', 'lowercase, letters/digits/underscores') ?></span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">label</span><span class="kv-value"><?= __('forms_edit_dsl_label_hint', 'shown next to the input') ?></span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">required</span><span class="kv-value"><?= __('forms_edit_dsl_required_hint', 'literal word') ?> <code>required</code></span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">placeholder</span><span class="kv-value"><?= __('forms_edit_dsl_placeholder_hint', 'optional hint') ?></span></li>
                        <li class="kv-row"><span class="kv-label kv-mono">options</span><span class="kv-value"><?= __('forms_edit_dsl_options_hint', 'for select/radio, comma-separated') ?></span></li>
                    </ul>
                </div>
                <?php if (!empty($fieldErrors['fields_dsl'])): ?>
                    <div class="field-error" style="margin-top:8px;"><?= e($fieldErrors['fields_dsl']) ?></div>
                <?php endif; ?>
            </div>
            <?php forms_edit_card_close(); ?>
            </div>

            <div class="pv-panel" data-panel="appearance" hidden>

            <style>
            .fdesign-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;}
            .fdesign{text-align:left;border:1px solid var(--border);border-radius:13px;background:var(--card,#fff);padding:10px;cursor:pointer;transition:border-color .12s,box-shadow .12s,transform .1s;display:flex;flex-direction:column;gap:8px;}
            .fdesign:hover{border-color:var(--border-stronger,#CFD2D8);box-shadow:var(--shadow-sm);}
            .fdesign.is-on{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent);}
            .fdesign-prev{display:block;border:1px solid var(--border);border-radius:9px;background:#fff;padding:11px 11px 12px;}
            .fp-line{display:block;height:8px;border-radius:4px;background:#EAEDF1;margin-bottom:7px;}
            .fp-line.short{width:62%;}
            .fdesign-prev[data-fs="filled"] .fp-line{background:#E2E6EE;}
            .fp-btn{display:block;text-align:center;font-size:9px;font-weight:700;color:#fff;background:var(--acc,#2563EB);padding:6px;border-radius:7px;margin-top:9px;letter-spacing:.02em;}
            .fdesign-prev[data-shape="pill"] .fp-btn{border-radius:999px;}
            .fdesign-prev[data-shape="square"] .fp-btn{border-radius:0;}
            .fdesign-prev[data-shape="square"] .fp-line{border-radius:0;}
            .fdesign-meta{display:flex;align-items:center;gap:7px;}
            .fdesign-sw{width:14px;height:14px;border-radius:5px;flex:none;background:var(--acc,#2563EB);box-shadow:inset 0 0 0 1px rgba(0,0,0,.08);}
            .fdesign-name{font-size:12.5px;font-weight:650;letter-spacing:-.01em;color:var(--text);}
            .fdesign-desc{font-size:11px;color:var(--muted);line-height:1.4;}
            .fdesign-on{margin-left:auto;color:var(--accent);display:none;}
            .fdesign.is-on .fdesign-on{display:block;}
            </style>
            <?php forms_edit_card_open(['icon' => 'star', 'eyebrow' => __('forms_edit_designs', 'Designs'), 'title' => __('forms_edit_pick_a_design', 'Pick a design')]); ?>
                <p class="text-muted text-sm" style="margin:-2px 0 12px;"><?= __('forms_edit_design_hint', 'One click applies a ready-made look — accent, fields, corners and motion. Fine-tune anything below.') ?></p>
                <div class="fdesign-grid">
                    <?php foreach (FormDesigns::all() as $d):
                        $sw = $d['swatch'] ?? ($d['accent'] ?: '#2563EB');
                    ?>
                    <button type="button" class="fdesign"
                            data-accent="<?= e((string)$d['accent']) ?>"
                            data-field-style="<?= e($d['field_style']) ?>"
                            data-shape="<?= e($d['shape']) ?>"
                            data-density="<?= e($d['density']) ?>"
                            data-animate="<?= !empty($d['animate']) ? '1' : '0' ?>"
                            data-labels="<?= e($d['labels']) ?>">
                        <span class="fdesign-prev" data-shape="<?= e($d['shape']) ?>" data-fs="<?= e($d['field_style']) ?>" style="--acc:<?= e($sw) ?>;">
                            <span class="fp-line"></span>
                            <span class="fp-line short"></span>
                            <span class="fp-btn"><?= __('forms_submit', 'Submit') ?></span>
                        </span>
                        <div class="fdesign-meta">
                            <span class="fdesign-sw" style="--acc:<?= e($sw) ?>;"></span>
                            <span class="fdesign-name"><?= e($d['name']) ?></span>
                            <svg class="fdesign-on" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <span class="fdesign-desc"><?= e($d['desc']) ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <script>
                (function(){
                    function setVal(id, v){ var el = document.getElementById(id); if (el && v != null) el.value = v; }
                    function darken(hex, f){
                        hex = String(hex || '').replace('#',''); if (hex.length === 3) hex = hex.replace(/(.)/g,'$1$1');
                        var n = parseInt(hex, 16); if (isNaN(n)) return '#1D4ED8';
                        var r = Math.round(((n>>16)&255)*(1-f)), g = Math.round(((n>>8)&255)*(1-f)), b = Math.round((n&255)*(1-f));
                        return '#' + ((1<<24)+(r<<16)+(g<<8)+b).toString(16).slice(1);
                    }
                    Array.prototype.forEach.call(document.querySelectorAll('.fdesign'), function(btn){
                        btn.addEventListener('click', function(){
                            Array.prototype.forEach.call(document.querySelectorAll('.fdesign'), function(b){ b.classList.remove('is-on'); });
                            btn.classList.add('is-on');
                            var acc = btn.getAttribute('data-accent') || '';
                            var on  = document.getElementById('fs_accent_on');
                            if (on) { on.checked = acc !== ''; on.dispatchEvent(new Event('change')); }
                            if (acc) {
                                var ai = document.getElementById('fs_accent');       if (ai) ai.value = acc;
                                var ah = document.getElementById('fs_accent_hover');  if (ah) ah.value = darken(acc, 0.15);
                            }
                            setVal('fs_field_style', btn.getAttribute('data-field-style'));
                            setVal('fs_shape',       btn.getAttribute('data-shape'));
                            setVal('fs_density',     btn.getAttribute('data-density'));
                            var an = document.querySelector('[name="fs_animate"]');     if (an) an.checked = btn.getAttribute('data-animate') === '1';
                            var hl = document.querySelector('[name="fs_hide_labels"]'); if (hl) hl.checked = btn.getAttribute('data-labels') === 'hide';
                        });
                    });
                })();
                </script>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'star', 'eyebrow' => __('forms_edit_appearance', 'Appearance'), 'title' => __('forms_edit_look_feel', 'Look & feel')]); ?>
                <div class="field">
                    <label class="field-label" for="fs_density"><?= __('forms_edit_density', 'Density') ?></label>
                    <select id="fs_density" name="fs_density">
                        <option value="comfortable" <?= $fset['density'] === 'comfortable' ? 'selected' : '' ?>><?= __('forms_edit_comfortable', 'Comfortable (roomy)') ?></option>
                        <option value="compact"     <?= $fset['density'] === 'compact'     ? 'selected' : '' ?>><?= __('forms_edit_compact', 'Compact (tighter)') ?></option>
                    </select>
                    <div class="field-hint"><?= __('forms_edit_density_hint', 'Compact tightens spacing, padding and type sizes on the public form.') ?></div>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_animate" value="1" <?= $fset['animate'] ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_step_animations', 'Step animations') ?> <span class="text-muted text-xs">— <?= __('forms_edit_step_animations_desc', 'directional slide + staggered field entrance') ?></span></span>
                    </label>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_validate" value="1" <?= $fset['validate'] ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_inline_validation', 'Inline validation') ?> <span class="text-muted text-xs">— <?= __('forms_edit_inline_validation_desc', 'live green-check / red state as visitors type') ?></span></span>
                    </label>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_rail" value="1" <?= $fset['rail'] ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_numbered_rail', 'Numbered step rail') ?> <span class="text-muted text-xs">— <?= __('forms_edit_numbered_rail_desc', '①②③ progress dots on multi-step forms') ?></span></span>
                    </label>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_scroll_fields" value="1" <?= !empty($fset['scroll_fields']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_scrollable_fields', 'Scrollable fields region') ?> <span class="text-muted text-xs">— <?= __('forms_edit_scrollable_fields_desc', 'keep fields in a fixed-height scroll area. Turn OFF to let the form flow at full height (recommended for embeds — avoids a gap under the button)') ?></span></span>
                    </label>
                </div>
                <div class="field" style="margin-bottom:8px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_full_width" value="1" <?= !empty($fset['full_width']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_full_width_form', 'Full-width form') ?> <span class="text-muted text-xs">— <?= __('forms_edit_full_width_form_desc', 'fill the container instead of a centered max-width column (best for wide embeds)') ?></span></span>
                    </label>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_summary" value="1" <?= !empty($fset['summary']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_review_summary_step', 'Review &amp; summary step') ?> <span class="text-muted text-xs">— <?= __('forms_edit_review_summary_step_desc', 'add a final read-only "Review your answers" step before submit') ?></span></span>
                    </label>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php
                $accOn = $fset['accent'] !== '';
                $accVal = $fset['accent'] ?: '#2563EB';
                $accHov = $fset['accent_hover'] ?: ($fset['accent'] ? FormsAPI::darkenHex($fset['accent'], 0.12) : '#1D4ED8');
            ?>
            <?php forms_edit_card_open(['icon' => 'tag', 'eyebrow' => __('forms_edit_style', 'Style'), 'title' => __('forms_edit_colors_components', 'Colors & components')]); ?>
                <div class="field" style="margin-bottom:10px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" id="fs_accent_on" name="fs_accent_on" value="1" <?= $accOn ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_custom_brand_colors', 'Custom brand colors') ?> <span class="text-muted text-xs">— <?= __('forms_edit_custom_brand_colors_desc', 'override the theme accent') ?></span></span>
                    </label>
                </div>
                <div class="field-row field-row-2" id="fs_accent_row" style="<?= $accOn ? '' : 'opacity:.45;pointer-events:none;' ?>">
                    <div class="field">
                        <label class="field-label" for="fs_accent"><?= __('forms_edit_primary_color', 'Primary color') ?></label>
                        <input type="color" id="fs_accent" name="fs_accent" value="<?= e($accVal) ?>" style="width:100%;height:40px;padding:3px;cursor:pointer;">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_accent_hover"><?= __('forms_edit_hover_darker', 'Hover / darker') ?></label>
                        <input type="color" id="fs_accent_hover" name="fs_accent_hover" value="<?= e($accHov) ?>" style="width:100%;height:40px;padding:3px;cursor:pointer;">
                    </div>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="fs_field_style"><?= __('forms_edit_field_style', 'Field style') ?></label>
                        <select id="fs_field_style" name="fs_field_style">
                            <option value="outline" <?= $fset['field_style'] === 'outline' ? 'selected' : '' ?>><?= __('forms_edit_outlined_white', 'Outlined (white)') ?></option>
                            <option value="filled"  <?= $fset['field_style'] === 'filled'  ? 'selected' : '' ?>><?= __('forms_edit_filled_grey', 'Filled (soft grey)') ?></option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_shape"><?= __('forms_edit_corners', 'Corners') ?></label>
                        <select id="fs_shape" name="fs_shape">
                            <option value="rounded" <?= $fset['shape'] === 'rounded' ? 'selected' : '' ?>><?= __('forms_edit_rounded', 'Rounded') ?></option>
                            <option value="pill"    <?= $fset['shape'] === 'pill'    ? 'selected' : '' ?>><?= __('forms_edit_pill_buttons', 'Pill buttons') ?></option>
                            <option value="square"  <?= $fset['shape'] === 'square'  ? 'selected' : '' ?>><?= __('forms_edit_square', 'Square') ?></option>
                        </select>
                    </div>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_hide_labels" value="1" <?= $fset['labels'] === 'hide' ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_hide_field_labels', 'Hide field labels') ?> <span class="text-muted text-xs">— <?= __('forms_edit_hide_field_labels_desc', 'rely on placeholders (kept for screen readers)') ?></span></span>
                    </label>
                </div>
                <script>
                (function(){
                    var on = document.getElementById('fs_accent_on'), row = document.getElementById('fs_accent_row');
                    if (on && row) on.addEventListener('change', function(){ row.style.opacity = on.checked ? '' : '.45'; row.style.pointerEvents = on.checked ? '' : 'none'; });
                })();
                </script>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'box', 'eyebrow' => __('forms_edit_spacing', 'Spacing'), 'title' => __('forms_edit_padding_gaps', 'Padding & gaps')]); ?>
                <p class="text-muted text-xs" style="margin:-2px 0 12px;"><?= __('forms_edit_spacing_hint', 'Leave blank to use the theme default. All values in pixels — handy for making an embedded form fit its container.') ?></p>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="fs_pad"><?= __('forms_edit_form_padding', 'Form padding') ?></label>
                        <input type="number" id="fs_pad" name="fs_pad" min="0" max="80" placeholder="<?= e(__('forms_edit_default_ph', 'default')) ?>" value="<?= $fset['pad'] !== null ? (int)$fset['pad'] : '' ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_field_gap"><?= __('forms_edit_field_spacing', 'Field spacing') ?></label>
                        <input type="number" id="fs_field_gap" name="fs_field_gap" min="0" max="60" placeholder="<?= e(__('forms_edit_default_ph', 'default')) ?>" value="<?= $fset['field_gap'] !== null ? (int)$fset['field_gap'] : '' ?>">
                    </div>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="fs_col_gap"><?= __('forms_edit_column_gap', 'Column gap') ?> <span class="text-muted text-xs">— <?= __('forms_edit_column_gap_desc', 'horizontal space between two-column fields') ?></span></label>
                    <input type="number" id="fs_col_gap" name="fs_col_gap" min="0" max="80" placeholder="<?= e(__('forms_edit_default_ph', 'default')) ?>" value="<?= $fset['col_gap'] !== null ? (int)$fset['col_gap'] : '' ?>">
                </div>
            <?php forms_edit_card_close(); ?>
            </div>

            <div class="pv-panel" data-panel="after" hidden>
            <?php forms_edit_card_open(['icon' => 'check', 'eyebrow' => __('forms_edit_after_submit', 'After submit'), 'title' => __('forms_edit_confirmation_redirect', 'Confirmation & redirect')]); ?>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="submit_label"><?= __('forms_edit_submit_button_label', 'Submit button label') ?></label>
                        <input type="text" id="submit_label" name="submit_label" maxlength="80"
                               value="<?= e($state['submit_label']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="submission_limit"><?= __('forms_edit_submission_limit', 'Submission limit') ?></label>
                        <input type="number" id="submission_limit" name="submission_limit" min="0"
                               value="<?= e($state['submission_limit']) ?>"
                               placeholder="<?= e(__('unlimited', 'unlimited')) ?>">
                        <div class="field-hint"><?= __('forms_edit_submission_limit_hint', 'Form closes once this many submissions land.') ?></div>
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="success_message"><?= __('forms_edit_success_message', 'Success message') ?></label>
                    <textarea id="success_message" name="success_message" maxlength="1000"><?= e($state['success_message']) ?></textarea>
                    <div class="field-hint"><?= __('forms_edit_success_message_hint', 'Shown on the thank-you page after a successful submit (unless redirect URL is set).') ?></div>
                </div>

                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="redirect_url"><?= __('forms_edit_redirect_url', 'Redirect URL') ?> <span class="text-muted text-xs"><?= __('forms_edit_optional_paren', '(optional)') ?></span></label>
                    <input type="url" id="redirect_url" name="redirect_url" maxlength="500"
                           value="<?= e($state['redirect_url']) ?>"
                           placeholder="https://example.com/thanks">
                    <?php if (!empty($fieldErrors['redirect_url'])): ?>
                        <div class="field-error"><?= e($fieldErrors['redirect_url']) ?></div>
                    <?php endif; ?>
                    <div class="field-hint"><?= __('forms_edit_redirect_url_hint', 'If set, the submitter is redirected here instead of seeing the success message.') ?></div>
                </div>

            <?php forms_edit_card_close(); ?>
            </div>

            <div class="pv-panel" data-panel="notify" hidden>
            <?php forms_edit_card_open(['icon' => 'mail', 'eyebrow' => __('notifications', 'Notifications'), 'title' => __('email', 'Email')]); ?>

                <div class="field">
                    <label class="field-label" for="notify_email"><?= __('forms_edit_notify_email', 'Notify email') ?></label>
                    <input type="email" id="notify_email" name="notify_email" maxlength="200"
                           value="<?= e($state['notify_email']) ?>"
                           placeholder="you@example.com">
                    <?php if (!empty($fieldErrors['notify_email'])): ?>
                        <div class="field-error"><?= e($fieldErrors['notify_email']) ?></div>
                    <?php endif; ?>
                    <div class="field-hint"><?= __('forms_edit_notify_email_hint', 'Where to send a copy of each submission. Leave blank to skip.') ?></div>
                </div>

                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="confirm_submitter" value="1"
                               <?= $state['confirm_submitter'] ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_confirm_submitter_pre', 'Email the submitter a confirmation (uses the form\'s') ?> <code>email</code> <?= __('forms_edit_confirm_submitter_post', 'field)') ?></span>
                    </label>
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="confirm_subject"><?= __('forms_edit_confirmation_subject', 'Confirmation subject') ?></label>
                        <input type="text" id="confirm_subject" name="confirm_subject" maxlength="200"
                               value="<?= e($state['confirm_subject']) ?>"
                               placeholder="<?= e(__('forms_edit_we_got_your_message', 'We got your message')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="confirm_body"><?= __('forms_edit_confirmation_body', 'Confirmation body (HTML)') ?></label>
                    <textarea id="confirm_body" name="confirm_body" rows="4"><?= e($state['confirm_body']) ?></textarea>
                    <div class="field-hint"><?= __('forms_edit_confirmation_body_hint_pre', 'Sent to the submitter when the checkbox above is on and the form has an') ?> <code>email</code> <?= __('forms_edit_confirmation_body_hint_post', 'field.') ?></div>
                </div>

            <?php forms_edit_card_close(); ?>

            <?php
            // ── Email template customizer ─────────────────────────────────
            // Per-form overrides for the admin notification email. Empty values
            // fall back to the built-in defaults (subject = "New submission · …",
            // header eyebrow = "New form submission", CTA = "View submission",
            // field table shown).
            $placeholderHint = '<code>{{form.title}}</code> · <code>{{ref}}</code> · <code>{{date}}</code> · <code>{{submitter}}</code> · <code>{{data.field_name}}</code>';
            ?>
            <?php forms_edit_card_open([
                'icon'       => 'mail',
                'eyebrow'    => __('forms_edit_notification_template', 'Notification template'),
                'title'      => __('forms_edit_admin_email_content', 'Admin email content'),
                'aside_html' => '<span class="text-xs text-muted">' . __('forms_edit_customize_subject_body', 'Customize subject &amp; body') . '</span>',
            ]); ?>
                <div class="field-hint" style="margin-bottom:14px;">
                    <?= __('forms_edit_notify_template_hint', 'Sent to the notify address above when a submission lands. Leave a field blank to use the default. Placeholders:') ?> <?= $placeholderHint ?>.
                </div>

                <div class="field">
                    <label class="field-label" for="fs_email_subject"><?= __('forms_edit_subject_line', 'Subject line') ?></label>
                    <input type="text" id="fs_email_subject" name="fs_email_subject" maxlength="200"
                           value="<?= e($fset['email_subject'] ?? '') ?>"
                           placeholder="<?= e(__('forms_edit_ph_subject', 'New submission · {{form.title}} ({{ref}})')) ?>">
                </div>

                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="fs_email_header_label"><?= __('forms_edit_header_eyebrow', 'Header eyebrow') ?></label>
                        <input type="text" id="fs_email_header_label" name="fs_email_header_label" maxlength="80"
                               value="<?= e($fset['email_header_label'] ?? '') ?>"
                               placeholder="<?= e(__('forms_email_new_submission', 'New form submission')) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_email_cta_label"><?= __('forms_edit_view_submission_btn_label', '"View submission" button label') ?></label>
                        <input type="text" id="fs_email_cta_label" name="fs_email_cta_label" maxlength="60"
                               value="<?= e($fset['email_cta_label'] ?? '') ?>"
                               placeholder="<?= e(__('forms_email_view_submission', 'View submission')) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label" for="fs_email_intro"><?= __('forms_edit_intro_paragraph', 'Intro paragraph') ?> <span class="text-muted text-xs"><?= __('forms_edit_above_field_table', '(above the field table)') ?></span></label>
                    <textarea id="fs_email_intro" name="fs_email_intro" rows="3"
                              placeholder="<?= e(__('forms_edit_ph_email_intro', 'Hi team — a new {{form.title}} just came in from {{submitter}}.')) ?>"><?= e($fset['email_intro'] ?? '') ?></textarea>
                </div>

                <div class="field">
                    <label class="field-label" for="fs_email_outro"><?= __('forms_edit_outro_paragraph', 'Outro paragraph') ?> <span class="text-muted text-xs"><?= __('forms_edit_below_field_table', '(below the field table)') ?></span></label>
                    <textarea id="fs_email_outro" name="fs_email_outro" rows="3"
                              placeholder="<?= e(__('forms_edit_ph_email_outro', 'Reply directly to this email to follow up — the submitter is BCC\'d.')) ?>"><?= e($fset['email_outro'] ?? '') ?></textarea>
                </div>

                <div class="field" style="margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_email_show_table" value="1"
                               <?= !empty($fset['email_show_table']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_include_field_table_pre', 'Include the') ?> <strong><?= __('forms_edit_field_by_field', 'field-by-field') ?></strong> <?= __('forms_edit_include_field_table_post', 'table in the body') ?></span>
                    </label>
                    <div class="field-hint"><?= __('forms_edit_include_field_table_hint', 'Turn off for short notifications that only need the intro / CTA. Submitted values are still in the saved submission.') ?></div>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open([
                'icon'       => 'box',
                'eyebrow'    => __('forms_edit_pdf_customizer', 'PDF customizer'),
                'title'      => __('forms_edit_submission_pdf', 'Submission PDF'),
                'aside_html' => '<span class="text-xs text-muted">' . __('forms_edit_pdf_customizer_desc', 'Heading, recital, signatures, page size') . '</span>',
            ]); ?>

                <div class="field">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_pdf_attach" value="1"
                               <?= !empty($fset['pdf_attach']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_attach_pdf_pre', 'Attach a branded') ?> <strong>PDF</strong> <?= __('forms_edit_attach_pdf_post', 'of the submission to the notification &amp; confirmation emails') ?></span>
                    </label>
                    <div class="field-hint"><?= __('forms_edit_attach_pdf_hint', 'A professional, brand-styled PDF (logo, colours, signature) is generated for each submission and attached to the admin notification and the submitter confirmation. You can also download it from any submission.') ?></div>
                </div>

                <div class="field-hint" style="margin-top:14px;margin-bottom:14px;border-top:1px solid var(--line,#e5e7eb);padding-top:14px;">
                    <?= __('forms_edit_pdf_override_hint', 'Override the title, recital and footer printed on the PDF. Leave blank to use the form\'s defaults. Placeholders:') ?> <?= $placeholderHint ?>.
                </div>

                <div class="field">
                    <label class="field-label" for="fs_pdf_heading"><?= __('forms_edit_pdf_heading', 'PDF heading') ?> <span class="text-muted text-xs"><?= __('forms_edit_pdf_heading_desc', '(replaces the form title)') ?></span></label>
                    <input type="text" id="fs_pdf_heading" name="fs_pdf_heading" maxlength="200"
                           value="<?= e($fset['pdf_heading'] ?? '') ?>"
                           placeholder="{{form.title}}">
                </div>

                <div class="field">
                    <label class="field-label" for="fs_pdf_intro"><?= __('forms_edit_recital_intro', 'Recital / introduction') ?></label>
                    <textarea id="fs_pdf_intro" name="fs_pdf_intro" rows="3"
                              placeholder="<?= e(__('forms_edit_ph_pdf_intro', 'Particulars submitted through the &quot;{{form.title}}&quot; form…')) ?>"><?= e($fset['pdf_intro'] ?? '') ?></textarea>
                    <div class="field-hint"><?= __('forms_edit_pdf_intro_hint', 'Shown directly under the heading. If blank, the standard "Particulars submitted through the … form" sentence is used.') ?></div>
                </div>

                <div class="field">
                    <label class="field-label" for="fs_pdf_footer_note"><?= __('forms_edit_footer_note', 'Footer note') ?> <span class="text-muted text-xs"><?= __('forms_edit_footer_note_desc', '(printed alongside the business line)') ?></span></label>
                    <input type="text" id="fs_pdf_footer_note" name="fs_pdf_footer_note" maxlength="200"
                           value="<?= e($fset['pdf_footer_note'] ?? '') ?>"
                           placeholder="<?= e(__('forms_edit_ph_footer_note', 'e.g. Confidential — for internal use only')) ?>">
                </div>

                <div class="field-row field-row-2" style="margin-top:8px;border-top:1px solid var(--line,#e5e7eb);padding-top:16px;">
                    <div class="field">
                        <label class="field-label" for="fs_pdf_page"><?= __('forms_edit_pdf_page_layout', 'PDF page layout') ?></label>
                        <select id="fs_pdf_page" name="fs_pdf_page">
                            <option value="a4"     <?= ($fset['pdf_page'] ?? 'a4') === 'a4'     ? 'selected' : '' ?>><?= __('forms_edit_a4', 'A4 (210 × 297 mm)') ?></option>
                            <option value="letter" <?= ($fset['pdf_page'] ?? '') === 'letter'   ? 'selected' : '' ?>><?= __('forms_edit_us_letter', 'US Letter (8.5 × 11 in)') ?></option>
                            <option value="legal"  <?= ($fset['pdf_page'] ?? '') === 'legal'    ? 'selected' : '' ?>><?= __('forms_edit_us_legal', 'US Legal (8.5 × 14 in)') ?></option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_pdf_save_btn"><?= __('forms_edit_save_pdf_btn_label', '"Save PDF" button on submission') ?></label>
                        <select id="fs_pdf_save_btn" name="fs_pdf_save_btn">
                            <option value="always" <?= ($fset['pdf_save_btn'] ?? 'always') === 'always' ? 'selected' : '' ?>><?= __('forms_edit_always_show', 'Always show') ?></option>
                            <option value="never"  <?= ($fset['pdf_save_btn'] ?? '') === 'never'         ? 'selected' : '' ?>><?= __('forms_edit_hide_always', 'Hide always') ?></option>
                        </select>
                    </div>
                </div>

                <div class="field" style="margin-top:16px;margin-bottom:0;border-top:1px solid var(--line,#e5e7eb);padding-top:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_pdf_sign" value="1"
                               <?= !empty($fset['pdf_sign']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_add_signature_area_pre', 'Add a') ?> <strong><?= __('forms_edit_signature_area', 'signature area') ?></strong> <?= __('forms_edit_add_signature_area_post', 'to the PDF') ?></span>
                    </label>
                    <div class="field-hint"><?= __('forms_edit_signature_area_hint', 'When on, the document always ends with a signature line — even if the form has no signature field — so it can be signed by hand. Turn off to omit the signature block entirely.') ?></div>
                </div>

                <div class="field" style="margin-top:12px;margin-bottom:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="fs_pdf_sign_both" value="1"
                               <?= !empty($fset['pdf_sign_both']) ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_require_both_pre', 'Require signatures from') ?> <strong><?= __('forms_edit_both_parties', 'both parties') ?></strong></span>
                    </label>
                    <div class="field-hint"><?= __('forms_edit_both_parties_hint', 'Prints two signature columns — the client and your company representative (from the Sender profile below), the original agreement layout. Leave off for a single signature line.') ?></div>
                </div>

            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'user', 'eyebrow' => __('forms_edit_e_signature', 'E-Signature'), 'title' => __('forms_edit_sender_profile', 'Sender profile'), 'aside_html' => '<span class="text-xs text-muted">' . __('forms_edit_printed_on_pdf', 'Printed on the agreement PDF') . '</span>']); ?>
                <div class="field-hint" style="margin-bottom:14px;"><?= __('forms_edit_sender_profile_hint', 'Identity shown as the document sender on the generated PDF when the form collects a signature.') ?></div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="fs_sender_name"><?= __('from_name', 'Sender name') ?></label>
                        <input type="text" id="fs_sender_name" name="fs_sender_name" maxlength="120"
                               value="<?= e($fset['sender']['name'] ?? '') ?>" placeholder="<?= e(__('forms_edit_sender_name_ph', 'Jane Doe')) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="fs_sender_company"><?= __('business_name', 'Company name') ?></label>
                        <input type="text" id="fs_sender_company" name="fs_sender_company" maxlength="160"
                               value="<?= e($fset['sender']['company'] ?? '') ?>" placeholder="<?= e(__('forms_edit_sender_company_ph', 'Your Company')) ?>">
                    </div>
                </div>
                <div class="field" style="margin-bottom:16px;">
                    <label class="field-label" for="fs_sender_email"><?= __('from_email', 'Sender email') ?></label>
                    <input type="email" id="fs_sender_email" name="fs_sender_email" maxlength="200"
                           value="<?= e($fset['sender']['email'] ?? '') ?>" placeholder="you@example.com">
                </div>

                <style>
                #sender-sig{border:1.5px solid var(--line,#e5e7eb);border-radius:12px;overflow:hidden;background:#fff;max-width:460px;}
                #sender-sig .sig-tabs{display:flex;align-items:center;gap:6px;padding:8px 10px;border-bottom:1px solid #eef1f5;background:#f7f8fa;}
                #sender-sig .sig-tab{border:0;background:transparent;padding:6px 14px;border-radius:8px;font:600 13px/1 inherit;color:#64748b;cursor:pointer;}
                #sender-sig .sig-tab.is-active{background:#2563eb;color:#fff;}
                #sender-sig .sig-upload{margin-left:auto;display:inline-flex;align-items:center;font:600 12.5px/1 inherit;color:#64748b;cursor:pointer;padding:6px 10px;border-radius:8px;}
                #sender-sig .sig-upload:hover{background:#eef1f5;color:#0f172a;}
                #sender-sig .sig-clear{border:0;background:transparent;padding:6px 10px;font:600 12.5px/1 inherit;color:#64748b;cursor:pointer;border-radius:8px;}
                #sender-sig .sig-clear:hover{color:#dc2626;}
                #sender-sig .sig-stage{position:relative;}
                #sender-sig .sig-canvas{display:block;width:100%;height:150px;background:#fff;cursor:crosshair;touch-action:none;}
                #sender-sig .sig-type-input{position:absolute;left:14px;right:14px;top:50%;transform:translateY(-50%);box-sizing:border-box;border:1.5px dashed #e4e7ec;border-radius:10px;padding:10px 14px;font-size:20px;text-align:center;outline:none;font-family:'Brush Script MT','Segoe Script',cursive;}
                </style>
                <label class="field-label" style="margin-bottom:6px;"><?= __('forms_edit_legal_signature', 'Legal signature') ?></label>
                <div class="sig-pad" data-sig id="sender-sig">
                    <div class="sig-tabs">
                        <button type="button" class="sig-tab is-active" data-sig-mode-btn="draw"><?= __('forms_edit_draw', 'Draw') ?></button>
                        <button type="button" class="sig-tab" data-sig-mode-btn="type"><?= __('forms_edit_type', 'Type') ?></button>
                        <label class="sig-upload" title="<?= e(__('forms_edit_upload_signature_title', 'Upload an image of your signature')) ?>"><?= __('upload', 'Upload') ?><input type="file" id="sender-sig-file" accept="image/png,image/jpeg" hidden></label>
                        <button type="button" class="sig-clear" data-sig-clear><?= __('forms_edit_clear', 'Clear') ?></button>
                    </div>
                    <div class="sig-stage">
                        <canvas class="sig-canvas" width="660" height="180" role="img" aria-label="<?= e(__('forms_edit_sender_sig_pad', 'Sender signature pad')) ?>"></canvas>
                        <input type="text" class="sig-type-input" maxlength="60" placeholder="<?= e(__('forms_edit_type_full_name', 'Type your full name')) ?>" autocomplete="name" hidden>
                    </div>
                    <input type="hidden" name="fs_sender_sig" value="<?= e($fset['sender']['sig'] ?? '') ?>" data-sig-value>
                    <input type="hidden" name="fs_sender_sig__mode" value="draw" data-sig-mode>
                    <input type="hidden" name="fs_sender_sig__name" value="" data-sig-name>
                </div>
                <div class="field-hint" style="margin-top:8px;"><?= __('forms_edit_draw_switch_pre', 'Draw with your mouse, switch to') ?> <strong><?= __('forms_edit_type', 'Type') ?></strong>, <?= __('forms_edit_draw_switch_or', 'or') ?> <strong><?= __('upload', 'Upload') ?></strong> <?= __('forms_edit_draw_switch_post', 'an image. This signature + your name and the signing date are stamped on the PDF as the sender.') ?></div>
                <script src="<?= e(plugin_url('forms', 'assets/js/signature.js') . '?v=' . FormsAPI::ASSET_VERSION) ?>" defer></script>
                <script>
                (function(){
                    var f = document.getElementById('sender-sig-file'); if (!f) return;
                    f.addEventListener('change', function(){
                        var file = f.files && f.files[0]; if (!file) return;
                        var rd = new FileReader();
                        rd.onload = function(){
                            var pad = document.getElementById('sender-sig');
                            var canvas = pad.querySelector('.sig-canvas'), val = pad.querySelector('[data-sig-value]');
                            var img = new Image();
                            img.onload = function(){
                                var ctx = canvas.getContext('2d');
                                ctx.clearRect(0, 0, canvas.width, canvas.height);
                                var r = Math.min(canvas.width / img.width, canvas.height / img.height);
                                var w = img.width * r, h = img.height * r;
                                ctx.drawImage(img, (canvas.width - w) / 2, (canvas.height - h) / 2, w, h);
                                val.value = canvas.toDataURL('image/png');
                            };
                            img.src = rd.result;
                        };
                        rd.readAsDataURL(file);
                    });
                })();
                </script>
            <?php forms_edit_card_close(); ?>
            </div>

            <div class="pv-panel" data-panel="webhooks" hidden>
            <?php forms_edit_card_open([
                'icon'       => 'link',
                'eyebrow'    => __('forms_edit_integrations', 'Integrations'),
                'title'      => __('forms_edit_webhooks', 'Webhooks'),
                'aside_html' => '<span class="text-xs text-muted">' . __('forms_edit_post_json_on_submit', 'POST JSON on submit') . '</span>',
            ]); ?>
                <div class="field" style="margin-bottom:0;">
                    <textarea id="webhook_urls" name="webhook_urls" rows="3"
                              style="font-family:var(--font-mono); font-size:12.5px;"
                              placeholder="https://hooks.zapier.com/...&#10;https://internal.example.com/forms"><?= e($state['webhook_urls']) ?></textarea>
                    <?php if (!empty($fieldErrors['webhook_urls'])): ?>
                        <div class="field-error"><?= e($fieldErrors['webhook_urls']) ?></div>
                    <?php endif; ?>
                    <div class="field-hint"><?= __('forms_edit_webhook_hint', 'One URL per line. Each submission is POSTed as JSON. Save and we\'ll start firing.') ?></div>
                </div>
            <?php forms_edit_card_close(); ?>
            </div>

            <?php
            // Spam & Security panel. Pull the normalised spam settings and
            // turn the list values back into editable text for the textareas.
            $sp = $fset['spam'];
            $spCountryList = implode(', ', $sp['country_list']);
            $spKeywords    = implode("\n", $sp['keywords']);
            $spIpList      = implode("\n", $sp['ip_blocklist']);
            $spEmailList   = implode("\n", $sp['email_blocklist']);
            $spBlocked30   = (!$isNew && $id) ? (int) Database::value(
                "SELECT COUNT(*) FROM forms_spam_log
                  WHERE tenant_id = ? AND form_id = ? AND created_at > (NOW() - INTERVAL 30 DAY)",
                [$tid, $id]
            ) : 0;
            $spRecent = (!$isNew && $id) ? Database::rows(
                "SELECT code, reason, ip, country, created_at FROM forms_spam_log
                  WHERE tenant_id = ? AND form_id = ?
                  ORDER BY id DESC LIMIT 10",
                [$tid, $id]
            ) : [];
            // Human labels for the block codes recorded by FormsSpamGuard.
            $spCodeLabel = [
                'ip' => __('forms_spam_code_ip', 'IP blocklist'), 'timetrap' => __('forms_spam_code_timetrap', 'Too fast'), 'keyword' => __('forms_spam_code_keyword', 'Keyword'),
                'links' => __('forms_spam_code_links', 'Too many links'), 'email' => __('forms_spam_code_email', 'Email blocklist'),
                'disposable' => __('forms_spam_code_disposable', 'Disposable email'), 'country' => __('forms_spam_code_country', 'Country'), 'rate' => __('forms_spam_code_rate', 'Rate limit'),
                'captcha' => __('forms_spam_code_captcha', 'CAPTCHA'),
            ];
            ?>
            <div class="pv-panel" data-panel="security" hidden>

            <?php forms_edit_card_open(['icon' => 'shield', 'eyebrow' => 'CAPTCHA', 'title' => __('forms_edit_bot_challenge', 'Bot challenge')]); ?>
                <div class="field">
                    <label class="field-label" for="sp_captcha_provider"><?= __('forms_edit_provider', 'Provider') ?></label>
                    <select id="sp_captcha_provider" name="sp_captcha_provider">
                        <option value="none"      <?= $sp['captcha_provider'] === 'none'      ? 'selected' : '' ?>><?= __('off', 'Off') ?></option>
                        <option value="turnstile" <?= $sp['captcha_provider'] === 'turnstile' ? 'selected' : '' ?>>Cloudflare Turnstile</option>
                        <option value="recaptcha" <?= $sp['captcha_provider'] === 'recaptcha' ? 'selected' : '' ?>>Google reCAPTCHA v3</option>
                        <option value="hcaptcha"  <?= $sp['captcha_provider'] === 'hcaptcha'  ? 'selected' : '' ?>>hCaptcha</option>
                    </select>
                    <div class="field-hint"><?= __('forms_edit_captcha_hint', 'Paste the site &amp; secret keys from your provider dashboard. The widget appears just above the submit button; the secret never leaves the server.') ?></div>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sp_captcha_site_key"><?= __('forms_edit_site_key', 'Site key') ?></label>
                        <input type="text" id="sp_captcha_site_key" name="sp_captcha_site_key" maxlength="200"
                               value="<?= e($sp['captcha_site_key']) ?>" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="field">
                        <label class="field-label" for="sp_captcha_secret"><?= __('forms_edit_secret_key', 'Secret key') ?></label>
                        <input type="password" id="sp_captcha_secret" name="sp_captcha_secret" maxlength="200"
                               value="<?= e($sp['captcha_secret']) ?>" autocomplete="off" spellcheck="false">
                    </div>
                </div>
                <div class="field" style="margin-bottom:0;" data-sp-recaptcha<?= $sp['captcha_provider'] === 'recaptcha' ? '' : ' hidden' ?>>
                    <label class="field-label" for="sp_recaptcha_min"><?= __('forms_edit_recaptcha_threshold', 'reCAPTCHA v3 score threshold') ?></label>
                    <input type="number" id="sp_recaptcha_min" name="sp_recaptcha_min" min="0" max="1" step="0.1"
                           value="<?= e((string)$sp['recaptcha_min']) ?>" style="max-width:120px;">
                    <div class="field-hint"><?= __('forms_edit_recaptcha_hint', '0 = lenient, 1 = strict. Submissions scoring below this are rejected. 0.5 is a good default.') ?></div>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'map-pin', 'eyebrow' => __('forms_edit_geography', 'Geography'), 'title' => __('forms_edit_country_filtering', 'Country filtering')]); ?>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sp_country_mode"><?= __('forms_edit_mode', 'Mode') ?></label>
                        <select id="sp_country_mode" name="sp_country_mode">
                            <option value="off"   <?= $sp['country_mode'] === 'off'   ? 'selected' : '' ?>><?= __('forms_edit_off_allow_everywhere', 'Off (allow everywhere)') ?></option>
                            <option value="allow" <?= $sp['country_mode'] === 'allow' ? 'selected' : '' ?>><?= __('forms_edit_allow_listed_countries', 'Allow only the listed countries') ?></option>
                            <option value="block" <?= $sp['country_mode'] === 'block' ? 'selected' : '' ?>><?= __('forms_edit_block_listed_countries', 'Block the listed countries') ?></option>
                        </select>
                    </div>
                    <div class="field">
                        <label class="field-label" for="sp_geo_method"><?= __('forms_edit_lookup_method', 'Lookup method') ?></label>
                        <select id="sp_geo_method" name="sp_geo_method">
                            <option value="auto"    <?= $sp['geo_method'] === 'auto'    ? 'selected' : '' ?>><?= __('forms_edit_geo_auto', 'Auto (header → MaxMind → API)') ?></option>
                            <option value="header"  <?= $sp['geo_method'] === 'header'  ? 'selected' : '' ?>><?= __('forms_edit_geo_header', 'Proxy header only (Cloudflare)') ?></option>
                            <option value="api"     <?= $sp['geo_method'] === 'api'     ? 'selected' : '' ?>><?= __('forms_edit_geo_api', 'ip-api.com lookup') ?></option>
                            <option value="maxmind" <?= $sp['geo_method'] === 'maxmind' ? 'selected' : '' ?>><?= __('forms_edit_geo_maxmind', 'MaxMind GeoLite2 DB') ?></option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="sp_country_list"><?= __('forms_edit_country_codes', 'Country codes') ?></label>
                    <input type="text" id="sp_country_list" name="sp_country_list" maxlength="500"
                           value="<?= e($spCountryList) ?>" placeholder="US, GB, CA, AU" spellcheck="false">
                    <div class="field-hint"><?= __('forms_edit_country_codes_hint', 'Two-letter ISO codes, comma-separated. An unknown country is never blocked (fails open), so legitimate visitors aren\'t locked out when geo lookup is unavailable.') ?></div>
                </div>
                <div class="field" style="margin-bottom:0;">
                    <label class="field-label" for="sp_maxmind_db"><?= __('forms_edit_maxmind_path', 'MaxMind .mmdb path') ?> <span class="text-muted text-xs"><?= __('forms_edit_optional_paren', '(optional)') ?></span></label>
                    <input type="text" id="sp_maxmind_db" name="sp_maxmind_db" maxlength="500"
                           value="<?= e($sp['maxmind_db']) ?>" placeholder="/home/you/geoip/GeoLite2-Country.mmdb" spellcheck="false">
                    <div class="field-hint"><?= __('forms_edit_maxmind_hint', 'Absolute path to a GeoLite2-Country database. Used by the MaxMind method (and Auto). The official') ?> <code>\MaxMind\Db\Reader</code> <?= __('forms_edit_maxmind_hint2', 'is used if installed; otherwise a built-in reader.') ?></div>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'list', 'eyebrow' => __('forms_edit_filtering', 'Filtering'), 'title' => __('forms_edit_content_rules', 'Content rules')]); ?>
                <div class="field">
                    <label class="field-label" for="sp_keywords"><?= __('forms_edit_blocked_keywords', 'Blocked keywords') ?></label>
                    <textarea id="sp_keywords" name="sp_keywords" rows="3"
                              placeholder="casino&#10;crypto airdrop&#10;viagra"><?= e($spKeywords) ?></textarea>
                    <div class="field-hint"><?= __('forms_edit_blocked_keywords_hint', 'One word or phrase per line (or comma-separated). A submission containing any of these in a text field is dropped silently.') ?></div>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sp_max_links"><?= __('forms_edit_max_links', 'Max links') ?></label>
                        <input type="number" id="sp_max_links" name="sp_max_links" min="0"
                               value="<?= e((string)$sp['max_links']) ?>" placeholder="<?= e(__('forms_edit_zero_unlimited', '0 = unlimited')) ?>">
                        <div class="field-hint"><?= __('forms_edit_max_links_hint', 'Drop submissions with more than this many') ?> <code>http(s)://</code> <?= __('forms_edit_max_links_hint2', 'links across all fields.') ?></div>
                    </div>
                    <div class="field">
                        <label class="field-label"><?= __('forms_spam_code_disposable', 'Disposable email') ?></label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;margin-top:8px;">
                            <input type="checkbox" name="sp_block_disposable" value="1" <?= $sp['block_disposable'] ? 'checked' : '' ?>>
                            <span><?= __('forms_edit_reject_throwaway', 'Reject known throwaway domains') ?> <span class="text-muted text-xs">(mailinator, 10minutemail, …)</span></span>
                        </label>
                    </div>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sp_email_blocklist"><?= __('forms_spam_code_email', 'Email blocklist') ?></label>
                        <textarea id="sp_email_blocklist" name="sp_email_blocklist" rows="3"
                                  placeholder="spammer@example.com&#10;@bad-domain.com"><?= e($spEmailList) ?></textarea>
                        <div class="field-hint"><?= __('forms_edit_email_blocklist_hint', 'Full addresses, or') ?> <code>@domain</code> <?= __('forms_edit_email_blocklist_hint2', 'to block a whole domain.') ?></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="sp_ip_blocklist"><?= __('forms_spam_code_ip', 'IP blocklist') ?></label>
                        <textarea id="sp_ip_blocklist" name="sp_ip_blocklist" rows="3"
                                  placeholder="203.0.113.7&#10;198.51.100.0/24"><?= e($spIpList) ?></textarea>
                        <div class="field-hint"><?= __('forms_edit_ip_blocklist_hint', 'Exact IPs or CIDR ranges (v4 / v6), one per line.') ?></div>
                    </div>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php forms_edit_card_open(['icon' => 'clock', 'eyebrow' => __('forms_edit_throttling', 'Throttling'), 'title' => __('forms_edit_rate_limit_time_trap', 'Rate limit & time-trap')]); ?>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="sp_rate_limit"><?= __('forms_edit_max_subs_per_ip', 'Max submissions per IP') ?></label>
                        <input type="number" id="sp_rate_limit" name="sp_rate_limit" min="0"
                               value="<?= e((string)$sp['rate_limit']) ?>" placeholder="<?= e(__('forms_edit_zero_off', '0 = off')) ?>">
                        <div class="field-hint"><?= __('forms_edit_max_subs_hint', 'Within the window below. 0 disables the limit.') ?></div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="sp_rate_window"><?= __('forms_edit_window_seconds', 'Window (seconds)') ?></label>
                        <input type="number" id="sp_rate_window" name="sp_rate_window" min="10"
                               value="<?= e((string)$sp['rate_window']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="sp_min_seconds"><?= __('forms_edit_min_fill_time', 'Minimum fill time (seconds)') ?></label>
                    <input type="number" id="sp_min_seconds" name="sp_min_seconds" min="0"
                           value="<?= e((string)$sp['min_seconds']) ?>" style="max-width:120px;" placeholder="<?= e(__('forms_edit_zero_off', '0 = off')) ?>">
                    <div class="field-hint"><?= __('forms_edit_min_fill_time_hint', 'A signed hidden timestamp records when the form was shown. Submissions sent faster than this are almost always bots — dropped silently. Try 3.') ?></div>
                </div>
                <div class="field" style="margin-bottom:0;border-top:1px solid var(--line,#e5e7eb);padding-top:16px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400;">
                        <input type="checkbox" name="sp_log_blocked" value="1" <?= $sp['log_blocked'] ? 'checked' : '' ?>>
                        <span><?= __('forms_edit_log_blocked', 'Log blocked attempts') ?> <span class="text-muted text-xs">— <?= __('forms_edit_log_blocked_desc', 'keep a record of what was stopped and why') ?></span></span>
                    </label>
                    <?php if (!$isNew && $spBlocked30 > 0): ?>
                        <div class="field-hint"><strong><?= number_format($spBlocked30) ?></strong> <?= $spBlocked30 === 1 ? __('forms_edit_submission_blocked_30d', 'submission blocked on this form in the last 30 days.') : __('forms_edit_submissions_blocked_30d', 'submissions blocked on this form in the last 30 days.') ?></div>
                    <?php endif; ?>
                </div>
            <?php forms_edit_card_close(); ?>

            <?php if (!empty($spRecent)): ?>
            <?php forms_edit_card_open(['icon' => 'clipboard', 'eyebrow' => __('activity', 'Activity'), 'title' => __('forms_edit_recently_blocked', 'Recently blocked')]); ?>
                <ul class="kv-list" style="margin:0;">
                    <?php foreach ($spRecent as $b): ?>
                        <li class="kv-row" style="align-items:flex-start;">
                            <span class="kv-label">
                                <span class="fb-badge"><?= e($spCodeLabel[$b['code']] ?? $b['code']) ?></span>
                                <span class="text-muted text-xs" style="display:block;margin-top:3px;"><?= e(I18n::localDate('j M, H:i', strtotime($b['created_at']))) ?></span>
                            </span>
                            <span class="kv-value" style="text-align:right;">
                                <span class="text-xs"><?= e(mb_strimwidth((string)$b['reason'], 0, 60, '…')) ?></span>
                                <span class="kv-mono text-muted text-xs" style="display:block;margin-top:3px;"><?= e($b['ip'] ?: '—') ?><?= !empty($b['country']) ? ' · ' . e($b['country']) : '' ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="field-hint" style="margin-top:12px;"><?= __('forms_edit_last_n_blocked_pre', 'Last') ?> <?= count($spRecent) ?> <?= count($spRecent) === 1 ? __('forms_edit_blocked_attempt', 'blocked attempt.') : __('forms_edit_blocked_attempts', 'blocked attempts.') ?> <?= __('forms_edit_last_blocked_desc', 'Silent drops (honeypot, time-trap, content rules) and visible rejections (country, rate limit, CAPTCHA) are both recorded here while logging is on.') ?></div>
            <?php forms_edit_card_close(); ?>
            <?php endif; ?>
            </div>

            <script>
            (function(){
                var prov = document.getElementById('sp_captcha_provider');
                var rc   = document.querySelector('[data-sp-recaptcha]');
                if (prov && rc) prov.addEventListener('change', function(){
                    rc.hidden = prov.value !== 'recaptcha';
                });
            })();
            </script>

</form>
<?php forms_edit_close(); ?>
<?php forms_editor_js(); ?>

<style>
/* The shared .pv-card wrapper (includes/record_editor.php) sets
   overflow:hidden so its rounded corners clip children — used by every
   admin editor page, so it's not something to change globally here. But
   it's also the nearest ancestor of #fb-side with overflow != visible,
   and since .pv-card never scrolls itself (it just grows to fit its
   content; the real scroll container is .app-panel, several levels up),
   it becomes position:sticky's positioning reference instead of the
   actual scrolling viewport — which is what was silently capping #fb-side
   to zero travel room even after the align-items fix below. Scope the
   override to just the card wrapping the Fields builder. */
.pv-card:has(#fb-card){overflow:visible;}

/* ── Two-pane builder: canvas (left) + field palette (right) ── */
/* align-items was "start" on the grid container, which meant BOTH columns'
   cells shrank to their own content height instead of stretching to the
   tall row track. That's fine for the canvas (it shouldn't stretch to
   match an arbitrary height) but it silently breaks position:sticky on
   the side panel: sticky has nothing to travel within if its own cell is
   only as tall as its content. Default (stretch) on the container, with
   the canvas opting itself out via align-self, keeps the canvas's natural
   height while giving the side panel's cell the full row height it needs
   for sticky to actually stick as the (long) canvas scrolls. */
.fb2{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;}
.fb2-canvas{min-width:0;min-height:240px;align-self:start;}
/* Palette */
.fb2-side{position:sticky;top:14px;min-width:0;border:1px solid var(--border);border-radius:14px;background:var(--card,#fff);overflow:hidden;box-shadow:var(--shadow-sm);max-height:calc(100vh - 90px);overflow-y:auto;}
/* Field settings panel (shown in the side when a field is selected) */
.fb2-set-head{display:flex;align-items:center;gap:8px;padding:11px 13px;border-bottom:1px solid var(--border);background:var(--bg-soft,#FAFAF7);position:sticky;top:0;z-index:2;}
.fb2-set-badge{flex:none;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--accent);background:var(--accent-soft);padding:2px 8px;border-radius:999px;}
.fb2-set-title{font-size:12.5px;font-weight:600;color:var(--text);}
.fb2-set-sp{flex:1;}
.fb2-set-head .fb-move{flex:none;}
.fb2-set-done{flex:none;border:1px solid var(--border-strong,#E0E2E6);background:var(--surface,#fff);border-radius:8px;padding:5px 13px;font-size:12px;font-weight:600;color:var(--text-2);cursor:pointer;}
.fb2-set-done:hover{background:var(--accent);border-color:var(--accent);color:#fff;}
/* Settings stack in a single column inside the narrow panel. minmax(0,1fr)
   (not bare 1fr) so a wide input/textarea's intrinsic min-content width
   can't force the single column -- and with it the whole sheet's visible
   content -- wider than the panel; same class of bug as the .fb2-canvas/
   .fb2-side min-width:0 fixes above, just one level deeper. */
#fb-set-body .fb-body{display:grid;grid-template-columns:minmax(0,1fr);gap:11px;padding:14px;}
.fb2-hint{display:flex;gap:10px;align-items:flex-start;padding:14px;color:var(--muted);font-size:12.5px;line-height:1.5;border-bottom:1px solid var(--border);background:var(--bg-soft,#FAFAF7);}
.fb2-hint svg{flex:none;color:var(--accent);margin-top:1px;}
.fb2-group{border-bottom:1px solid var(--border);}
.fb2-group:last-child{border-bottom:0;}
.fb2-group-head{width:100%;display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 14px;background:none;border:0;cursor:pointer;font-weight:700;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--text);}
.fb2-chev{color:var(--muted);transition:transform .15s ease;}
.fb2-group:not(.is-open) .fb2-chev{transform:rotate(-90deg);}
.fb2-group:not(.is-open) .fb2-tiles{display:none;}
.fb2-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:2px 14px 14px;}
.fb2-tile{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:80px;border:1px solid var(--border);border-radius:11px;background:var(--card,#fff);cursor:grab;color:var(--text);padding:10px 6px;transition:border-color .12s,background .12s,box-shadow .12s,transform .08s;-webkit-tap-highlight-color:transparent;}
.fb2-tile:hover{border-color:var(--accent);background:var(--accent-soft);box-shadow:var(--shadow-sm);}
.fb2-tile:active{transform:scale(.96);}
.fb2-tile.fb-add-drag{opacity:.45;}
.fb2-tile-ico{width:24px;height:24px;color:var(--muted);transition:color .12s;}
.fb2-tile-ico svg{width:100%;height:100%;}
.fb2-tile:hover .fb2-tile-ico{color:var(--accent);}
.fb2-tile-label{font-size:11px;font-weight:600;line-height:1.25;text-align:center;}
/* Drop-indicator line inserted between cards while dragging. */
.fb-drop-line{grid-column:1/-1;height:3px;border-radius:3px;background:var(--accent);box-shadow:0 0 0 4px var(--accent-soft);margin:1px 0;}
.fb2-canvas.fb-canvas-over{outline:2px dashed var(--accent);outline-offset:6px;border-radius:12px;}
/* Design view: 12-col grid so field cards show at their real width and
   sit side-by-side as columns (greedy-packed, like the public form). */
.fb-list{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;align-items:start;}
.fb-item{position:relative;grid-column:span 12;border:1px solid var(--border);border-radius:10px;background:var(--card,#fff);}
.fb-item.fb-dragging{opacity:.45;}
.fb-item.fb-over{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent);}
.fb-item.fb-over-before{box-shadow:inset 3px 0 0 var(--accent);}
.fb-item.fb-resizing{box-shadow:inset 0 0 0 1px var(--accent);user-select:none;}
.fb-head{display:flex;align-items:center;gap:10px;padding:9px 12px;background:var(--bg-soft,#FAFAF7);border-bottom:1px solid var(--border);}
.fb-handle{cursor:grab;color:var(--muted);font-size:15px;line-height:1;user-select:none;}
.fb-badge{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--accent);background:var(--accent-soft);padding:2px 8px;border-radius:999px;}
.fb-head-label{flex:1;font-weight:600;font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fb-del{border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:15px;line-height:1;padding:3px 7px;border-radius:6px;}
.fb-del:hover{color:#DC2626;background:#FEF2F2;}
.fb-move{flex:none;border:0;background:transparent;color:var(--muted);cursor:pointer;padding:3px 4px;border-radius:6px;line-height:0;display:inline-flex;}
.fb-move svg{width:15px;height:15px;}
.fb-move:hover:not(:disabled){color:var(--accent);background:var(--accent-soft);}
.fb-move:disabled{opacity:.28;cursor:default;}
.fb-body{padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.fb-full{grid-column:1/-1;}
.fb-body label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:3px;}
.fb-body input[type=text]{width:100%;}
.fb-req{display:flex !important;align-items:center;gap:6px;font-weight:400 !important;color:var(--text) !important;font-size:13px !important;cursor:pointer;margin:0;}
.fb-req input{width:16px;height:16px;accent-color:var(--accent);}
.fb-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;text-align:center;color:var(--muted);min-height:230px;padding:30px 24px;border:2px dashed var(--border-strong,#E0E2E6);border-radius:14px;}
.fb-empty.fb-empty-over{border-color:var(--accent);background:var(--accent-soft);}
.fb-empty-ico{width:34px;height:34px;color:var(--faint,#D3D5DA);}
.fb-empty-t{font-size:14.5px;font-weight:650;color:var(--text-2,#3F4450);}
.fb-empty-s{font-size:12.5px;}
.fb-logic{grid-column:1/-1;border-top:1px dashed var(--border);margin-top:4px;padding-top:9px;}
.fb-logic>label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:4px;}
.fb-logic-row{display:flex;gap:6px;flex-wrap:wrap;}
.fb-logic-row select,.fb-logic-row input{flex:1;min-width:96px;font-size:12px;}
.fb-formula-keys{grid-column:1/-1;font-size:11px;color:var(--muted);font-family:var(--font-mono);margin-top:-2px;}
.fb-icon-sel>label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:3px;}
.fb-icon-sel select{width:100%;font-size:12px;}
.fb-cards-ed{grid-column:1/-1;}
.fb-cards-ed>label{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:6px;}
.fb-card-row{display:flex;gap:6px;margin-bottom:6px;align-items:center;}
.fb-card-row input{flex:1;min-width:0;font-size:12px;}
.fb-card-row input:first-child{flex:0 0 28%;}
.fb-card-row select{flex:0 0 88px;font-size:11.5px;}
.fb-card-rm{flex:none;border:0;background:transparent;color:var(--muted);cursor:pointer;font-size:13px;padding:4px 6px;border-radius:6px;}
.fb-card-rm:hover{color:#DC2626;background:#FEF2F2;}
.fb-card-add{border:1px dashed var(--border);background:var(--surface-2);color:var(--accent);cursor:pointer;font-size:12px;font-weight:600;padding:7px 12px;border-radius:8px;width:100%;margin-top:2px;}
.fb-card-add:hover{background:var(--accent-soft);border-color:var(--accent);}
/* Field cards are compact summaries now (settings live in the side panel). */
.fb-item{cursor:pointer;background:var(--card,#fff);transition:border-color .12s, box-shadow .12s;}
.fb-item:hover{border-color:var(--border-stronger,#CFD2D8);}
.fb-item.is-selected{border-color:var(--accent);box-shadow:0 0 0 1px var(--accent), var(--shadow-sm);}
.fb-head{border-radius:10px;border-bottom:0;background:transparent;cursor:pointer;}
.fb-item.is-selected .fb-badge{background:var(--accent);color:#fff;}
/* Draggable palette chips (drag a type onto the canvas to insert). */
.fb-add[draggable=true]{cursor:grab;}
.fb-add.fb-add-drag{opacity:.5;}
/* Width chip in the card head + per-field width presets. */
.fb-wchip{flex:none;font-size:10px;font-weight:700;color:var(--muted);background:var(--surface-2,#f5f6f8);border:1px solid var(--border);border-radius:999px;padding:1px 7px;letter-spacing:.02em;}
.fb-width{grid-column:1/-1;display:flex;align-items:center;gap:6px;flex-wrap:wrap;border-top:1px dashed var(--border);margin-top:4px;padding-top:9px;}
.fb-width>label{font-size:11px;font-weight:600;color:var(--muted);margin:0 2px 0 0;}
.fb-wbtn{border:1px solid var(--border);background:var(--card,#fff);border-radius:6px;padding:3px 9px;font-size:12px;cursor:pointer;color:var(--text);min-width:34px;line-height:1.2;}
.fb-wbtn:hover{border-color:var(--accent);color:var(--accent);}
.fb-wbtn.is-on{background:var(--accent);border-color:var(--accent);color:#fff;}
/* Drag-resize handle on the right edge of a field card. */
.fb-resize{position:absolute;top:8px;bottom:8px;right:-6px;width:12px;cursor:col-resize;z-index:3;display:flex;align-items:center;justify-content:center;touch-action:none;}
.fb-resize::after{content:"";width:3px;height:30px;border-radius:3px;background:var(--border);transition:background .12s;}
.fb-item:hover .fb-resize::after{background:var(--accent);}
/* Compact view — every field collapses to one line; click to expand one. */
.fb-list.fb-compact{display:flex;flex-direction:column;gap:6px;align-items:stretch;}
.fb-list.fb-compact .fb-item{grid-column:auto !important;}
.fb-list.fb-compact .fb-head{cursor:pointer;border-bottom:0;border-radius:10px;}
.fb-list.fb-compact .fb-body{display:none;}
.fb-list.fb-compact .fb-resize{display:none;}
.fb-list.fb-compact .fb-item.fb-expanded .fb-head{border-bottom:1px solid var(--border);border-radius:10px 10px 0 0;}
.fb-list.fb-compact .fb-item.fb-expanded .fb-body{display:grid;}
.fb-toolbar-toggle.is-on{background:var(--accent);border-color:var(--accent);color:#fff;}
/* ── Responsive: mobile-app builder ──────────────────────────── */
/* Backdrop for the mobile settings bottom-sheet (hidden on desktop). */
.fb-sheet-backdrop{display:none;}
@keyframes fb-sheet-up{from{transform:translateY(100%);}to{transform:translateY(0);}}

@media (max-width:900px){
    .fb2{grid-template-columns:1fr;}
    /* The palette used to sit statically above the canvas, which meant it
       scrolled away on long forms with no way back to "add field" short of
       scrolling to the top of the page. Keep it sticky + independently
       scrollable, same pattern as desktop, just shorter so the canvas below
       still gets most of the viewport. The .fb-editing override below still
       wins while a field is being edited (settings bottom-sheet). */
    .fb2-side{position:sticky;top:8px;z-index:5;order:-1;max-height:38vh;overflow-y:auto;}
    .fb2-hint{display:none;}
    .fb2-tiles{grid-template-columns:repeat(4,1fr);}

    /* When a field is selected, the settings panel rises as a bottom sheet
       OVER the canvas (app pattern) instead of being pushed above it. The
       palette keeps sitting inline above the canvas for quick add-field. */
    .fb-sheet-backdrop{
        display:block;position:fixed;inset:0;z-index:79;
        background:rgba(15,17,23,.45);opacity:0;pointer-events:none;
        transition:opacity .2s ease;-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);
    }
    .fb-sheet-backdrop.is-on{opacity:1;pointer-events:auto;}
    .fb-editing .fb2-side{
        position:fixed;left:0;right:0;bottom:0;top:auto;order:0;z-index:80;
        max-height:84vh;overflow-y:auto;border-radius:20px 20px 0 0;
        box-shadow:0 -12px 44px rgba(15,17,23,.24);
        padding-bottom:calc(env(safe-area-inset-bottom,0) + 8px);
        animation:fb-sheet-up .26s cubic-bezier(.22,1,.36,1);
    }
    /* Grab handle + larger sticky header on the sheet. */
    .fb-editing #fb-side-settings::before{
        content:"";display:block;width:42px;height:4px;border-radius:999px;
        background:var(--border-stronger,#CFD2D8);margin:9px auto 1px;
    }
    .fb-editing .fb2-set-head{padding:10px 14px;}
}
@media (max-width:640px){
    .fb-list{display:flex;flex-direction:column;align-items:stretch;}
    .fb-item{grid-column:auto !important;width:100%;}
    .fb-resize{display:none;}
    .fb-body{grid-template-columns:1fr;}
    .fb2-tiles{grid-template-columns:repeat(3,1fr);gap:8px;}
    .fb2-tile{min-height:78px;}
    .fb2-tile-label{font-size:12px;}
    /* Lift the tiny uppercase field-setting + group labels so they're legible
       on a phone (they're 10–11px on desktop, which reads as unreadably small). */
    #fb-set-body .fb-body label{font-size:11.5px;}
    .fb2-group-head{font-size:12px;}
    .fb2-set-badge{font-size:11px;}
    .fb2-set-title{font-size:13px;}
    /* Touch-friendly settings inputs; 16px avoids iOS focus-zoom. */
    .fb-body input:not([type=checkbox]):not([type=radio]):not([type=color]),
    .fb-body select,.fb-body textarea{font-size:16px !important;padding:10px 12px !important;}
    #fb-set-body .fb-body{padding:14px 16px;gap:14px;}
    .fb2-set-done{padding:9px 18px;font-size:13.5px;}
    .fb-move{padding:6px 6px;}
    .fb-move svg{width:18px;height:18px;}
}
@media (max-width:380px){ .fb2-tiles{grid-template-columns:repeat(2,1fr);} }

/* ── Clean, compact field-editor body — paired rows, light inputs ── */
.fb-body{padding:12px 13px;gap:10px 12px;}
.fb-body label{font-size:10px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:4px;color:var(--subtle,#A2A6AE);}
/* Light outlined inputs (replaces the heavy grey fills) */
.fb-body input:not([type=checkbox]):not([type=radio]):not([type=color]),
.fb-body select,
.fb-body textarea{
    background:var(--surface,#fff) !important;
    border:1px solid var(--border-strong,#E0E2E6) !important;
    box-shadow:none !important;
    padding:7px 10px !important;font-size:12.5px !important;
    min-height:0 !important;height:auto !important;border-radius:8px !important;
    /* Only input[type=text] had an explicit width:100% (an older, narrower
       rule below) -- textareas, selects and other input types had none,
       so they fell back to browser default intrinsic sizing instead of
       filling the settings column. box-sizing:border-box so that 100%
       width plus the padding above doesn't itself overflow the column. */
    box-sizing:border-box !important;width:100% !important;max-width:100%;
    transition:border-color .12s, box-shadow .12s;
}
.fb-body input:not([type=checkbox]):not([type=radio]):focus,
.fb-body select:focus,
.fb-body textarea:focus{border-color:var(--accent) !important;box-shadow:0 0 0 3px var(--ring,rgba(37,99,235,.13)) !important;outline:none;}
.fb-body textarea{min-height:56px !important;}
.fb-req{font-size:12.5px !important;}
.fb-req input{width:15px;height:15px;}
/* Combined Required + Width row */
.fb-layout{grid-column:1/-1;display:flex;align-items:center;justify-content:space-between;gap:14px 18px;flex-wrap:wrap;border-top:1px solid var(--border);margin-top:2px;padding-top:11px;}
.fb-layout .fb-width{border-top:0 !important;margin:0 !important;padding:0 !important;}
.fb-width{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.fb-width>label{margin:0 2px 0 0;}
.fb-wbtn{padding:3px 9px;font-size:11.5px;min-width:30px;}
/* Visibility row — solid hairline divider (no dashed noise) */
.fb-logic{margin-top:2px;padding-top:11px;border-top:1px solid var(--border) !important;}
.fb-logic>label{margin-bottom:5px;}
.fb-icon-sel>label,.fb-cards-ed>label{margin-bottom:4px;}
.fb-formula-keys{margin-top:0;}
/* Card head */
.fb-head{padding:7px 10px;gap:8px;}
.fb-head-label{font-size:12.5px;}

/* ── Compact, modern editor header (Forms) — one card: identity +
   Save/Cancel on top, slim stats + links below. ── */
.fhead{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:16px;background:var(--surface,#fff);box-shadow:var(--shadow-sm);margin-bottom:14px;}
/* Hairline accent across the very top edge — a subtle premium cue. */
.fhead::before{content:"";position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),color-mix(in srgb,var(--accent) 30%,transparent));}
.fhead-top{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px 16px;}
.fhead-idwrap{display:flex;align-items:center;gap:13px;min-width:0;}
.fhead-ico{flex:none;width:42px;height:42px;border-radius:13px;display:grid;place-items:center;background:linear-gradient(140deg,var(--accent),var(--accent-deep));color:#fff;box-shadow:0 6px 16px color-mix(in srgb,var(--accent) 30%,transparent),inset 0 1px 0 rgba(255,255,255,.25);}
.fhead-ico svg{width:20px;height:20px;}
.fhead-id{min-width:0;}
.fhead-title{margin:0;font-size:18px;font-weight:700;letter-spacing:-.022em;line-height:1.15;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.fhead-meta{display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap;}
.fhead-ref{font-family:var(--font-mono,monospace);font-size:11px;font-weight:600;color:var(--muted);background:var(--surface-2,#f5f6f8);border:1px solid var(--border);border-radius:999px;padding:2px 9px;}
.fhead-actions{display:flex;align-items:center;gap:9px;flex:none;}
.fhead-actions .btn{padding:8px 16px;}
.fhead-sub{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:9px 16px;border-top:1px solid var(--border);background:var(--surface-2);}
.fhead-stats{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--muted);}
.fhead-stats b{color:var(--text);font-weight:700;font-variant-numeric:tabular-nums;}
.fhead-bullet{width:4px;height:4px;border-radius:999px;background:var(--faint,#D3D5DA);}
.fhead-links{display:flex;align-items:center;gap:8px;font-size:12px;flex-wrap:wrap;}
.fhead-links a{color:var(--text-2,#3F4450);font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none;padding:5px 11px;border-radius:999px;border:1px solid var(--border);background:var(--surface);transition:border-color .12s,color .12s,background .12s;}
.fhead-links a:hover{color:var(--accent);border-color:color-mix(in srgb,var(--accent) 35%,transparent);background:var(--accent-soft);}
.fhead-links a svg{width:14px;height:14px;}
@media (max-width:768px){
    /* Compact editor header on narrow screens / phones */
    .fhead{margin-bottom:8px;border-radius:12px;}
    .fhead-top{flex-wrap:wrap;gap:8px 10px;padding:10px 12px;}
    .fhead-ico{width:32px;height:32px;border-radius:9px;}
    .fhead-ico svg{width:16px;height:16px;}
    .fhead-title{font-size:15.5px;}
    .fhead-meta{gap:7px;margin-top:2px;}
    .fhead-actions{order:3;width:100%;gap:8px;}
    .fhead-actions .btn{flex:1;justify-content:center;padding:7px 12px;font-size:12.5px;min-height:0;}
    /* Drop the informational stats on phones (still on the forms list);
       keep the quick links as one slim row. */
    .fhead-sub{padding:7px 12px;}
    .fhead-stats{display:none;}
    .fhead-links{font-size:12px;gap:8px;width:100%;}
    /* Tidy the card header toolbar: stack it — title on its own row, the action
       buttons share an equal-thirds row below — instead of the title + 3 buttons
       cramming onto one line. (Tab chip spacing comes from the shared kit.) */
    .pv-card-head{flex-wrap:wrap;gap:9px 7px;padding-bottom:2px;}
    .pv-card-head .pv-card-ico{display:none;}        /* decorative — reclaim width on phones */
    .pv-card-head .pv-card-titles{flex:1 1 100%;}    /* title takes the first row */
    .pv-card-head .btn{padding:7px 8px;font-size:12px;min-height:0;flex:1 1 0;justify-content:center;}
}
@media (max-width:420px){
    .fhead-ico{display:none;}                 /* reclaim width on tiny screens */
    .fhead-title{font-size:15px;}
}
</style>
<script>
<?php /* Country list mirrored from FormsAPI::phoneCountries() so the builder UI can render the allowlist + default editors without an extra fetch. */ ?>
var FB_PHONE_COUNTRIES = <?= json_encode(FormsAPI::phoneCountries(), JSON_UNESCAPED_UNICODE) ?>;
<?php
// Translated strings for the client-side field builder (below). Kept as one
// small JSON blob rather than scattering __() through the <script> block —
// PHP can't be evaluated inline inside the JS source.
$fbI18n = [
    'type_text' => __('forms_edit_js_type_text', 'Text'),
    'type_textarea' => __('forms_edit_js_type_textarea', 'Paragraph'),
    'type_email' => __('email', 'Email'),
    'type_tel' => __('auth_phone', 'Phone'),
    'type_intlphone' => __('forms_edit_field_intlphone', 'Phone (intl)'),
    'type_address' => __('forms_edit_js_type_address', 'Address'),
    'type_number' => __('forms_edit_field_number', 'Number'),
    'type_date' => __('forms_edit_field_date', 'Date'),
    'type_time' => __('forms_edit_field_time', 'Time'),
    'type_select' => __('forms_edit_js_type_dropdown', 'Dropdown'),
    'type_radio' => __('forms_edit_js_type_choice', 'Choice'),
    'type_checkbox' => __('forms_edit_js_type_checkbox', 'Checkbox'),
    'type_checkboxes' => __('forms_edit_field_checkboxes', 'Multi-select'),
    'type_range' => __('forms_edit_field_range', 'Slider'),
    'type_rating' => __('forms_edit_field_rating', 'Rating'),
    'type_calc' => __('forms_edit_field_calc', 'Calculated'),
    'type_file' => __('forms_edit_js_type_file', 'File'),
    'type_signature' => __('forms_signature', 'Signature'),
    'type_disclaimer' => __('forms_edit_field_disclaimer', 'Acceptance'),
    'type_heading' => __('title', 'Heading'),
    'type_step' => __('forms_edit_js_type_step_break', 'Step break'),
    'type_hidden' => __('forms_edit_field_hidden', 'Hidden'),
    'opt_min_max_step' => __('forms_edit_js_min_max_step', 'Min, Max, Step'),
    'opt_max_stars' => __('forms_edit_js_max_stars', 'Max stars (e.g. 5)'),
    'opt_options_csv' => __('forms_edit_js_options_csv', 'Options (comma-separated)'),
    'ph_subtitle_optional' => __('forms_edit_js_subtitle_optional', 'Subtitle (optional)'),
    'ph_value' => __('forms_edit_js_value', 'Value'),
    'ph_placeholder' => __('forms_edit_js_placeholder', 'Placeholder'),
    'width_full' => __('forms_edit_js_full', 'Full'),
    'drag_resize_col' => __('forms_edit_js_drag_resize_col', 'Drag to resize column'),
    'drag_reorder' => __('forms_edit_js_drag_reorder', 'Drag to reorder'),
    'untitled_field' => __('forms_edit_js_untitled', '(untitled)'),
    'remove_field' => __('forms_edit_js_remove_field', 'Remove field'),
    'label_word' => __('forms_edit_js_label', 'Label'),
    'field_key' => __('forms_edit_js_field_key', 'Field key'),
    'field_icon' => __('forms_edit_js_field_icon', 'Field icon'),
    'auto_by_type' => __('forms_edit_js_auto_by_type', 'Auto (by type)'),
    'no_icon' => __('forms_edit_js_no_icon', 'No icon'),
    'display_as_choice_cards' => __('forms_edit_js_display_as_cards', ' Display as choice cards (icon + description)'),
    'choice_cards' => __('forms_edit_js_choice_cards', 'Choice cards'),
    'add_option' => __('forms_edit_js_add_option', '+ Add option'),
    'option_desc_ph' => __('short_description', 'Short description'),
    'remove_word' => __('remove', 'Remove'),
    'formula_label' => __('forms_edit_js_formula_label', 'Formula (e.g. {qty} * {price})'),
    'available_prefix' => __('forms_edit_js_available_prefix', 'Available: '),
    'allowed_countries' => __('forms_edit_js_allowed_countries', 'Allowed countries'),
    'allow_all_countries' => __('forms_edit_js_allow_all_countries', 'Allow all countries'),
    'choose_specific_countries' => __('forms_edit_js_choose_specific_countries', 'Choose specific countries'),
    'default_country' => __('forms_edit_js_default_country', 'Default country'),
    'auto_united_states' => __('forms_edit_js_auto_united_states', 'Auto (United States)'),
    'bias_countries_label' => __('forms_edit_js_bias_countries', 'Bias to countries (optional, e.g. "us, ca")'),
    'osm_hint' => __('forms_edit_js_osm_hint', 'Powered by OpenStreetMap (no API key required). Leave blank to search worldwide.'),
    'terms_disclaimer_label' => __('forms_edit_js_terms_label', 'Terms / disclaimer text'),
    'agreement_checkbox_label' => __('forms_edit_js_agreement_label', 'Agreement checkbox label'),
    'required_word' => __('required', 'Required'),
    'width_word' => __('forms_edit_js_width', 'Width'),
    'visibility_word' => __('forms_edit_js_visibility', 'Visibility'),
    'always_show' => __('forms_edit_js_always_show', 'Always show'),
    'op_is' => __('forms_edit_js_op_is', 'is'),
    'op_is_not' => __('forms_edit_js_op_is_not', 'is not'),
    'op_greater_than' => __('forms_edit_js_op_greater_than', 'greater than'),
    'op_less_than' => __('forms_edit_js_op_less_than', 'less than'),
    'op_is_filled' => __('forms_edit_js_op_is_filled', 'is filled'),
    'op_is_empty' => __('forms_edit_js_op_is_empty', 'is empty'),
    'value_ph_lc' => __('forms_edit_js_value_lc', 'value'),
    'visual_builder_btn' => __('forms_edit_js_visual_builder_btn', 'Visual builder'),
    'advanced_dsl_btn' => __('forms_edit_advanced_dsl', 'Advanced (DSL)'),
    'close_word' => __('close', 'Close'),
    'copied' => __('copied', 'Copied!'),
];
?>
var FB_I18N = <?= json_encode($fbI18n, JSON_UNESCAPED_UNICODE) ?>;
(function(){
    var TYPES = {text:FB_I18N.type_text,textarea:FB_I18N.type_textarea,email:FB_I18N.type_email,tel:FB_I18N.type_tel,intlphone:FB_I18N.type_intlphone,
                 address:FB_I18N.type_address,number:FB_I18N.type_number,
                 date:FB_I18N.type_date,time:FB_I18N.type_time,url:'URL',select:FB_I18N.type_select,radio:FB_I18N.type_radio,checkbox:FB_I18N.type_checkbox,
                 checkboxes:FB_I18N.type_checkboxes,
                 range:FB_I18N.type_range,rating:FB_I18N.type_rating,calc:FB_I18N.type_calc,file:FB_I18N.type_file,signature:FB_I18N.type_signature,
                 disclaimer:FB_I18N.type_disclaimer,heading:FB_I18N.type_heading,step:FB_I18N.type_step,hidden:FB_I18N.type_hidden};
    var hasOptions = function(t){ return t === 'select' || t === 'radio' || t === 'checkboxes' || t === 'range' || t === 'rating'; };
    // Per-type labels for the config (options) and placeholder inputs.
    var optLabel = function(t){
        if (t === 'range')  return FB_I18N.opt_min_max_step;
        if (t === 'rating') return FB_I18N.opt_max_stars;
        return FB_I18N.opt_options_csv;
    };
    var phLabel = function(t){
        if (t === 'heading' || t === 'step' || t === 'disclaimer') return FB_I18N.ph_subtitle_optional;
        if (t === 'hidden')  return FB_I18N.ph_value;
        return FB_I18N.ph_placeholder;
    };
    var hasPlaceholder = function(t){
        return ['checkbox','file','signature','range','rating','calc'].indexOf(t) === -1;
    };
    var canHalf = function(t){ return ['step','heading','signature','file','disclaimer'].indexOf(t) === -1; };
    var hasRequired = function(t){ return ['heading','hidden','step','calc'].indexOf(t) === -1; };

    // ── Column width (twelfths). Full = 12 (stored as absent). ──
    var WIDTH_PRESETS = [[3,'¼'],[4,'⅓'],[6,'½'],[8,'⅔'],[9,'¾'],[12,FB_I18N.width_full]];
    function canWidth(f){ return canHalf(f.type) && !f.card && f.type !== 'hidden'; }
    function widthOf(f){
        var w = parseInt(f.width, 10);
        if (!w) w = f.half ? 6 : 12;            // migrate legacy half → 6
        return Math.max(2, Math.min(12, w));
    }
    function setWidth(f, w){
        w = Math.max(2, Math.min(12, w | 0));
        if (w >= 12) { delete f.width; } else { f.width = w; }
        delete f.half;                          // width supersedes the legacy flag
    }
    function widthLabel(w){
        for (var i = 0; i < WIDTH_PRESETS.length; i++) if (WIDTH_PRESETS[i][0] === w) return WIDTH_PRESETS[i][1];
        return Math.round(w / 12 * 100) + '%';
    }
    // New field from a type (shared by palette click + palette drag).
    function makeField(t){
        var f = { type: t, name: uniqueName(t), label: defaultLabel(t),
                  required: false, placeholder: '', options: defaultOptions(t), _named: false };
        if (t === 'disclaimer') {
            f.placeholder = 'Please read carefully before signing';
            f.terms = 'Enter your terms, disclaimer and indemnity text here. The visitor must tick the agreement box below to submit.';
            f.agree = 'I have read and agree to the terms and disclaimer above.';
            f.required = true;
        }
        return f;
    }
    // Right-edge drag handle that resizes a field's column width live.
    function makeResizeHandle(item, f, wchip){
        var h = document.createElement('span'); h.className = 'fb-resize'; h.title = FB_I18N.drag_resize_col;
        var startX = 0, startW = 12, colPx = 60, dragging = false;
        h.addEventListener('pointerdown', function(e){
            if (list.classList.contains('fb-compact')) return;
            e.preventDefault(); e.stopPropagation();
            dragging = true; startX = e.clientX; startW = widthOf(f);
            colPx = (list.clientWidth - 11 * 10) / 12; if (!(colPx > 0)) colPx = 60;
            item.classList.add('fb-resizing');
            try { h.setPointerCapture(e.pointerId); } catch (_){}
        });
        h.addEventListener('pointermove', function(e){
            if (!dragging) return;
            var w = Math.max(2, Math.min(12, startW + Math.round((e.clientX - startX) / colPx)));
            item.style.gridColumn = 'span ' + w;
            if (wchip) wchip.textContent = widthLabel(w);
        });
        function end(e){
            if (!dragging) return; dragging = false;
            item.classList.remove('fb-resizing');
            try { h.releasePointerCapture(e.pointerId); } catch (_){}
            var span = parseInt((item.style.gridColumn || '').replace('span', '').trim(), 10) || widthOf(f);
            setWidth(f, span); render();
        }
        h.addEventListener('pointerup', end);
        h.addEventListener('pointercancel', end);
        return h;
    }

    var ta       = document.getElementById('fields_dsl');
    var list     = document.getElementById('fb-list');
    var empty    = document.getElementById('fb-empty');
    var palette  = document.getElementById('fb-palette');
    var advanced = document.getElementById('fb-advanced');
    var toggle   = document.getElementById('fb-toggle');
    var jsonEl   = document.getElementById('fields_json_data');
    if (!ta || !list) return;

    function slugify(s){
        return (String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'').slice(0,50)) || 'field';
    }
    function uniqueName(base, exceptIdx){
        base = slugify(base); var n = base, i = 2;
        var taken = function(nm){ return fields.some(function(f,j){ return j !== exceptIdx && (f.name||'') === nm; }); };
        while (taken(n)) { n = base + '_' + i; i++; }
        return n;
    }
    function parse(text){
        var out = [];
        String(text||'').split(/\r\n|\n|\r/).forEach(function(line){
            line = line.trim(); if (!line || line[0] === '#') return;
            var p = line.split('|').map(function(s){ return s.trim(); });
            var type = (p[0]||'').toLowerCase();
            if (!TYPES[type]) return;
            out.push({
                type: type, name: p[1]||'', label: p[2]||'',
                required: (p[3]||'').toLowerCase() === 'required',
                placeholder: p[4]||'',
                options: p[5] ? p[5].split(',').map(function(s){return s.trim();}).filter(Boolean) : [],
                _named: !!(p[1])
            });
        });
        return out;
    }
    function toDsl(){
        return fields.map(function(f){
            var parts = [f.type, f.name || slugify(f.label), f.label || '',
                         f.required ? 'required' : '', f.placeholder || '',
                         hasOptions(f.type) ? optStr(f.options).join(',') : ''];
            while (parts.length > 3 && parts[parts.length-1] === '') parts.pop();
            return parts.join('|');
        }).join('\n');
    }
    function sync(){
        ta.value = toDsl();
        if (jsonEl) jsonEl.value = JSON.stringify(fields);   // canonical (carries advanced props)
        empty.style.display = fields.length ? 'none' : '';
    }
    function fromJson(a){
        return a.map(function(f){
            return {
                type: f.type, name: f.name || '', label: f.label || '',
                required: !!f.required, placeholder: f.placeholder || '',
                options: Array.isArray(f.options) ? f.options : [],
                card: !!f.card, half: !!f.half, width: f.width || 0, icon: f.icon || '',
                show_if: f.show_if || null, formula: f.formula || '', step: f.step || null,
                terms: f.terms || '', agree: f.agree || '',
                intl_countries: Array.isArray(f.intl_countries) ? f.intl_countries.slice() : [],
                intl_default: f.intl_default || '',
                addr_countries: f.addr_countries || '',
                _named: true
            };
        });
    }

    // Choice-card option helpers.
    var FB_CARD_ICONS = ['','user','users','building','anchor','boat','star','heart','tag','box','card','globe','phone','mail','home','shield','truck','calendar','dollar','briefcase','wrench','check'];
    function optStr(opts){ return (opts||[]).map(function(o){ return (o && typeof o === 'object') ? (o.value||o.label||'') : o; }); }
    function optObj(opts){ return (opts||[]).map(function(o){ return (o && typeof o === 'object') ? o : { value:o, label:o, icon:'', desc:'' }; }); }
    function cardOptionsEditor(f){
        var wrap = document.createElement('div'); wrap.className = 'fb-full fb-cards-ed';
        var lab = document.createElement('label'); lab.textContent = FB_I18N.choice_cards; wrap.appendChild(lab);
        var rows = document.createElement('div'); wrap.appendChild(rows);
        var addBtn = document.createElement('button'); addBtn.type='button'; addBtn.className='fb-card-add'; addBtn.textContent=FB_I18N.add_option;
        wrap.appendChild(addBtn);
        function draw(){
            rows.innerHTML = '';
            f.options.forEach(function(o, i){
                var row = document.createElement('div'); row.className='fb-card-row';
                var lv = document.createElement('input'); lv.type='text'; lv.placeholder=FB_I18N.label_word; lv.value=o.label||o.value||'';
                lv.addEventListener('input', function(){ o.label=lv.value; o.value=lv.value; sync(); });
                var ic = document.createElement('select');
                FB_CARD_ICONS.forEach(function(n){ var op=new Option(n||'— icon —', n); if((o.icon||'')===n) op.selected=true; ic.appendChild(op); });
                ic.addEventListener('change', function(){ o.icon=ic.value; sync(); });
                var dv = document.createElement('input'); dv.type='text'; dv.placeholder=FB_I18N.option_desc_ph; dv.value=o.desc||'';
                dv.addEventListener('input', function(){ o.desc=dv.value; sync(); });
                var rm = document.createElement('button'); rm.type='button'; rm.className='fb-card-rm'; rm.title=FB_I18N.remove_word; rm.textContent='✕';
                rm.addEventListener('click', function(){ f.options.splice(i,1); draw(); sync(); });
                row.appendChild(lv); row.appendChild(ic); row.appendChild(dv); row.appendChild(rm);
                rows.appendChild(row);
            });
        }
        addBtn.addEventListener('click', function(){ f.options.push({value:'Option '+(f.options.length+1), label:'Option '+(f.options.length+1), icon:'', desc:''}); draw(); sync(); });
        draw();
        return wrap;
    }

    function textField(label, val, oninput, full){
        var w = document.createElement('div'); if (full) w.className = 'fb-full';
        var l = document.createElement('label'); l.textContent = label;
        var i = document.createElement('input'); i.type = 'text'; i.value = val || '';
        i.addEventListener('input', function(){ oninput(i.value); });
        w.appendChild(l); w.appendChild(i); return w;
    }
    function card(f, idx){
        var item = document.createElement('div'); item.className = 'fb-item'; item.dataset.idx = idx;
        // Width span (design view only; compact/mobile CSS overrides to auto).
        if (canWidth(f)) item.style.gridColumn = 'span ' + widthOf(f);

        var head = document.createElement('div'); head.className = 'fb-head'; head.draggable = true;
        var handle = document.createElement('span'); handle.className = 'fb-handle'; handle.textContent = '⠿'; handle.title = FB_I18N.drag_reorder;
        var badge = document.createElement('span'); badge.className = 'fb-badge'; badge.textContent = TYPES[f.type] || f.type;
        var hlabel = document.createElement('span'); hlabel.className = 'fb-head-label'; hlabel.textContent = f.label || FB_I18N.untitled_field;
        head.appendChild(handle); head.appendChild(badge); head.appendChild(hlabel);
        if (canWidth(f)) {
            var wchip = document.createElement('span'); wchip.className = 'fb-wchip';
            wchip.textContent = widthLabel(widthOf(f)); head.appendChild(wchip);
        }
        var del = document.createElement('button'); del.type = 'button'; del.className = 'fb-del'; del.textContent = '✕'; del.title = FB_I18N.remove_field;
        del.addEventListener('click', function(e){
            e.stopPropagation();
            fields.splice(idx, 1);
            if (selectedIdx === idx) selectedIdx = -1; else if (selectedIdx > idx) selectedIdx--;
            render();
        });
        head.appendChild(del);

        // Clicking a field selects it → its settings open in the right panel.
        head.addEventListener('click', function(e){
            if (e.target.closest('.fb-del')) return;
            selectField(idx);
        });

        // Drag SOURCE only — the canvas-level handler (below) shows the
        // drop-indicator line and performs the insert/reorder.
        head.addEventListener('dragstart', function(e){
            item.classList.add('fb-dragging');
            e.dataTransfer.setData('text/plain', 'move:' + idx);
            e.dataTransfer.effectAllowed = 'move';
        });
        head.addEventListener('dragend', function(){ item.classList.remove('fb-dragging'); clearDrop(); });

        // Drag-resize handle — change this field's width in twelfths.
        if (canWidth(f)) item.appendChild(makeResizeHandle(item, f, wchip));

        item.appendChild(head);
        return item;
    }

    // Build the SETTINGS form (shown in the right panel for the selected
    // field). Returns a .fb-body node.
    function fieldSettings(f, idx){
        var body = document.createElement('div'); body.className = 'fb-body';
        body.appendChild(textField(FB_I18N.label_word, f.label, function(v){
            f.label = v;
            var lblEl = list.querySelector('.fb-item[data-idx="' + idx + '"] .fb-head-label');
            if (lblEl) lblEl.textContent = v || FB_I18N.untitled_field;
            if (!f._named) f.name = uniqueName(v, idx);
            sync();
        }));
        body.appendChild(textField(FB_I18N.field_key, f.name, function(v){
            f.name = v; f._named = true; sync();
        }));
        var isTextLike = ['text','email','tel','url','number','date','time'].indexOf(f.type) !== -1;
        if (hasPlaceholder(f.type)) {
            // Text-like fields keep the placeholder half-width so it pairs
            // with the Field icon on the same row (no empty half).
            body.appendChild(textField(phLabel(f.type), f.placeholder, function(v){ f.placeholder = v; sync(); }, !isTextLike));
        }
        if (isTextLike) {
            var iw = document.createElement('div'); iw.className = 'fb-icon-sel';
            var il = document.createElement('label'); il.textContent = FB_I18N.field_icon;
            var isel = document.createElement('select');
            [['',FB_I18N.auto_by_type], ['none',FB_I18N.no_icon]].concat(FB_CARD_ICONS.filter(Boolean).map(function(n){ return [n, n]; })).forEach(function(p){
                var o = new Option(p[1], p[0]); if ((f.icon||'') === p[0]) o.selected = true; isel.appendChild(o);
            });
            isel.addEventListener('change', function(){ f.icon = isel.value; sync(); });
            iw.appendChild(il); iw.appendChild(isel); body.appendChild(iw);
        }
        if (f.type === 'select' || f.type === 'radio') {
            var cardWrap = document.createElement('div'); cardWrap.className = 'fb-full';
            var clab = document.createElement('label'); clab.className = 'fb-req';
            var ccb = document.createElement('input'); ccb.type = 'checkbox'; ccb.checked = !!f.card;
            ccb.addEventListener('change', function(){
                f.card = ccb.checked;
                f.options = f.card ? optObj(f.options) : optStr(f.options);
                render();
            });
            clab.appendChild(ccb); clab.appendChild(document.createTextNode(FB_I18N.display_as_choice_cards));
            cardWrap.appendChild(clab); body.appendChild(cardWrap);

            if (f.card) {
                body.appendChild(cardOptionsEditor(f));
            } else {
                body.appendChild(textField(FB_I18N.opt_options_csv, optStr(f.options).join(', '), function(v){
                    f.options = v.split(',').map(function(s){return s.trim();}).filter(Boolean); sync();
                }, true));
            }
        } else if (hasOptions(f.type)) {
            body.appendChild(textField(optLabel(f.type), (f.options||[]).join(', '), function(v){
                f.options = v.split(',').map(function(s){return s.trim();}).filter(Boolean); sync();
            }, true));
        }
        if (f.type === 'calc') {
            body.appendChild(textField(FB_I18N.formula_label, f.formula || '', function(v){ f.formula = v; sync(); }, true));
            var ck = fields.filter(function(of, j){ return j !== idx && of.name && of.type !== 'step' && of.type !== 'heading' && of.type !== 'calc'; })
                           .map(function(of){ return '{' + of.name + '}'; });
            if (ck.length){
                var hk = document.createElement('div'); hk.className = 'fb-full fb-formula-keys';
                hk.textContent = FB_I18N.available_prefix + ck.join('  ');
                body.appendChild(hk);
            }
        }
        if (f.type === 'intlphone') {
            // ── Country allowlist + default ──────────────────────────────
            // "Allow all" hides the chip picker; "Specific countries" reveals
            // it. The default-country dropdown is filtered to the allowed set
            // so the admin can't pick a default that won't render.
            var iw = document.createElement('div'); iw.className = 'fb-full';
            var il = document.createElement('label'); il.textContent = FB_I18N.allowed_countries;
            var modeSel = document.createElement('select');
            modeSel.appendChild(new Option(FB_I18N.allow_all_countries, 'all'));
            modeSel.appendChild(new Option(FB_I18N.choose_specific_countries, 'pick'));
            modeSel.value = (f.intl_countries && f.intl_countries.length) ? 'pick' : 'all';
            iw.appendChild(il); iw.appendChild(modeSel); body.appendChild(iw);

            var pickWrap = document.createElement('div'); pickWrap.className = 'fb-full fb-intl-pick';
            pickWrap.style.cssText = 'display:flex;flex-wrap:wrap;gap:6px;margin-top:-4px;';
            FB_PHONE_COUNTRIES.forEach(function(c){
                var lab = document.createElement('label');
                lab.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:4px 9px;border:1px solid var(--border,#e5e7eb);border-radius:999px;font:600 12px/1.2 inherit;cursor:pointer;background:#fff;';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = c.d;
                cb.checked = (f.intl_countries||[]).indexOf(c.d) !== -1;
                cb.style.margin = '0';
                cb.addEventListener('change', function(){
                    var arr = (f.intl_countries||[]).slice();
                    var i = arr.indexOf(c.d);
                    if (cb.checked && i === -1) arr.push(c.d);
                    if (!cb.checked && i !== -1) arr.splice(i, 1);
                    f.intl_countries = arr;
                    // Drop a default that's no longer in the allowed set.
                    if (f.intl_default && arr.length && arr.indexOf(f.intl_default) === -1) {
                        f.intl_default = '';
                    }
                    rebuildDefault();
                    sync();
                });
                lab.appendChild(cb);
                lab.appendChild(document.createTextNode(c.f + ' ' + c.d + ' ' + c.n));
                pickWrap.appendChild(lab);
            });
            body.appendChild(pickWrap);

            var dw = document.createElement('div'); dw.className = 'fb-full';
            var dl = document.createElement('label'); dl.textContent = FB_I18N.default_country;
            var dsel = document.createElement('select');
            dw.appendChild(dl); dw.appendChild(dsel); body.appendChild(dw);
            function rebuildDefault(){
                dsel.innerHTML = '';
                dsel.appendChild(new Option(FB_I18N.auto_united_states, ''));
                var allow = f.intl_countries || [];
                FB_PHONE_COUNTRIES.forEach(function(c){
                    if (allow.length && allow.indexOf(c.d) === -1) return;
                    var o = new Option(c.f + ' ' + c.d + ' · ' + c.n, c.d);
                    if (f.intl_default === c.d) o.selected = true;
                    dsel.appendChild(o);
                });
            }
            dsel.addEventListener('change', function(){ f.intl_default = dsel.value; sync(); });

            function applyMode(){
                var picking = modeSel.value === 'pick';
                pickWrap.style.display = picking ? 'flex' : 'none';
                if (!picking) {
                    f.intl_countries = [];
                    // Uncheck all chips when switching back to "all".
                    pickWrap.querySelectorAll('input[type=checkbox]').forEach(function(cb){ cb.checked = false; });
                }
                rebuildDefault();
                sync();
            }
            modeSel.addEventListener('change', applyMode);
            applyMode();
        }
        if (f.type === 'address') {
            // Address autocomplete is powered by OpenStreetMap's free
            // Nominatim service — no API key needed. The admin can optionally
            // bias results to one or more countries (ISO 3166-1 alpha-2),
            // comma-separated, to keep suggestions local.
            body.appendChild(textField(
                FB_I18N.bias_countries_label,
                f.addr_countries || '',
                function(v){ f.addr_countries = v.toLowerCase().replace(/[^a-z,]/g, ''); sync(); },
                false
            ));
            var hint = document.createElement('div'); hint.className = 'fb-full fb-formula-keys';
            hint.textContent = FB_I18N.osm_hint;
            body.appendChild(hint);
        }
        if (f.type === 'disclaimer') {
            var tw = document.createElement('div'); tw.className = 'fb-full';
            var tl = document.createElement('label'); tl.textContent = FB_I18N.terms_disclaimer_label;
            var tt = document.createElement('textarea'); tt.rows = 7; tt.value = f.terms || '';
            tt.style.cssText = 'width:100%;box-sizing:border-box;font:inherit;font-size:13px;line-height:1.5;';
            tt.addEventListener('input', function(){ f.terms = tt.value; sync(); });
            tw.appendChild(tl); tw.appendChild(tt); body.appendChild(tw);
            body.appendChild(textField(FB_I18N.agreement_checkbox_label, f.agree || '', function(v){ f.agree = v; sync(); }, true));
        }
        // One tidy "layout" row: Required on the left, Width presets on the right.
        if (hasRequired(f.type) || canWidth(f)) {
            var lay = document.createElement('div'); lay.className = 'fb-layout';
            if (hasRequired(f.type)) {
                var rl = document.createElement('label'); rl.className = 'fb-req';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = !!f.required;
                cb.addEventListener('change', function(){ f.required = cb.checked; sync(); });
                rl.appendChild(cb); rl.appendChild(document.createTextNode(' ' + FB_I18N.required_word));
                lay.appendChild(rl);
            }
            if (canWidth(f)) {
                var wWrap = document.createElement('div'); wWrap.className = 'fb-width';
                var wl = document.createElement('label'); wl.textContent = FB_I18N.width_word; wWrap.appendChild(wl);
                var cur = widthOf(f);
                WIDTH_PRESETS.forEach(function(p){
                    var b = document.createElement('button'); b.type = 'button';
                    b.className = 'fb-wbtn' + (p[0] === cur ? ' is-on' : '');
                    b.textContent = p[1]; b.title = Math.round(p[0]/12*100) + '%';
                    b.addEventListener('click', function(){ setWidth(f, p[0]); render(); });
                    wWrap.appendChild(b);
                });
                lay.appendChild(wWrap);
            }
            body.appendChild(lay);
        }

        // Conditional visibility — "show this field when [field] [op] [value]".
        if (f.type !== 'step') {
            var others = fields.filter(function(of, j){ return j !== idx && of.name && of.type !== 'step' && of.type !== 'heading'; });
            var logic = document.createElement('div'); logic.className = 'fb-full fb-logic';
            var ll = document.createElement('label'); ll.textContent = FB_I18N.visibility_word; logic.appendChild(ll);
            var lrow = document.createElement('div'); lrow.className = 'fb-logic-row';

            var selF = document.createElement('select');
            selF.appendChild(new Option(FB_I18N.always_show, ''));
            others.forEach(function(of){
                var o = new Option((of.label || of.name), of.name);
                if (f.show_if && f.show_if.field === of.name) o.selected = true;
                selF.appendChild(o);
            });
            var selOp = document.createElement('select');
            [['eq',FB_I18N.op_is],['ne',FB_I18N.op_is_not],['gt',FB_I18N.op_greater_than],['lt',FB_I18N.op_less_than],['filled',FB_I18N.op_is_filled],['empty',FB_I18N.op_is_empty]].forEach(function(p){
                var o = new Option(p[1], p[0]); if (f.show_if && f.show_if.op === p[0]) o.selected = true; selOp.appendChild(o);
            });
            var inpV = document.createElement('input'); inpV.type = 'text'; inpV.placeholder = FB_I18N.value_ph_lc;
            inpV.value = (f.show_if && f.show_if.value) || '';

            var noVal = function(){ return selOp.value === 'filled' || selOp.value === 'empty'; };
            var paint = function(){ selOp.style.display = selF.value ? '' : 'none'; inpV.style.display = (selF.value && !noVal()) ? '' : 'none'; };
            var syncLogic = function(){
                if (!selF.value) f.show_if = null;
                else f.show_if = { field: selF.value, op: selOp.value, value: noVal() ? '' : inpV.value };
                paint(); sync();
            };
            selF.addEventListener('change', syncLogic);
            selOp.addEventListener('change', syncLogic);
            inpV.addEventListener('input', syncLogic);

            lrow.appendChild(selF); lrow.appendChild(selOp); lrow.appendChild(inpV);
            logic.appendChild(lrow); body.appendChild(logic);
            paint();
        }

        return body;
    }

    // ── Selection: open / close the right-hand settings panel ──
    var selectedIdx  = -1;
    var sidePalette  = document.getElementById('fb-side-palette');
    var sideSettings = document.getElementById('fb-side-settings');
    var setBody      = document.getElementById('fb-set-body');
    var setType      = document.getElementById('fb-set-type');
    var builderEl    = document.getElementById('fb-builder');
    // On mobile the settings panel rises as a bottom sheet over the canvas;
    // CSS keys off the .fb-editing class + a backdrop (no-op on desktop).
    var fbBackdrop = null;
    function fbSheetOpen(on){
        if (!builderEl) return;
        builderEl.classList.toggle('fb-editing', !!on);
        if (on){
            if (!fbBackdrop){
                fbBackdrop = document.createElement('div');
                fbBackdrop.className = 'fb-sheet-backdrop';
                fbBackdrop.addEventListener('click', deselect);
                builderEl.appendChild(fbBackdrop);
            }
            fbBackdrop.classList.add('is-on');
        } else if (fbBackdrop){
            fbBackdrop.classList.remove('is-on');
        }
    }
    function selectField(idx){
        if (idx < 0 || idx >= fields.length) { deselect(); return; }
        selectedIdx = idx;
        Array.prototype.forEach.call(list.querySelectorAll('.fb-item'), function(el){
            el.classList.toggle('is-selected', parseInt(el.dataset.idx, 10) === idx);
        });
        if (setBody) { setBody.innerHTML = ''; setBody.appendChild(fieldSettings(fields[idx], idx)); }
        if (setType) setType.textContent = TYPES[fields[idx].type] || fields[idx].type;
        var up = document.getElementById('fb-set-up'), dn = document.getElementById('fb-set-down');
        if (up) up.disabled = idx === 0;
        if (dn) dn.disabled = idx === fields.length - 1;
        if (sidePalette)  sidePalette.hidden = true;
        if (sideSettings) sideSettings.hidden = false;
        fbSheetOpen(true);
    }
    function deselect(){
        selectedIdx = -1;
        Array.prototype.forEach.call(list.querySelectorAll('.fb-item.is-selected'), function(el){ el.classList.remove('is-selected'); });
        if (sideSettings) sideSettings.hidden = true;
        if (sidePalette)  sidePalette.hidden = false;
        fbSheetOpen(false);
    }
    (function(){
        var done = document.getElementById('fb-set-done');
        if (done) done.addEventListener('click', deselect);
        var moveSel = function(dir){
            if (selectedIdx < 0) return;
            var to = selectedIdx + dir; if (to < 0 || to >= fields.length) return;
            var m = fields.splice(selectedIdx, 1)[0]; fields.splice(to, 0, m);
            selectedIdx = to; render();
        };
        var u = document.getElementById('fb-set-up'), d = document.getElementById('fb-set-down');
        if (u) u.addEventListener('click', function(){ moveSel(-1); });
        if (d) d.addEventListener('click', function(){ moveSel(1); });
    })();

    function render(){
        list.innerHTML = '';
        fields.forEach(function(f, i){ list.appendChild(card(f, i)); });
        sync();
        if (selectedIdx >= 0 && selectedIdx < fields.length) selectField(selectedIdx);
        else deselect();
    }

    var fields = (function(){
        if (jsonEl && jsonEl.value) {
            try { var a = JSON.parse(jsonEl.value); if (Array.isArray(a) && a.length) return fromJson(a); } catch (e) {}
        }
        return parse(ta.value);
    })();

    function defaultOptions(t){
        if (t === 'select' || t === 'radio') return ['Option 1','Option 2'];
        if (t === 'range')  return ['0','100','5'];
        if (t === 'rating') return ['5'];
        return [];
    }
    function defaultLabel(t){
        if (t === 'heading')    return 'Section title';
        if (t === 'step')       return '';   // unnamed → renders just the "Step i of N" eyebrow
        if (t === 'disclaimer') return 'Legal Disclaimer & Indemnity';
        return TYPES[t];
    }
    // Palette tiles: tap to append, or drag onto the canvas to position.
    Array.prototype.forEach.call(document.querySelectorAll('.fb-add'), function(btn){
        btn.addEventListener('click', function(){ fields.push(makeField(btn.dataset.type)); render(); });
        btn.draggable = true;
        btn.addEventListener('dragstart', function(e){
            btn.classList.add('fb-add-drag');
            e.dataTransfer.setData('text/plain', 'new:' + btn.dataset.type);
            e.dataTransfer.effectAllowed = 'copy';
            // Use the tile itself as the floating drag image (matches the ref).
            try { e.dataTransfer.setDragImage(btn, btn.offsetWidth / 2, btn.offsetHeight / 2); } catch (_) {}
        });
        btn.addEventListener('dragend', function(){ btn.classList.remove('fb-add-drag'); clearDrop(); });
    });

    // Collapsible palette groups (Standard / Advanced).
    Array.prototype.forEach.call(document.querySelectorAll('[data-fb-group-toggle]'), function(btn){
        btn.addEventListener('click', function(){ btn.parentNode.classList.toggle('is-open'); });
    });

    // ── Canvas drag-and-drop: drop-indicator line + insert/reorder ──
    var canvas   = document.getElementById('fb-canvas');
    var dropLine = document.createElement('div'); dropLine.className = 'fb-drop-line';
    var pendingIdx = -1;
    function clearDrop(){
        if (dropLine.parentNode) dropLine.parentNode.removeChild(dropLine);
        if (canvas) canvas.classList.remove('fb-canvas-over');
        empty.classList.remove('fb-empty-over');
        pendingIdx = -1;
    }
    function computeIndex(y){
        var items = list.querySelectorAll('.fb-item'), i;
        for (i = 0; i < items.length; i++) {
            var r = items[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) return i;
        }
        return items.length;
    }
    function onCanvasOver(e){
        var types = e.dataTransfer.types || [];
        if (Array.prototype.indexOf.call(types, 'text/plain') === -1) return;
        e.preventDefault();
        pendingIdx = computeIndex(e.clientY);
        var items = list.querySelectorAll('.fb-item');
        if (pendingIdx >= items.length) list.appendChild(dropLine);
        else list.insertBefore(dropLine, items[pendingIdx]);
        if (!fields.length) empty.classList.add('fb-empty-over');
    }
    function onCanvasDrop(e){
        var payload = e.dataTransfer.getData('text/plain') || '';
        if (payload.indexOf('new:') !== 0 && payload.indexOf('move:') !== 0) return;
        e.preventDefault();
        var idx = pendingIdx < 0 ? fields.length : pendingIdx;
        clearDrop();
        if (payload.indexOf('new:') === 0) {
            idx = Math.max(0, idx);
            fields.splice(idx, 0, makeField(payload.slice(4)));
        } else {
            var from = parseInt(payload.slice(5), 10);
            if (isNaN(from)) return;
            var moved = fields.splice(from, 1)[0];
            if (from < idx) idx--;                       // adjust for the removal
            idx = Math.max(0, idx);
            fields.splice(idx, 0, moved);
            if (selectedIdx === from) selectedIdx = idx; // keep the moved field selected
        }
        render();
    }
    if (canvas) {
        canvas.addEventListener('dragover', onCanvasOver);
        canvas.addEventListener('drop', onCanvasDrop);
        canvas.addEventListener('dragleave', function(e){ if (!canvas.contains(e.relatedTarget)) clearDrop(); });
    }

    // Compact view — collapse every field to a single line (persisted).
    var compactBtn = document.getElementById('fb-compact-toggle');
    var COMPACT_KEY = 'slate_fb_compact';
    function applyCompact(on){
        list.classList.toggle('fb-compact', on);
        if (compactBtn) compactBtn.classList.toggle('is-on', on);
        if (!on) Array.prototype.forEach.call(list.querySelectorAll('.fb-expanded'), function(el){ el.classList.remove('fb-expanded'); });
        try { localStorage.setItem(COMPACT_KEY, on ? '1' : '0'); } catch (e) {}
    }
    if (compactBtn) compactBtn.addEventListener('click', function(){ applyCompact(!list.classList.contains('fb-compact')); });
    (function(){ var s = null; try { s = localStorage.getItem(COMPACT_KEY); } catch (e) {} if (s === '1') applyCompact(true); })();

    var builder = document.getElementById('fb-builder');
    var showingAdvanced = false;
    toggle.addEventListener('click', function(){
        showingAdvanced = !showingAdvanced;
        if (showingAdvanced) {
            sync(); advanced.hidden = false; if (builder) builder.style.display = 'none';
            toggle.textContent = FB_I18N.visual_builder_btn;
        } else {
            fields = parse(ta.value); advanced.hidden = true; if (builder) builder.style.display = '';
            toggle.textContent = FB_I18N.advanced_dsl_btn; render();
        }
    });

    var form = ta.closest('form');
    if (form) form.addEventListener('submit', function(){ if (!showingAdvanced) sync(); });

    // Live preview — POST the current builder state to the preview endpoint in a new tab.
    var previewBtn = document.getElementById('fb-preview');
    if (previewBtn) previewBtn.addEventListener('click', function(){
        if (showingAdvanced) { fields = parse(ta.value); } else { sync(); }
        var pf = document.createElement('form');
        pf.method = 'post'; pf.target = '_blank';
        pf.action = window.location.pathname + (window.location.search ? window.location.search + '&' : '?') + 'preview=1';
        var add = function(n, v){ var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = (v == null ? '' : v); pf.appendChild(i); };
        add('fields_json_data', JSON.stringify(fields));
        var t = document.getElementById('title');       add('title', t ? t.value : '');
        var d = document.getElementById('description');  add('description', d ? d.value : '');
        var ds = document.getElementById('fs_density');
        var accOn = !!(document.getElementById('fs_accent_on') || {}).checked;
        add('settings_json', JSON.stringify({
            density: ds ? ds.value : 'comfortable',
            animate:  !!(document.querySelector('[name="fs_animate"]')  || {}).checked,
            validate: !!(document.querySelector('[name="fs_validate"]') || {}).checked,
            rail:     !!(document.querySelector('[name="fs_rail"]')     || {}).checked,
            scroll_fields: !!(document.querySelector('[name="fs_scroll_fields"]') || {}).checked,
            full_width:    !!(document.querySelector('[name="fs_full_width"]')    || {}).checked,
            pad:       (function(v){ return v === '' || v == null ? null : parseInt(v, 10); })((document.getElementById('fs_pad')       || {}).value),
            field_gap: (function(v){ return v === '' || v == null ? null : parseInt(v, 10); })((document.getElementById('fs_field_gap') || {}).value),
            col_gap:   (function(v){ return v === '' || v == null ? null : parseInt(v, 10); })((document.getElementById('fs_col_gap')   || {}).value),
            accent:       accOn ? ((document.getElementById('fs_accent') || {}).value || '') : '',
            accent_hover: accOn ? ((document.getElementById('fs_accent_hover') || {}).value || '') : '',
            field_style:  (document.getElementById('fs_field_style') || {}).value || 'outline',
            shape:        (document.getElementById('fs_shape') || {}).value || 'rounded',
            labels:       (document.querySelector('[name="fs_hide_labels"]') || {}).checked ? 'hide' : 'show',
            summary:      !!(document.querySelector('[name="fs_summary"]') || {}).checked
        }));
        document.body.appendChild(pf); pf.submit(); document.body.removeChild(pf);
    });

    render();
})();
</script>

<script>
// Live "Preview popup" — same overlay the snippet produces, runnable here.
(function(){
    var pd = document.getElementById('popup-demo'); if (!pd) return;
    pd.addEventListener('click', function(){
        var url = pd.getAttribute('data-form');
        var o = document.createElement('div');
        o.style.cssText = 'position:fixed;inset:0;z-index:2147483600;background:#f4f5f7;display:flex;align-items:center;justify-content:center;padding:clamp(8px,3vw,28px);opacity:0;transition:opacity .18s ease;overflow:auto';
        var m = document.createElement('div');
        m.style.cssText = 'position:relative;width:100%;max-width:720px;max-height:94vh';
        var f = document.createElement('iframe');
        f.src = url;
        f.style.cssText = 'display:block;width:100%;height:600px;max-height:94vh;border:0;background:transparent;transition:height .2s ease';
        var c = document.createElement('button');
        c.setAttribute('aria-label', FB_I18N.close_word);
        c.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
        c.style.cssText = 'position:absolute;top:18px;right:18px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;padding:0;border:0;border-radius:999px;background:rgba(15,23,42,.92);color:#fff;box-shadow:0 6px 22px rgba(0,0,0,.4);cursor:pointer;z-index:2';
        function onMsg(ev){ var d = ev.data; if (d && d.type === 'cb-form-height' && d.height) { f.style.height = Math.min(d.height, Math.round(window.innerHeight * 0.94)) + 'px'; } }
        function close(){ window.removeEventListener('message', onMsg); document.removeEventListener('keydown', onKey); o.remove(); }
        function onKey(e){ if (e.key === 'Escape') close(); }
        window.addEventListener('message', onMsg);
        document.addEventListener('keydown', onKey);
        c.addEventListener('click', close);
        o.addEventListener('click', function(ev){ if (ev.target === o) close(); });
        m.appendChild(f); o.appendChild(m); o.appendChild(c); document.body.appendChild(o);
        requestAnimationFrame(function(){ o.style.opacity = '1'; });
    });
})();

<?php /* Copy-to-clipboard for the Share & embed snippets. */ ?>
(function(){
    document.addEventListener('click', function(e){
        var b = e.target.closest('.snippet-copy'); if (!b) return;
        var fld = b.closest('.field'); var pre = fld && fld.querySelector('pre.snippet'); if (!pre) return;
        var txt = pre.textContent;
        var done = function(){ var o = b.textContent; b.textContent = FB_I18N.copied; setTimeout(function(){ b.textContent = o; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(done, function(){});
        } else {
            var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta);
            ta.select(); try { document.execCommand('copy'); done(); } catch (err) {}
            document.body.removeChild(ta);
        }
    });
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
