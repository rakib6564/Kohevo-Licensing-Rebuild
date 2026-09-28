<?php
/**
 * Forms — public-facing router.
 *
 * Wired by Forms::addPublicRoutes() — PublicRouter::dispatch() hands
 * us paths starting with /forms/. We render the form, accept POST,
 * and show a thanks page. `?embed=1` strips outer chrome for use
 * inside an iframe.
 *
 * URL shape:
 *   /forms/<slug>          → form (GET) + submit (POST)
 *   /forms/<slug>/thanks   → success page (after submit)
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('forms');
ModuleGuard::requirePublic('forms');
require_once dirname(__DIR__) . '/FormsAPI.php';
require_once dirname(__DIR__) . '/lib/FormsPdf.php';
FormsAPI::ensureSchema();

// Start the session up front (before any output) so the post-submit "Save PDF"
// fallback can read the just-saved ref. Public form pages aren't auth-gated, so
// nothing else starts a session for them.
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$path = trim((string)($_GET['_route_path'] ?? ''), '/');

// Split into <slug>[/<action>]
$segments = $path === '' ? [] : explode('/', $path);
$slug   = $segments[0] ?? '';
$action = $segments[1] ?? '';

$embed = !empty($_GET['embed']);

if ($slug === '') {
    // /forms/ with no slug shows the public landing page (same as the site
    // root), so visitors choosing a vessel survey land somewhere meaningful.
    require_once SLATE_ROOT . '/includes/landing.php';
    slate_render_landing();
    return;
}

$form = FormsAPI::getForm($slug);
if (!$form || $form['status'] !== 'published') {
    forms_public_render_notfound('That form isn\'t available.', $embed);
    return;
}

// Submission cap reached?
if (!empty($form['submission_limit']) && (int)$form['submission_limit'] > 0) {
    $count = (int) Database::value(
        "SELECT COUNT(*) FROM forms_submissions WHERE tenant_id = ? AND form_id = ?",
        [current_tenant_id(), (int)$form['id']]
    );
    if ($count >= (int)$form['submission_limit']) {
        forms_public_render_closed($form, $embed);
        return;
    }
}

// Thanks page
if ($action === 'thanks') {
    forms_public_render_thanks($form, $embed);
    return;
}

// Token-gated public PDF download of a submission ("Save PDF").
if ($action === 'pdf') {
    $ref = (string)($_GET['ref'] ?? '');
    $tok = (string)($_GET['t'] ?? '');
    $set = FormsAPI::formSettings($form['settings_json'] ?? null);
    if ($set['pdf_save_btn'] === 'never' || $ref === ''
        || !slate_sign_equals('forms-pdf', $ref, $tok, 24)) {
        forms_public_render_notfound('That PDF isn\'t available.', $embed);
        return;
    }
    $sub = Database::row(
        "SELECT data_json FROM forms_submissions WHERE tenant_id = ? AND form_id = ? AND ref = ?",
        [current_tenant_id(), (int)$form['id'], $ref]
    );
    if (!$sub) { forms_public_render_notfound('Submission not found.', $embed); return; }
    $data = json_decode($sub['data_json'] ?? '[]', true) ?: [];
    if ($set['pdf_save_btn'] === 'signed' && !forms_public_all_signed($form, $data)) {
        forms_public_render_notfound('The PDF becomes available once the document is signed by everyone.', $embed);
        return;
    }
    $pdf = FormsAPI::submissionPdf($form, $data, $ref);
    if ($pdf === '') { forms_public_render_notfound('The PDF could not be generated.', $embed); return; }
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', 'submission-' . $ref) . '.pdf"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, no-store, max-age=0');
    }
    echo $pdf;
    return;
}

$errors = [];
$values = [];
$spamCountry = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $spam = FormsAPI::formSettings($form['settings_json'] ?? null)['spam'];

    // Honeypot — naive bots fill any input they see (zero-cost first gate, kept
    // ahead of the heavier checks). Silent drop: fake success, store nothing.
    // (Accept the legacy name too, in case a cached page is submitted.)
    if (!empty($_POST['hp_field']) || !empty($_POST['website_url'])) {
        forms_public_finish_silent($form, $embed);
        return;
    }

    if (!csrf_verify()) {
        $errors['_form'] = __('fm_err_security_check_failed_please_try_again', 'Security check failed. Please try again.');
    } else {
        // Anti-spam / security gate: IP blocklist, time-trap and content
        // rules drop silently; country / rate-limit / CAPTCHA reject with
        // a visible message. Runs before validation so spam never touches
        // the file/signature handlers or the database.
        $verdict = FormsSpamGuard::evaluate($form, $spam, $_POST, FormsAPI::clientIp());
        if (!$verdict['ok'] && $verdict['silent']) {
            forms_public_finish_silent($form, $embed);
            return;
        }
        if (!$verdict['ok']) {
            $errors['_form'] = $verdict['message'];
        } else {
            $spamCountry = $verdict['country'];
            $result = FormsAPI::validateSubmission($form, $_POST);
            $values = $result['data'];

            // Handle file fields after text validation (cheap-fail order)
            if ($result['ok']) {
                $fileResult = FormsAPI::handleFileUploads($form, $result['data']);
                $result['data']   = $fileResult['data'];
                $result['errors'] = array_merge($result['errors'], $fileResult['errors']);
                $result['ok']     = empty($result['errors']);
                $values           = $result['data'];
            }

            // Decode + store signature pads (also a structured array in $data)
            if ($result['ok']) {
                $sigResult = FormsAPI::handleSignatures($form, $result['data']);
                $result['data']   = $sigResult['data'];
                $result['errors'] = array_merge($result['errors'], $sigResult['errors']);
                $result['ok']     = empty($result['errors']);
                $values           = $result['data'];
            }

            if ($result['ok']) {
                // Save FAST (DB insert + contacts), then redirect immediately.
                // The heavy work (PDF, emails, webhooks) runs AFTER the response
                // is flushed so the submitter isn't kept waiting on SMTP.
                $newRef = null;
                $saved  = forms_public_save($form, $result['data'], ['country' => $spamCountry], $newRef);

                // An inline form block posts here from the page it lives on and
                // sends that page's PATH as return_to, so a successful submit
                // goes back where the visitor was instead of stranding them on
                // the standalone thanks page.
                //
                // Path-only, single-leading-slash, no scheme or host: a value
                // that is not a same-origin path is DISCARDED rather than
                // sanitised, because a redirect target taken from a request is
                // an open redirect the moment it can name another site.
                $returnTo = (string)($_POST['return_to'] ?? '');
                $sameOrigin = $returnTo !== ''
                    && $returnTo[0] === '/'
                    && !str_starts_with($returnTo, '//')
                    && !str_contains($returnTo, "\\")
                    && parse_url($returnTo, PHP_URL_HOST) === null;

                if (!empty($form['redirect_url'])) {
                    $dest = $form['redirect_url'];
                } elseif ($sameOrigin) {
                    $dest = $returnTo . (str_contains($returnTo, '?') ? '&' : '?')
                          . http_build_query(['form_submitted' => $form['slug'], 'ref' => $newRef]);
                } else {
                    // Same-page success — carry the ref + signed token so the
                    // thanks page can offer a "Save PDF" download.
                    $q = ['ref' => $newRef, 't' => forms_public_pdf_token((string)$newRef)];
                    if ($embed) $q['embed'] = '1';
                    // Carry bare mode through so the thanks page keeps the same
                    // chrome-less, true-height rendering (else it reverts to the
                    // full layout and leaves a tall gap in the host iframe).
                    if (forms_is_bare()) $q['chrome'] = '0';
                    $dest = SLATE_URL . '/forms/' . $form['slug'] . '/thanks?' . http_build_query($q);
                }
                header('Location: ' . $dest, true, 303);

                // Send the redirect to the browser now, then keep processing.
                forms_public_finish_response();
                forms_public_dispatch($form, $result['data'], $saved);
                exit;
            }
            $errors = $result['errors'];
        }
    }
}

forms_public_render_form($form, $values, $errors, $embed);


// ─────────────────────────────────────────────────────────────
//                       Render helpers
// ─────────────────────────────────────────────────────────────

/**
 * "Bare" embed: ?embed=1&chrome=0 renders the form with NO card border,
 * background, padding or title/description header — just the fields. Lets a
 * host page (e.g. an SBK contact/tabs block) supply its own card + heading
 * without the double-border / duplicate-title look. Opt-in; default embeds
 * keep their full chrome.
 */
function forms_is_bare(): bool {
    return !empty($_GET['embed']) && (string)($_GET['chrome'] ?? '1') === '0';
}

function forms_public_layout_start(string $title, bool $embed): void {
    // The form HTML is dynamic — never let a browser/CDN serve a stale copy
    // (otherwise old markup + old ?v= asset links keep showing after updates).
    if (!headers_sent()) {
        header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        // Allow this page to be iframed on customer sites in embed mode.
        if ($embed) {
            header_remove('X-Frame-Options');
            header('Content-Security-Policy: frame-ancestors *');
        }
    }
    $siteName = Database::setting('site_name') ?: 'Kohevo';
    slate_ui_emit_css();
    // Override core's default --accent with the tenant brand colour, else
    // every `var(--accent)` in the form renders #2563EB blue. Must follow
    // slate_ui_emit_css().
    slate_brand_accent_emit();
    ?>
    <!DOCTYPE html>
    <html lang="<?= e(I18n::currentLocale()) ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title><?= e($title) ?> — <?= e($siteName) ?></title>
        <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
        <meta name="csrf" content="<?= e(csrf_token()) ?>">
        <link rel="stylesheet" href="<?= e(plugin_url('forms', 'assets/css/public.css')) ?>?v=<?= e(FormsAPI::ASSET_VERSION) ?>">
        <?php if (forms_is_bare()): ?>
        <style id="forms-bare">
            /* Bare embed: no chrome, and the document reports its TRUE content
               height (no min-height/centering slack) so the host iframe fits
               exactly with no trailing gap under the submit button. */
            html,body.forms-bare{background:transparent!important;min-height:0!important;height:auto!important;margin:0!important;}
            body.forms-bare .forms-public-shell{display:block!important;max-width:none!important;margin:0!important;padding:0!important;min-height:0!important;}
            body.forms-bare .forms-public-card{border:0!important;box-shadow:none!important;background:transparent!important;padding:0!important;border-radius:0!important;min-height:0!important;max-width:none!important;margin:0!important;}
            body.forms-bare .forms-public-header{display:none!important;}
            body.forms-bare .forms-public-footer{display:none!important;}
            body.forms-bare form{margin:0!important;}
            /* THE FIX: the fields wrapper is normally a fixed-height scroll
               region (max-height:60vh, for multi-step wizards). In an embed we
               want the form to flow at its FULL natural height so the host
               iframe fits it exactly — no inner scroll, no empty band, no
               scroll-shadow mask. */
            body.forms-bare .forms-step-fields{max-height:none!important;overflow:visible!important;margin:0!important;padding:0!important;-webkit-mask-image:none!important;mask-image:none!important;}
            /* Trim the trailing margins that would otherwise pad scrollHeight. */
            body.forms-bare .forms-flat-foot{margin-top:16px!important;margin-bottom:0!important;}
            body.forms-bare .forms-public-card .field:last-of-type{margin-bottom:0!important;}
            /* A roomier message box. */
            body.forms-bare textarea{min-height:130px;resize:vertical;}
        </style>
        <?php endif; ?>
    </head>
    <body class="forms-public<?= $embed ? ' forms-public-embed' : '' ?><?= forms_is_bare() ? ' forms-bare' : '' ?>">
    <?php
}

function forms_public_layout_end(bool $embed): void {
    if (!$embed) {
        echo '<footer class="forms-public-footer text-sm text-muted text-center">' . e(__('platform_powered_by', 'Powered by')) . ' '
           . e(Database::setting('site_name') ?: 'Kohevo') . '</footer>';
    } else {
        // Embedded in a page (Content Builder "Form" block): size the host
        // iframe from the INSIDE. Because the embed is same-origin, the form
        // can set `window.frameElement.style.height` directly — this runs in
        // the form's OWN context (after its wizard JS lays the step out) and
        // measures the real content wrapper (.forms-public-shell), which is
        // floor-proof and ignores browser-extension injections (Grammarly,
        // etc.) that would otherwise inflate document.scrollHeight. A
        // ResizeObserver + short poll catch late init and step changes.
        // data-cfasync="false" keeps Cloudflare Rocket Loader from deferring it.
        echo '<script data-cfasync="false">(function(){'
           . 'var el=document.querySelector(".forms-public-shell");'
           . 'function h(){try{'
           .   'var box=el||document.querySelector(".forms-public-shell")||document.body; if(!box)return;'
           .   'var ht=Math.ceil(box.getBoundingClientRect().height)+2; if(ht<=2)return;'
           .   'var fe=window.frameElement; if(fe){fe.style.height=ht+"px";}'
           .   'try{parent.postMessage({type:"cb-form-height",height:ht},"*");}catch(e){}'
           . '}catch(e){}}'
           . 'window.addEventListener("load",h);window.addEventListener("resize",h);'
           . 'document.addEventListener("DOMContentLoaded",h);'
           . 'if(window.ResizeObserver){try{new ResizeObserver(h).observe(el||document.body);}catch(e){}}'
           . 'var n=0,iv=setInterval(function(){h();if(++n>30)clearInterval(iv);},200);})();</script>';
    }
    echo '</body></html>';
}

function forms_public_render_form(array $form, array $values, array $errors, bool $embed): void {
    forms_public_layout_start($form['title'], $embed);
    // Multi-step forms make each step's title the hero, so demote the form
    // title to a small brand kicker (avoids two competing big headings).
    $multi = false;
    foreach (($form['fields'] ?? []) as $f) { if (($f['type'] ?? '') === 'step') { $multi = true; break; } }
    $set = FormsAPI::formSettings($form['settings_json'] ?? null);
    ?>
    <main class="forms-public-shell">
        <article <?= FormsAPI::publicCardAttrs($set) ?>>
            <?php if (!$multi && !forms_is_bare()): ?>
                <header class="forms-public-header">
                    <h1><?= e($form['title']) ?></h1>
                    <?php if (!empty($form['description'])): ?>
                        <p class="forms-public-desc"><?= nl2br(e($form['description'])) ?></p>
                    <?php endif; ?>
                </header>
            <?php endif; /* multi-step: title is rendered in the wizard header row */ ?>

            <?php if (!empty($errors['_form'])): ?>
                <div class="alert alert-error"><?= e($errors['_form']) ?></div>
            <?php elseif (!empty($errors)): ?>
                <div class="alert alert-error"><?= __('forms_fix_highlighted_fields', 'Please fix the highlighted fields and try again.') ?></div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" novalidate
                  data-animate="<?= $set['animate'] ? '1' : '0' ?>" data-validate="<?= $set['validate'] ? '1' : '0' ?>">
                <?= csrf_field() ?>
                <!-- Honeypot — hidden from humans, often filled by bots.
                     display:none (not off-screen) so browser autofill and
                     form-filler extensions skip it; naive bots still submit it. -->
                <div style="display:none !important;" aria-hidden="true">
                    <label>Leave this field empty
                        <input type="text" name="hp_field" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <?php
                // Anti-spam: a signed time-trap field + the configured CAPTCHA
                // widget, rendered just above the submit button.
                $beforeSubmit = FormsSpamGuard::timeTrapField()
                              . FormsSpamGuard::captchaWidget($set['spam']);
                $brandLogoPath = (string)Database::setting('brand_logo_path');
                $brand = [
                    'name' => (string)(Database::setting('site_name') ?: ''),
                    'logo' => $brandLogoPath !== '' ? SLATE_URL . '/' . ltrim($brandLogoPath, '/') : '',
                ];
                ?>
                <?= FormsAPI::renderFormBody($form['fields'], $values, $errors, (string)($form['submit_label'] ?: 'Submit'), ['rail' => $set['rail'], 'before_submit' => $beforeSubmit, 'title' => (string)$form['title'], 'summary' => !empty($set['summary']), 'brand' => $brand]) ?>
            </form>
        </article>
    </main>
    <?= FormsSpamGuard::captchaScript($set['spam']) ?>
    <script src="<?= e(plugin_url('forms', 'assets/js/forms-logic.js')) ?>?v=<?= e(FormsAPI::ASSET_VERSION) ?>" defer></script>
    <?php
    slate_form_validation_i18n_script();
    forms_public_layout_end($embed);
}

/**
 * Honeypot- / silent-spam success path: pretend the submit worked (so a
 * bot can't tell it was caught) without storing anything. Mirrors the
 * real success branch's redirect-or-thanks behaviour.
 */
function forms_public_finish_silent(array $form, bool $embed): void {
    if (!empty($form['redirect_url'])) {
        header('Location: ' . $form['redirect_url']);
        exit;
    }
    forms_public_render_thanks($form, $embed);
}

/** Format one submitted value for the on-screen confirmation summary. */
function forms_public_summary_value($v): string {
    if (is_bool($v))   return $v ? 'Yes' : '';
    if (is_array($v)) {
        if (!empty($v['signature'])) return 'Signed' . (!empty($v['name']) ? ' — ' . (string)$v['name'] : '');
        if (isset($v['path']))       return (string)($v['original'] ?? basename((string)$v['path']));
        return trim(implode(', ', array_map(fn($x) => is_scalar($x) ? (string)$x : '', $v)), ', ');
    }
    return trim((string)$v);
}

function forms_public_render_thanks(array $form, bool $embed): void {
    forms_public_layout_start('Thanks', $embed);
    $msg = $form['success_message'] ?: 'Thanks — we got your submission.';

    // Offer a "Save PDF" download when the ref+token are valid and the form's
    // setting allows it (and, in 'signed' mode, everything is signed).
    $ref = (string)($_GET['ref'] ?? '');
    $tok = (string)($_GET['t'] ?? '');
    // Fallback: if the ref/token didn't survive the redirect, recover the just-
    // saved submission from the session (set in forms_public_save_and_dispatch).
    if ($ref === '') {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        $last = $_SESSION['forms_last_pdf'] ?? null;
        if (is_array($last) && (int)($last['form'] ?? 0) === (int)$form['id'] && !empty($last['ref'])) {
            $ref = (string)$last['ref'];
            $tok = (string) forms_public_pdf_token($ref);
        }
    }
    $set = FormsAPI::formSettings($form['settings_json'] ?? null);
    $showPdf = $ref !== '' && slate_sign_equals('forms-pdf', $ref, $tok, 24) && $set['pdf_save_btn'] !== 'never';
    if ($showPdf && $set['pdf_save_btn'] === 'signed') {
        $sub  = Database::row("SELECT data_json FROM forms_submissions WHERE tenant_id = ? AND form_id = ? AND ref = ?",
                              [current_tenant_id(), (int)$form['id'], $ref]);
        $data = $sub ? (json_decode($sub['data_json'] ?? '[]', true) ?: []) : [];
        $showPdf = forms_public_all_signed($form, $data);
    }
    $pdfUrl = $showPdf ? SLATE_URL . '/forms/' . $form['slug'] . '/pdf?' . http_build_query(['ref' => $ref, 't' => $tok]) : '';

    // Receipt-style summary of the answers, shown right inside the success card.
    // Gated by the same valid ref+token check so a guessed ref can't reveal
    // someone else's submission.
    $summary  = [];
    $validRef = $ref !== '' && slate_sign_equals('forms-pdf', $ref, $tok, 24);
    if ($validRef) {
        $subRow = Database::row("SELECT data_json FROM forms_submissions WHERE tenant_id = ? AND form_id = ? AND ref = ?",
                                [current_tenant_id(), (int)$form['id'], $ref]);
        $sdata  = $subRow ? (json_decode($subRow['data_json'] ?? '[]', true) ?: []) : [];
        foreach (($form['fields'] ?? []) as $sf) {
            $st = $sf['type'] ?? '';
            if (in_array($st, ['heading', 'step', 'disclaimer'], true)) continue;
            $sn = $sf['name'] ?? '';
            if ($sn === '') continue;
            $sv = forms_public_summary_value($sdata[$sn] ?? '');
            if ($sv === '') continue;
            $summary[] = ['label' => (string)($sf['label'] ?? $sn), 'value' => $sv];
        }
    }
    ?>
    <main class="forms-public-shell">
        <article class="forms-public-card forms-public-success">
            <div class="forms-public-success-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
            </div>
            <h1><?= __('forms_thanks', 'Thanks!') ?></h1>
            <p><?= nl2br(e($msg)) ?></p>
            <?php if ($summary): ?>
            <div class="forms-receipt">
                <div class="forms-receipt-head">
                    <span><?= __('forms_your_submission', 'Your submission') ?></span>
                    <?php if ($ref !== ''): ?><span class="forms-receipt-ref"><?= e($ref) ?></span><?php endif; ?>
                </div>
                <dl class="forms-receipt-list">
                    <?php foreach ($summary as $r): ?>
                    <div class="forms-receipt-row">
                        <dt><?= e($r['label']) ?></dt>
                        <dd><?= nl2br(e($r['value'])) ?></dd>
                    </div>
                    <?php endforeach; ?>
                </dl>
            </div>
            <?php endif; ?>
            <?php if ($showPdf): ?>
                <a class="btn btn-primary fbtn fbtn-go forms-success-pdf" href="<?= e($pdfUrl) ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <span><?= __('forms_save_pdf', 'Save PDF') ?></span>
                </a>
            <?php endif; ?>
        </article>
    </main>
    <?php
    forms_public_layout_end($embed);
}

function forms_public_render_closed(array $form, bool $embed): void {
    forms_public_layout_start($form['title'], $embed);
    ?>
    <main class="forms-public-shell">
        <article class="forms-public-card">
            <h1><?= e($form['title']) ?></h1>
            <div class="alert alert-warning">
                <?= __('forms_submission_limit_reached', 'This form has reached its submission limit and is no longer accepting entries.') ?>
            </div>
        </article>
    </main>
    <?php
    forms_public_layout_end($embed);
}

function forms_public_render_notfound(string $msg, bool $embed): void {
    // Standalone: use the branded full-screen error page (hero + glass) so it
    // matches the rest of the site. Embedded (iframe): keep the compact card.
    if (!$embed) {
        require_once SLATE_ROOT . '/includes/error_page.php';
        slate_render_error(404, 'Form not available', $msg);
        return;
    }
    http_response_code(404);
    forms_public_layout_start(__('forms_not_found', 'Not found'), $embed);
    ?>
    <main class="forms-public-shell">
        <article class="forms-public-card">
            <h1><?= __('forms_not_found', 'Not found') ?></h1>
            <p class="text-muted"><?= e($msg) ?></p>
        </article>
    </main>
    <?php
    forms_public_layout_end($embed);
}

// ─────────────────────────────────────────────────────────────
//                  Save + email + webhook
// ─────────────────────────────────────────────────────────────

/**
 * Persist the submission — the ONLY work the submitter waits on: the DB insert
 * plus the contacts roll-up. Sets $outRef and stashes the PDF ref in the session
 * for the thanks page. The heavy work (PDF, emails, webhooks) is done separately
 * by forms_public_dispatch() AFTER the response is flushed.
 *
 * @return array{id:int, ref:string, submitter:?string}
 */
function forms_public_save(array $form, array $data, array $meta = [], ?string &$outRef = null): array {
    $tid = current_tenant_id();

    $submitterEmail = null;
    foreach ($form['fields'] as $f) {
        if (($f['type'] ?? '') === 'email' && !empty($data[$f['name']]) && filter_var($data[$f['name']], FILTER_VALIDATE_EMAIL)) {
            $submitterEmail = $data[$f['name']];
            break;
        }
    }

    $ref = FormsAPI::generateRef();

    $submissionId = Database::insert('forms_submissions', [
        'tenant_id'       => $tid,
        'form_id'         => (int)$form['id'],
        'ref'             => $ref,
        'data_json'       => json_encode($data, JSON_UNESCAPED_UNICODE),
        'submitter_email' => $submitterEmail,
        'ip'              => FormsAPI::clientIp(),
        'user_agent'      => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        'country'         => !empty($meta['country']) ? $meta['country'] : null,
        'email_sent'      => 0,
        'email_error'     => null,
    ]);

    // Contacts roll-up (best-effort; never let it break the submission path).
    try {
        FormsAPI::upsertContact($tid, $submitterEmail, $data);
    } catch (\Throwable $e) {
        slate_log('Forms: contact upsert failed: ' . $e->getMessage(), 'warning');
    }

    $outRef = $ref;
    // Stash for the thanks page so the "Save PDF" link works even if the ref
    // doesn't survive the redirect/rewrite.
    if (session_status() === PHP_SESSION_ACTIVE || @session_start()) {
        $_SESSION['forms_last_pdf'] = ['form' => (int)$form['id'], 'ref' => $ref];
    }

    return ['id' => (int)$submissionId, 'ref' => $ref, 'submitter' => $submitterEmail];
}

/**
 * Heavy post-submit work: branded PDF, admin notification + submitter
 * confirmation emails, audit log, bell notification, and webhooks. Built to run
 * AFTER the response is flushed (see forms_public_finish_response) so the
 * submitter never waits on SMTP. Every step is best-effort.
 *
 * @param array{id:int, ref:string, submitter:?string} $saved
 */
function forms_public_dispatch(array $form, array $data, array $saved): void {
    $tid            = current_tenant_id();
    $ref            = (string)($saved['ref'] ?? '');
    $submissionId   = (int)($saved['id'] ?? 0);
    $submitterEmail = $saved['submitter'] ?? null;
    if ($submissionId <= 0 || $ref === '') return;

    $fset = FormsAPI::formSettings($form['settings_json'] ?? null);

    // Optional branded PDF of the submission, attached to both emails.
    $attachments = [];
    if (!empty($fset['pdf_attach'])) {
        try {
            $pdfBytes = FormsAPI::submissionPdf($form, $data, $ref);
            if ($pdfBytes !== '') {
                $attachments[] = ['name' => 'submission-' . $ref . '.pdf', 'data' => $pdfBytes, 'mime' => 'application/pdf'];
            }
        } catch (\Throwable $e) {
            slate_log('Forms: PDF generation failed: ' . $e->getMessage(), 'warning');
        }
    }

    // Admin notification (best-effort, outcome backfilled onto the row).
    if (!empty($form['notify_email'])) {
        $emailSent = 0; $emailError = null;
        $adminUrl = function_exists('plugin_url')
            ? plugin_url('forms', 'admin/submission.php') . '?id=' . $submissionId
            : (defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/plugins/forms/admin/submission.php?id=' . $submissionId : '');
        $submittedAt = slate_db_now();
        // Per-form email template: pass admin-authored overrides through to
        // submissionEmailHtml(), and resolve the subject template here so the
        // Mailer line stays consistent.
        $body = FormsAPI::submissionEmailHtml($form, $data, $ref, [
            'accent'       => $fset['accent'] ?? '',
            'submitted_at' => $submittedAt,
            'admin_url'    => $adminUrl,
            'tpl'          => [
                'header_label' => $fset['email_header_label'] ?? '',
                'intro'        => $fset['email_intro']        ?? '',
                'outro'        => $fset['email_outro']        ?? '',
                'cta_label'    => $fset['email_cta_label']    ?? '',
                'show_table'   => $fset['email_show_table']   ?? true,
            ],
        ]);
        $subjTpl = trim((string)($fset['email_subject'] ?? '')) !== ''
                 ? (string)$fset['email_subject']
                 : 'New submission · {{form.title}} ({{ref}})';
        $subj = FormsAPI::renderTemplate($subjTpl, $form, $data, $ref, (string)$submitterEmail, $submittedAt);
        try {
            $ok = Mailer::send($form['notify_email'], $subj, $body, '', true, $attachments);
            $emailSent = $ok ? 1 : 0;
            if (!$ok) $emailError = 'Mailer::send returned false.';
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
        }
        Database::update('forms_submissions',
            ['email_sent' => $emailSent, 'email_error' => $emailError],
            'id = ? AND tenant_id = ?', [$submissionId, $tid]);
    }

    // Optional submitter confirmation.
    if (!empty($form['confirm_submitter']) && $submitterEmail) {
        $confirmSubject = $form['confirm_subject'] ?: ('Thanks — ' . $form['title']);
        $rawConfirm     = $form['confirm_body'] ?: ('<p>Thanks — we got your submission.</p>');
        $confirmBody    = FormsAPI::confirmationEmailHtml($form, $ref, $rawConfirm, [
            'accent'  => $fset['accent'] ?? '',
            'heading' => $confirmSubject,
        ]);
        try {
            Mailer::send($submitterEmail, $confirmSubject, $confirmBody, '', true, $attachments);
        } catch (\Throwable $e) {
            slate_log('Forms: confirmation email failed: ' . $e->getMessage(), 'warning');
        }
    }

    AuditLog::record('forms.submitted', (string)$submissionId, ['form_id' => (int)$form['id'], 'ref' => $ref]);

    if (class_exists('Notifications')) {
        Notifications::add(__('forms_notif_new_submission', 'New submission') . ' · ' . $ref, [
            'body' => sprintf(
                __('forms_notif_new_submission_body', '%s submitted "%s"'),
                ($submitterEmail ?: __('forms_notif_someone', 'Someone')),
                ($form['title'] ?? __('forms_notif_a_form', 'a form'))
            ),
            'url'  => function_exists('plugin_url')
                      ? plugin_url('forms', 'admin/submission.php') . '?id=' . $submissionId
                      : (defined('SLATE_URL') ? SLATE_URL . '/plugins/forms/admin/submission.php?id=' . $submissionId : ''),
            'icon' => 'mail',
        ]);
    }

    $payload = [
        'event'         => 'forms.submitted',
        'submission_id' => $submissionId,
        'ref'           => $ref,
        'form'          => ['id' => (int)$form['id'], 'slug' => $form['slug'], 'title' => $form['title']],
        'submitted_at'  => date('c'),
        'data'          => $data,
    ];
    FormsAPI::dispatchWebhooks((int)$form['id'], $submissionId, $payload);

    Hook::doAction('forms_submitted', $submissionId, (int)$form['id'], $data);
}

/** Back-compat: save + dispatch synchronously. Returns the submission id. */
function forms_public_save_and_dispatch(array $form, array $data, array $meta = [], ?string &$outRef = null): int {
    $saved = forms_public_save($form, $data, $meta, $outRef);
    forms_public_dispatch($form, $data, $saved);
    return (int)$saved['id'];
}

/**
 * Flush the response and end the request so the submitter's browser proceeds to
 * the thanks page immediately, while PHP keeps running the deferred dispatch.
 * Releases the session lock first (so the thanks page isn't blocked). Falls back
 * to a plain flush when the SAPI can't detach — no worse than before.
 */
function forms_public_finish_response(): void {
    if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
    if (function_exists('fastcgi_finish_request'))  { @fastcgi_finish_request();  return; }
    if (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); return; }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
}

/** Tamper-proof token for the public "Save PDF" link (no DB column needed). */
function forms_public_pdf_token(string $ref): ?string {
    // CORE-1: null when no secret is configured. Previously this fell back to
    // signing with the empty string, i.e. a key any caller could reproduce,
    // which made the PDF link forgeable for any ref.
    return slate_sign('forms-pdf', $ref, 24);
}

/** True when every signature field on the form has a value in $data. */
function forms_public_all_signed(array $form, array $data): bool {
    foreach ($form['fields'] as $f) {
        if (($f['type'] ?? '') !== 'signature') continue;
        $v = $data[$f['name'] ?? ''] ?? '';
        if (is_array($v)) $v = $v['value'] ?? ($v['image'] ?? reset($v));
        if ($v === '' || $v === null) return false;
    }
    return true;
}

// NOTE: the admin notification email is now built by the reusable, branded
// FormsAPI::submissionEmailHtml() (accent header, logo, meta chips, CTA, and a
// zebra-striped field table). The old plain-table builder was removed.
