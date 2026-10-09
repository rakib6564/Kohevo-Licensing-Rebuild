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

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$isAdmin = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();
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
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Custom Code, Tracking & CSS</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Connect Google Search Console and Analytics, add marketing pixels or any vendor tag to the header or footer, and style the whole site with CSS.</p>
    </div>

    <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/code-tracking.php')) ?>">
        <?= csrf_field() ?>

        <!-- Section 1: Fast Analytics Integration -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('zap', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Analytics & Tag IDs</h3>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Google Analytics 4 Measurement ID</label>
                    <input type="text" name="ga4_id" value="<?= e($ga4Id) ?>" class="form-control" placeholder="G-XXXXXXXXXX">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Automatically loads gtag.js asynchronously without editing templates.</span>
                </div>

                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Google Tag Manager Container ID</label>
                    <input type="text" name="gtm_id" value="<?= e($gtmId) ?>" class="form-control" placeholder="GTM-XXXXXXX">
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Injects official GTM container snippets into head and body.</span>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Section: Search engine & platform verification -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('check', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Site verification</h3>
            </div>
            <p style="font-size: 0.85rem; color: var(--sb-muted); margin-bottom: 14px;">
                Paste the token, or the whole <code>&lt;meta&gt;</code> tag, that Google Search Console, Bing Webmaster Tools, Meta or Pinterest gives you.
                Only the token is stored; the tag is added to the <code>&lt;head&gt;</code> of your public pages.
            </p>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px;">
                <?php foreach ([
                    'google' => ['Google Search Console', 'google-site-verification'],
                    'bing' => ['Bing Webmaster Tools', 'msvalidate.01'],
                    'facebook' => ['Meta (Facebook) domain', 'facebook-domain-verification'],
                    'pinterest' => ['Pinterest', 'p:domain_verify'],
                ] as $service => [$label, $metaName]): ?>
                    <div>
                        <label class="form-label" style="font-weight: 600; font-size: 0.85rem;"><?= e($label) ?></label>
                        <input type="text" name="verify_<?= e($service) ?>" value="<?= e($verify[$service]) ?>" class="form-control" autocomplete="off" spellcheck="false" placeholder="<meta name=&quot;<?= e($metaName) ?>&quot; content=&quot;…&quot;> or the token">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section: Header and footer code -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('code', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Header &amp; footer code</h3>
            </div>
            <div class="alert alert-warning" style="border-radius: 10px; margin-bottom: 14px; font-size: 0.85rem;">
                This code runs on every public page of your site, for every visitor. Only add code from vendors you trust.
                It is <strong>not</strong> loaded in the builder canvas or in Preview, only on the live site, and only administrators can change it.
                Each change is recorded in the audit log.
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;" for="head_snippet">Header code <span style="font-weight: 400; color: var(--sb-muted);">(inside &lt;head&gt;)</span></label>
                    <textarea id="head_snippet" name="head_snippet" rows="8" class="form-control" spellcheck="false" autocomplete="off" style="font-family: ui-monospace, monospace; font-size: 0.85rem; background: #0f172a; color: #cbd5e1;" placeholder="<!-- e.g. Meta Pixel, Hotjar, Clarity, a verification meta tag -->"><?= e($headSnippet) ?></textarea>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">script, noscript, style, link and meta tags.</span>
                </div>
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;" for="footer_snippet">Footer code <span style="font-weight: 400; color: var(--sb-muted);">(end of &lt;body&gt;)</span></label>
                    <textarea id="footer_snippet" name="footer_snippet" rows="8" class="form-control" spellcheck="false" autocomplete="off" style="font-family: ui-monospace, monospace; font-size: 0.85rem; background: #0f172a; color: #cbd5e1;" placeholder="<!-- e.g. a chat widget, a tracking pixel, a noscript fallback -->"><?= e($footerSnippet) ?></textarea>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">The same tags, plus img and iframe. At most <?= (int) (StudioCodePolicy::MAX_SNIPPET_BYTES / 1024) ?> KiB each.</span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Section 2: Global Custom CSS -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 28px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('edit', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Global Custom CSS</h3>
            </div>

            <p style="font-size: 0.85rem; color: var(--sb-muted); margin-bottom: 12px;">
                Style rules defined here are loaded after Studio's own stylesheet on every
                public and preview page, so they override block styling. They are not applied
                inside the builder canvas, so the canvas always shows the published design.
                <code>@import</code> and script-bearing URLs are stripped on save.
            </p>

            <textarea name="custom_css" rows="6" class="form-control" style="font-family: ui-monospace, monospace; font-size: 0.85rem; background: #0f172a; color: #cbd5e1;" placeholder="/* Custom CSS overrides */
.sb-custom-highlight {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
}"><?= e($customCss) ?></textarea>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('save', 14) ?> Save
            </button>
        </div>
    </form>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
