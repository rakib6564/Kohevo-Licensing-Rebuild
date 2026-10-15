<?php
/**
 * Kohevo Studio — Code & Tracking Injection.
 *
 * Tenant-wide analytics IDs, search-engine verification tokens, head and footer code snippets and a custom CSS
 * stylesheet, all consumed by `StudioCodePolicy` at render time.
 *
 *   - GA4 / GTM container IDs     → Google's own fixed snippets (studio-builder.edit)
 *   - Verification tokens         → a fixed <meta> tag per service; only the token is stored (admin)
 *   - Header / footer snippets    → vendor tags (script, noscript, style, link, meta; footer also img, iframe),
 *                                   public pages only, never the canvas or Preview (admin, audited)
 *   - custom CSS                  → sanitized, emitted in <head> (studio-builder.edit)
 *
 * A field is only here if a renderer reads it. The snippet fields use NEW setting keys; the legacy
 * `studio_code_head` / `studio_code_footer` values (written by an old screen that nothing rendered) stay unread.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

// Scripts and CSS run on every public page, so only a Studio administrator may open or change this screen.
$isAdmin = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();
if (!$isAdmin) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to access this page.</p>';
    echo '<p>Required permission: <code>studio-builder.admin</code></p>';
    exit;
}
$canEdit = true;
$tenantId = current_tenant_id();

$pageTitle = __('studio_code_tracking', 'Code & tracking');
$currentNav = 'studio-code-tracking';

$flash = null;

// Read existing settings
$customCss = (string) (Database::setting(StudioCodePolicy::SETTING_CUSTOM_CSS, $tenantId) ?: '');
$ga4Id = (string) (Database::setting(StudioCodePolicy::SETTING_GA4, $tenantId) ?: '');
$gtmId = (string) (Database::setting(StudioCodePolicy::SETTING_GTM, $tenantId) ?: '');
$headSnippet = (string) (Database::setting(StudioCodePolicy::SETTING_HEAD_SNIPPET, $tenantId) ?: '');
$footerSnippet = (string) (Database::setting(StudioCodePolicy::SETTING_FOOTER_SNIPPET, $tenantId) ?: '');
$verifyStored = json_decode((string) (Database::setting(StudioCodePolicy::SETTING_VERIFICATION, $tenantId) ?: ''), true);
$verify = [];
foreach (array_keys(StudioCodePolicy::VERIFICATION_SERVICES) as $service) {
    $verify[$service] = is_array($verifyStored) && is_string($verifyStored[$service] ?? null) ? $verifyStored[$service] : '';
}

/** A plain-language message for a refused snippet (the policy's reason code). */
$snippetMessage = static function (string $label, string $reason): string {
    [$kind, $detail] = array_pad(explode(':', $reason, 2), 2, '');
    return $label . ': ' . match ($kind) {
        'too_large' => 'the code is larger than ' . (StudioCodePolicy::MAX_SNIPPET_BYTES / 1024) . ' KiB.',
        'control_characters' => 'it contains control characters.',
        'unclosed' => 'a <' . $detail . '> is not closed.',
        'stray_close' => 'a </' . $detail . '> has no matching opening tag.',
        'tag' => '<' . $detail . '> is not allowed here. Snippets may only contain script, noscript, style, link and meta tags (the footer also img and iframe).',
        default => 'it could not be accepted.',
    };
};

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage scripts and CSS.')];
    } else {
        $customCss = (string) ($_POST['custom_css'] ?? '');
        $ga4Id = trim((string) ($_POST['ga4_id'] ?? ''));
        $gtmId = trim((string) ($_POST['gtm_id'] ?? ''));
        if ($isAdmin) {
            $headSnippet = (string) ($_POST['head_snippet'] ?? '');
            $footerSnippet = (string) ($_POST['footer_snippet'] ?? '');
            foreach (array_keys($verify) as $service) {
                $verify[$service] = trim((string) ($_POST['verify_' . $service] ?? ''));
            }
        }

        // Validation mirrors StudioCodePolicy exactly, so a malformed value is rejected here with a message
        // instead of being stored and silently dropped at render time. Nothing is saved unless everything is valid.
        $errors = [];
        if (StudioCodePolicy::ga4Id($ga4Id) === null && $ga4Id !== '') {
            $errors[] = 'GA4 must look like G-XXXXXXXXXX.';
        }
        if (StudioCodePolicy::gtmId($gtmId) === null && $gtmId !== '') {
            $errors[] = 'GTM must look like GTM-XXXXXXX.';
        }
        $verifyTokens = [];
        $preparedHead = '';
        $preparedFooter = '';
        if ($isAdmin) {
            foreach ($verify as $service => $raw) {
                $token = StudioCodePolicy::verificationToken($raw);
                if ($token === null) {
                    $errors[] = ucfirst($service) . ' verification: paste the token or the whole meta tag the service gives you.';
                } else {
                    $verifyTokens[$service] = $token;
                }
            }
            foreach (['Header code' => ['head', $headSnippet], 'Footer code' => ['footer', $footerSnippet]] as $label => [$placement, $raw]) {
                try {
                    $prepared = StudioCodePolicy::prepareSnippet($raw, $placement);
                    if ($placement === 'head') { $preparedHead = $prepared; } else { $preparedFooter = $prepared; }
                } catch (\InvalidArgumentException $e) {
                    $errors[] = $snippetMessage($label, $e->getMessage());
                }
            }
        }

        if ($errors !== []) {
            $flash = ['type' => 'error', 'msg' => 'Nothing was saved. ' . implode(' ', $errors)];
        } else {
            $sanitizedCss = StudioCodePolicy::sanitizeCustomCss($customCss);
            Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $sanitizedCss, $tenantId);
            Database::setSetting(StudioCodePolicy::SETTING_GA4, $ga4Id, $tenantId);
            Database::setSetting(StudioCodePolicy::SETTING_GTM, $gtmId, $tenantId);

            $audit = [
                'ga4'   => $ga4Id !== '',
                'gtm'   => $gtmId !== '',
                'css_bytes' => strlen($sanitizedCss),
            ];
            if ($isAdmin) {
                Database::setSetting(StudioCodePolicy::SETTING_VERIFICATION, json_encode(array_filter($verifyTokens, static fn (string $v): bool => $v !== ''), JSON_THROW_ON_ERROR), $tenantId);
                Database::setSetting(StudioCodePolicy::SETTING_HEAD_SNIPPET, $preparedHead, $tenantId);
                Database::setSetting(StudioCodePolicy::SETTING_FOOTER_SNIPPET, $preparedFooter, $tenantId);
                $verify = $verifyTokens;
                $headSnippet = $preparedHead;
                $footerSnippet = $preparedFooter;
                // Sizes and a SHA-256 of each snippet, never the code itself: enough to see WHAT changed and WHEN.
                $audit += [
                    'verification' => array_keys(array_filter($verifyTokens, static fn (string $v): bool => $v !== '')),
                    'head_bytes'   => strlen($preparedHead),
                    'head_sha256'  => $preparedHead === '' ? null : hash('sha256', $preparedHead),
                    'footer_bytes' => strlen($preparedFooter),
                    'footer_sha256' => $preparedFooter === '' ? null : hash('sha256', $preparedFooter),
                ];
            }
            AuditLog::record('studio.code_tracking_updated', (string) $tenantId, $audit);

            $flash = ['type' => 'success', 'msg' => $isAdmin ? 'Tags, verification, code snippets and CSS saved.' : 'Analytics IDs and custom CSS saved.'];
        }
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('code-tracking', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> sb-alert">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="sb-page-head">
        <h2>Custom code, tracking &amp; CSS</h2>
        <p>Connect Google Search Console and Analytics, add marketing pixels or any vendor tag to the header or footer, and style the whole site with CSS.</p>
    </div>

    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/code-tracking.php')) ?>">
        <?= csrf_field() ?>

        <!-- Analytics -->
        <section class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('zap', 18) ?></span>
                <div>
                    <h3 class="sb-card__title">Analytics &amp; tag IDs</h3>
                    <p class="sb-card__sub">Load Google's tags without editing a template.</p>
                </div>
            </div>
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label" for="ga4_id">Google Analytics 4 measurement ID</label>
                    <input type="text" id="ga4_id" name="ga4_id" value="<?= e($ga4Id) ?>" class="form-control" placeholder="G-XXXXXXXXXX">
                    <p class="sb-field__hint">Loads gtag.js asynchronously on every public page.</p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="gtm_id">Google Tag Manager container ID</label>
                    <input type="text" id="gtm_id" name="gtm_id" value="<?= e($gtmId) ?>" class="form-control" placeholder="GTM-XXXXXXX">
                    <p class="sb-field__hint">Adds the official container snippets to the head and body.</p>
                </div>
            </div>
        </section>

        <?php if ($isAdmin): ?>
        <!-- Search engine & platform verification -->
        <section class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('check', 18) ?></span>
                <div>
                    <h3 class="sb-card__title">Site verification</h3>
                    <p class="sb-card__sub">Prove you own the site to search engines and social platforms.</p>
                </div>
            </div>
            <p class="sb-card__note">
                Paste the token, or the whole <code>&lt;meta&gt;</code> tag, that Google Search Console, Bing Webmaster Tools, Meta or Pinterest gives you.
                Only the token is stored; the tag is added to the <code>&lt;head&gt;</code> of your public pages.
            </p>
            <div class="sb-form-grid">
                <?php foreach ([
                    'google' => ['Google Search Console', 'google-site-verification'],
                    'bing' => ['Bing Webmaster Tools', 'msvalidate.01'],
                    'facebook' => ['Meta (Facebook) domain', 'facebook-domain-verification'],
                    'pinterest' => ['Pinterest', 'p:domain_verify'],
                ] as $service => [$label, $metaName]): ?>
                    <div class="sb-field">
                        <label class="sb-field__label" for="verify_<?= e($service) ?>"><?= e($label) ?></label>
                        <input type="text" id="verify_<?= e($service) ?>" name="verify_<?= e($service) ?>" value="<?= e($verify[$service]) ?>" class="form-control" autocomplete="off" spellcheck="false" placeholder="<meta name=&quot;<?= e($metaName) ?>&quot; content=&quot;…&quot;> or the token">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Header and footer code -->
        <section class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('code', 18) ?></span>
                <div>
                    <h3 class="sb-card__title">Header &amp; footer code</h3>
                    <p class="sb-card__sub">Vendor tags and pixels, on the live site only.</p>
                </div>
            </div>
            <div class="alert alert-warning sb-alert" style="font-size: 0.85rem; margin-bottom: 18px;">
                This code runs on every public page of your site, for every visitor. Only add code from vendors you trust.
                It is <strong>not</strong> loaded in the builder canvas or in Preview, only on the live site, and only administrators can change it.
                Each change is recorded in the audit log.
            </div>
            <div class="sb-form-grid">
                <div class="sb-field">
                    <label class="sb-field__label" for="head_snippet">Header code <small>(inside &lt;head&gt;)</small></label>
                    <textarea id="head_snippet" name="head_snippet" rows="8" class="form-control sb-code-input" spellcheck="false" autocomplete="off" placeholder="<!-- e.g. Meta Pixel, Hotjar, Clarity, a verification meta tag -->"><?= e($headSnippet) ?></textarea>
                    <p class="sb-field__hint">script, noscript, style, link and meta tags.</p>
                </div>
                <div class="sb-field">
                    <label class="sb-field__label" for="footer_snippet">Footer code <small>(end of &lt;body&gt;)</small></label>
                    <textarea id="footer_snippet" name="footer_snippet" rows="8" class="form-control sb-code-input" spellcheck="false" autocomplete="off" placeholder="<!-- e.g. a chat widget, a tracking pixel, a noscript fallback -->"><?= e($footerSnippet) ?></textarea>
                    <p class="sb-field__hint">The same tags, plus img and iframe. At most <?= (int) (StudioCodePolicy::MAX_SNIPPET_BYTES / 1024) ?> KiB each.</p>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- Global custom CSS -->
        <section class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('edit', 18) ?></span>
                <div>
                    <h3 class="sb-card__title">Global custom CSS</h3>
                    <p class="sb-card__sub">Loaded after Studio's own stylesheet on every public and preview page.</p>
                </div>
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="custom_css">Style rules</label>
                <textarea id="custom_css" name="custom_css" rows="8" class="form-control sb-code-input" spellcheck="false" placeholder="/* Custom CSS overrides */
.sb-custom-highlight {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}"><?= e($customCss) ?></textarea>
                <p class="sb-field__hint">These rules override block styling. They are not applied inside the builder canvas, so the canvas always shows the published design. <code>@import</code> and script-bearing URLs are stripped on save.</p>
            </div>
        </section>

        <div class="sb-card-actions" style="margin-top: 0;">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('save', 14) ?> Save
            </button>
        </div>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
