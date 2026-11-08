<?php
/**
 * Kohevo Studio — Site & SEO Controls.
 *
 * Dedicated admin page for global SEO settings, OpenGraph social card simulator,
 * Google SERP preview, search engine verification, sitemap.xml, and robots.txt.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_seo', 'Site & SEO');
$currentNav = 'studio-seo';

$flash = null;

// Read existing settings
$seoTitleFormat = (string) (Database::setting('studio_seo_title_format', $tenantId) ?: '%title% | %site_name%');
$seoDescription = (string) (Database::setting('studio_seo_description', $tenantId) ?: '');
$seoOgMediaId   = (string) (Database::setting('studio_seo_og_media_id', $tenantId) ?: '');
$seoOgImageUrl  = (string) (Database::setting('studio_seo_og_image_url', $tenantId) ?: '');
$seoRobots      = (string) (Database::setting('studio_seo_robots', $tenantId) ?: 'index,follow');
// Verification codes share one setting with Code & tracking, which prints them (each tag once).
$verifyTokens = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::verificationTokens($tenantId);
$seoGoogleVerification = $verifyTokens['google'];
$seoBingVerification   = $verifyTokens['bing'];
// Web fonts and the business for structured data (their own forms below).
$webFonts = (string) (Database::setting(\Slate\Module\StudioBuilder\Render\Seo\WebFonts::SETTING, $tenantId) ?: '');
$business = \Slate\Module\StudioBuilder\Render\Seo\StructuredData::business($tenantId);
$smoothScroll = (string) (Database::setting(\Slate\Module\StudioBuilder\Render\PageDocumentAssembler::SMOOTH_SCROLL_SETTING, $tenantId) ?: '') === '1';
// The name the live pages use for %site_name%.
try {
    $siteName = \Slate\Services\Content\TenantBranding::siteName();
} catch (\Throwable $e) {
    $siteName = '';
}

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit SEO settings.')];
    } elseif (!sb_studio_entitled()) {
        $flash = ['type' => 'error', 'msg' => __('studio_pages_forbidden', 'Kohevo Studio is not available for your account on this site.')];
    } elseif (($_POST['_form'] ?? '') === 'fonts') {
        $webFonts = trim((string) ($_POST['web_fonts'] ?? ''));
        $parsed = \Slate\Module\StudioBuilder\Render\Seo\WebFonts::parse($webFonts);
        if ($parsed['bad'] !== []) {
            $flash = ['type' => 'error', 'msg' => __('studio_code_nothing_saved', 'Nothing was saved.') . ' ' . sprintf(__('studio_fonts_bad', 'These lines are not a font (write "Family: 400, 700"): %s'), implode(' · ', $parsed['bad']))];
        } else {
            Database::setSetting(\Slate\Module\StudioBuilder\Render\Seo\WebFonts::SETTING, $webFonts, $tenantId);
            sb_flash_redirect('seo.php', ['type' => 'success', 'msg' => __('studio_fonts_saved', 'Fonts saved. Every page loads them from its next visit.')]);
        }
    } elseif (($_POST['_form'] ?? '') === 'scrolling') {
        $smoothScroll = ($_POST['smooth_scroll'] ?? '') === '1';
        Database::setSetting(\Slate\Module\StudioBuilder\Render\PageDocumentAssembler::SMOOTH_SCROLL_SETTING, $smoothScroll ? '1' : '0', $tenantId);
        sb_flash_redirect('seo.php', ['type' => 'success', 'msg' => __('studio_scrolling_saved', 'Saved. Every page scrolls this way from its next visit.')]);
    } elseif (($_POST['_form'] ?? '') === 'business') {
        $business = \Slate\Module\StudioBuilder\Render\Seo\StructuredData::clean([
            'type' => (string) ($_POST['biz_type'] ?? ''), 'name' => (string) ($_POST['biz_name'] ?? ''), 'alternate_name' => (string) ($_POST['biz_alternate'] ?? ''),
            'description' => (string) ($_POST['biz_description'] ?? ''), 'logo_media_id' => (int) ($_POST['biz_logo_media_id'] ?? 0),
            'founder' => (string) ($_POST['biz_founder'] ?? ''), 'same_as' => (string) ($_POST['biz_same_as'] ?? ''),
            'telephone' => (string) ($_POST['biz_telephone'] ?? ''), 'email' => (string) ($_POST['biz_email'] ?? ''), 'vat_id' => (string) ($_POST['biz_vat_id'] ?? ''),
            'street' => (string) ($_POST['biz_street'] ?? ''), 'postal_code' => (string) ($_POST['biz_postal_code'] ?? ''), 'locality' => (string) ($_POST['biz_locality'] ?? ''), 'country' => (string) ($_POST['biz_country'] ?? ''),
        ]);
        \Slate\Module\StudioBuilder\Render\Seo\StructuredData::save($tenantId, $business);
        sb_flash_redirect('seo.php', ['type' => 'success', 'msg' => __('studio_business_saved', 'Saved. Every public page tells search engines about the business from its next visit.')]);
    } else {
        $seoTitleFormat = trim((string) ($_POST['title_format'] ?? ''));
        $seoDescription = trim((string) ($_POST['description'] ?? ''));
        $seoOgMediaId   = trim((string) ($_POST['og_media_id'] ?? ''));
        $seoOgImageUrl  = trim((string) ($_POST['og_image_url'] ?? ''));
        $seoRobots      = trim((string) ($_POST['robots'] ?? 'index,follow'));
        $seoGoogleVerification = trim((string) ($_POST['google_verify'] ?? ''));
        $seoBingVerification   = trim((string) ($_POST['bing_verify'] ?? ''));
        $googleToken = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::verificationToken($seoGoogleVerification);
        $bingToken   = \Slate\Module\StudioBuilder\Http\StudioCodePolicy::verificationToken($seoBingVerification);
        if ($googleToken === null || $bingToken === null) {
            $flash = ['type' => 'error', 'msg' => __('studio_code_nothing_saved', 'Nothing was saved.') . ' ' . __('studio_seo_bad_verification', 'Verification: paste the code or the whole meta tag Google or Bing gives you.')];
        }
    }
    if (isset($googleToken, $bingToken) && $flash === null) {
        Database::setSetting('studio_seo_title_format', $seoTitleFormat, $tenantId);
        Database::setSetting('studio_seo_description', $seoDescription, $tenantId);
        Database::setSetting('studio_seo_og_media_id', $seoOgMediaId, $tenantId);
        Database::setSetting('studio_seo_og_image_url', $seoOgImageUrl, $tenantId);
        Database::setSetting('studio_seo_robots', $seoRobots, $tenantId);
        \Slate\Module\StudioBuilder\Http\StudioCodePolicy::saveVerificationTokens($tenantId, ['google' => $googleToken, 'bing' => $bingToken]);

        sb_flash_redirect('seo.php', ['type' => 'success', 'msg' => __('studio_seo_saved', 'Saved. Published pages use these settings from their next visit.')]);
    }
}
$flash = $flash ?? sb_take_flash();


// Resolve OG image preview if ID exists
$ogImageUrl = $seoOgImageUrl;
if ($seoOgMediaId !== '' && is_numeric($seoOgMediaId)) {
    $mediaRow = Database::row("SELECT path FROM media_files WHERE tenant_id = ? AND id = ? LIMIT 1", [$tenantId, (int) $seoOgMediaId]);
    if ($mediaRow && !empty($mediaRow['path'])) {
        $ogImageUrl = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') . '/' . ltrim((string) $mediaRow['path'], '/') : '/' . ltrim((string) $mediaRow['path'], '/');
    }
}

// The previews show a real page of this site: the home page, else the latest published page.
$samplePage = Database::row(
    "SELECT title, slug, route_mode FROM studiobuilder_pages WHERE tenant_id = ? AND status = 'published' AND page_type IN ('page', 'landing')
     ORDER BY route_mode = 'homepage' DESC, published_at DESC LIMIT 1",
    [$tenantId]
) ?: null;
$sampleTitle = $samplePage ? (string) $samplePage['title'] : ($siteName !== '' ? $siteName : __('studio_seo_sample_home', 'Home'));
$siteHost = (string) (parse_url(SLATE_URL, PHP_URL_HOST) ?: '');
$sampleCrumbs = array_values(array_filter(explode('/', (string) (parse_url(SLATE_URL, PHP_URL_PATH) ?: ''))));
if ($samplePage && $samplePage['route_mode'] !== 'homepage') {
    $sampleCrumbs[] = (string) $samplePage['slug'];
}
$sampleTitleText = static fn(string $format): string => str_replace(['%title%', '%site_name%'], [$sampleTitle, $siteName], $format !== '' ? $format : '%title%');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <?php sb_render_flash($flash); ?>

    <div class="sb-page-head">
        <h2><?= e(__('studio_seo_heading', 'Site & Search Engine Optimization')) ?></h2>
        <p><?= e(__('studio_seo_intro', 'Site-wide defaults for every Studio page. The title format, description and share image are used when a page leaves its own SEO fields empty. A site set to noindex keeps every page out of search and out of the sitemap. Verification codes are added to every public page.')) ?></p>
    </div>

    <!-- Quick links -->
    <div class="sb-two-up">
        <div class="sb-card sb-link-card">
            <div>
                <span class="sb-link-card__kicker" style="color: #10b981;"><?= e(__('studio_seo_sitemap_kicker', 'Live XML feed')) ?></span>
                <h4>sitemap.xml</h4>
                <p><?= e(__('studio_seo_sitemap_desc', 'Every published, indexable page, kept up to date automatically.')) ?></p>
            </div>
            <a href="<?= e(SLATE_URL . '/sitemap.xml') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> <?= e(__('view', 'View')) ?>
            </a>
        </div>

        <div class="sb-card sb-link-card">
            <div>
                <span class="sb-link-card__kicker" style="color: var(--sb-accent);"><?= e(__('studio_seo_robots_kicker', 'Bot directives')) ?></span>
                <h4>robots.txt</h4>
                <p><?= e(__('studio_seo_robots_desc', 'Base crawl permissions and the sitemap location.')) ?></p>
            </div>
            <a href="<?= e(SLATE_URL . '/robots.txt') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline">
                <?= sb_svg('external-link', 14) ?> <?= e(__('view', 'View')) ?>
            </a>
        </div>
    </div>

    <!-- SEO settings and a live preview, side by side -->
    <div class="sb-two-up">
        <!-- Left: settings form -->
        <div class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('seo', 18) ?></span>
                <div>
                    <h3 class="sb-card__title"><?= e(__('studio_seo_controls', 'Global SEO controls')) ?></h3>
                    <p class="sb-card__sub"><?= e(__('studio_seo_controls_sub', 'Defaults for every page that does not set its own.')) ?></p>
                </div>
            </div>

            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>">
                <?= csrf_field() ?>
                <div class="sb-stack">
                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-title"><?= e(__('studio_seo_title_format', 'Title format pattern')) ?></label>
                        <input type="text" name="title_format" id="seo-input-title" value="<?= e($seoTitleFormat) ?>" class="form-control" placeholder="%title% | %site_name%">
                        <p class="sb-field__hint"><?= e(__('studio_seo_title_tags', 'Supported tags:')) ?> <code>%title%</code>, <code>%site_name%</code>.</p>
                    </div>

                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-desc"><?= e(__('studio_seo_description', 'Global meta description')) ?></label>
                        <textarea name="description" id="seo-input-desc" rows="3" class="form-control" placeholder="<?= e(__('studio_seo_description_placeholder', 'Compelling summary under 160 characters...')) ?>"><?= e($seoDescription) ?></textarea>
                        <p class="sb-field__hint"><?= e(__('studio_seo_description_hint', 'Search engines show about 160 characters.')) ?></p>
                    </div>

                    <div>
                        <input type="hidden" name="og_media_id" id="seo_og_media_id" value="<?= e($seoOgMediaId) ?>" data-sb-id-for="seo_og_image_url">
                        <?= sb_media_picker_field([
                            'id'          => 'seo_og_image_url',
                            'name'        => 'og_image_url',
                            'label'       => __('studio_seo_og_image', 'Default social share image (OpenGraph)'),
                            'value'       => $ogImageUrl,
                            'placeholder' => __('studio_seo_og_image_placeholder', 'https://... or choose from Media Library'),
                            'help'        => __('studio_seo_og_image_help', 'Select an asset from your Media Library (recommended: 1200×630px). Used for Twitter/X, LinkedIn, and social embeds.'),
                        ]) ?>
                    </div>

                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-input-robots"><?= e(__('studio_seo_robots', 'Search robots policy')) ?></label>
                        <select name="robots" id="seo-input-robots" class="form-control">
                            <option value="index,follow" <?= $seoRobots === 'index,follow' ? 'selected' : '' ?>><?= e(__('studio_seo_robots_index_follow', 'index, follow (Recommended — allow indexing & links)')) ?></option>
                            <option value="noindex,follow" <?= $seoRobots === 'noindex,follow' ? 'selected' : '' ?>><?= e(__('studio_seo_robots_noindex_follow', 'noindex, follow (hide from search, follow internal links)')) ?></option>
                            <option value="index,nofollow" <?= $seoRobots === 'index,nofollow' ? 'selected' : '' ?>><?= e(__('studio_seo_robots_index_nofollow', 'index, nofollow (index page, do not crawl outbound links)')) ?></option>
                            <option value="noindex,nofollow" <?= $seoRobots === 'noindex,nofollow' ? 'selected' : '' ?>><?= e(__('studio_seo_robots_noindex_nofollow', 'noindex, nofollow (complete bot blackout)')) ?></option>
                        </select>
                    </div>

                    <div class="sb-form-grid">
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-input-google"><?= e(__('studio_seo_google_verify_label', 'Google site verification tag')) ?></label>
                            <input type="text" name="google_verify" id="seo-input-google" value="<?= e($seoGoogleVerification) ?>" class="form-control" placeholder="<?= e(__('studio_seo_google_verify_placeholder', 'google-site-verification code')) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-input-bing"><?= e(__('studio_seo_bing_verify_label', 'Bing Webmaster tag')) ?></label>
                            <input type="text" name="bing_verify" id="seo-input-bing" value="<?= e($seoBingVerification) ?>" class="form-control" placeholder="<?= e(__('studio_seo_bing_verify_placeholder', 'msvalidate.01 code')) ?>">
                        </div>
                    </div>
                </div>

                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary">
                        <?= sb_svg('save', 14) ?> <?= e(__('studio_seo_save', 'Save SEO settings')) ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: Real-time SERP & Social Card Simulator -->
        <div>
            <!-- Google SERP Snippet Preview -->
            <div class="sb-card">
                <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sb-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <?= sb_svg('globe', 14) ?> <?= e(__('studio_seo_serp_preview', 'Google Search Result Simulator')) ?>
                </div>
                <div style="font-family: Arial, sans-serif;">
                    <div style="font-size: 0.82rem; color: #202124; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                        <span><?= e($siteHost) ?></span>
                        <?php foreach ($sampleCrumbs as $crumb): ?><span style="color: #5f6368;">› <?= e($crumb) ?></span><?php endforeach; ?>
                    </div>
                    <div style="font-size: 1.15rem; color: #1a0dab; line-height: 1.3; font-weight: 400; margin-bottom: 4px;" data-seo-preview="title">
                        <?= e($sampleTitleText($seoTitleFormat)) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: #4d5156; line-height: 1.4;" data-seo-preview="description">
                        <?= e($seoDescription) ?>
                    </div>
                    <p class="sb-field__hint" style="margin-top: 10px;"><?= e(sprintf(__('studio_seo_preview_note', 'Shown with %s. A page\'s own SEO title and description replace these defaults.'), $sampleTitle)) ?></p>
                </div>
            </div>

            <!-- Twitter / OpenGraph Card Preview -->
            <div class="sb-card" style="margin-bottom: 0;">
                <div style="font-size: 0.72rem; text-transform: uppercase; font-weight: 700; color: var(--sb-muted); margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <?= sb_svg('eye', 14) ?> <?= e(__('studio_seo_card_preview', 'OpenGraph / Twitter Card Preview')) ?>
                </div>
                <div style="border: 1px solid var(--sb-border); border-radius: 12px; overflow: hidden; background: #0f172a;">
                    <div style="height: 180px; background: #1e293b; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <?php if ($ogImageUrl !== ''): ?>
                            <img src="<?= e($ogImageUrl) ?>" alt="<?= e(__('studio_seo_card_alt', 'Social Preview')) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <div style="text-align: center; color: #94a3b8;">
                                <?= sb_svg('media', 36) ?>
                                <div style="font-size: 0.75rem; margin-top: 6px;"><?= e(__('studio_seo_no_og_image', 'No OpenGraph Media ID set')) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="padding: 14px; background: #ffffff;">
                        <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--sb-muted); font-weight: 600; margin-bottom: 4px;">
                            <?= e($siteHost) ?>
                        </div>
                        <div style="font-weight: 700; font-size: 0.95rem; color: var(--sb-text); margin-bottom: 4px;" data-seo-preview="title">
                            <?= e($sampleTitleText($seoTitleFormat)) ?>
                        </div>
                        <div style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4;" data-seo-preview="card-description">
                            <?= e(mb_strimwidth($seoDescription, 0, 110, '...')) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fonts and structured data, written by Studio (no pasted code) -->
    <div class="sb-two-up">
        <div class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('palette', 18) ?></span>
                <div>
                    <h3 class="sb-card__title"><?= e(__('studio_fonts_title', 'Web fonts')) ?></h3>
                    <p class="sb-card__sub"><?= e(__('studio_fonts_sub', 'Google Fonts every page loads, for the fonts your theme names.')) ?></p>
                </div>
            </div>
            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_form" value="fonts">
                <div class="sb-field">
                    <label class="sb-field__label" for="seo-web-fonts"><?= e(__('studio_fonts_label', 'One font per line')) ?></label>
                    <textarea name="web_fonts" id="seo-web-fonts" rows="4" class="form-control" spellcheck="false" placeholder="Karla: 400, 700&#10;Lora: 400, 400 italic, 700"><?= e($webFonts) ?></textarea>
                    <p class="sb-field__hint"><?= e(__('studio_fonts_hint', 'The family as Google Fonts names it, then its weights; "400 italic" for italics. Google\'s own notation (Fraunces:opsz,wght@9..144,500) works too. Then pick the fonts under Site settings › Fonts. Visitors\' browsers fetch them from Google.')) ?></p>
                </div>
                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <?= e(__('studio_fonts_save', 'Save fonts')) ?></button>
                </div>
            </form>
            <!-- Smooth scrolling: one site-wide switch, read by studio-runtime.js on every public page -->
            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>" class="sb-stack" style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--sb-border, #e5e7eb);">
                <?= csrf_field() ?>
                <input type="hidden" name="_form" value="scrolling">
                <h3 class="sb-card__title"><?= e(__('studio_scrolling_title', 'Smooth scrolling')) ?></h3>
                <label class="sb-field" style="display: flex; gap: 8px; align-items: flex-start;">
                    <input type="checkbox" name="smooth_scroll" value="1" <?= $smoothScroll ? 'checked' : '' ?>>
                    <span><?= e(__('studio_scrolling_label', 'Ease the page as visitors scroll with a mouse wheel')) ?></span>
                </label>
                <p class="sb-field__hint"><?= e(__('studio_scrolling_hint', 'Touch screens, the keyboard and the scroll bar keep their own scrolling, and visitors who ask their device for less motion never get it.')) ?></p>
                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <?= e(__('studio_scrolling_save', 'Save scrolling')) ?></button>
                </div>
            </form>
        </div>

        <div class="sb-card">
            <div class="sb-card__head">
                <span class="sb-card__icon"><?= sb_svg('globe', 18) ?></span>
                <div>
                    <h3 class="sb-card__title"><?= e(__('studio_business_title', 'Business details for search engines')) ?></h3>
                    <p class="sb-card__sub"><?= e(__('studio_business_sub', 'Written into every page as structured data, so Google knows who publishes the site.')) ?></p>
                </div>
            </div>
            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/seo.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_form" value="business">
                <div class="sb-stack">
                    <div class="sb-form-grid">
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-type"><?= e(__('studio_business_type', 'Kind of business')) ?></label>
                            <select name="biz_type" id="seo-biz-type" class="form-control">
                                <option value=""><?= e(__('studio_business_none', 'None (no business data)')) ?></option>
                                <?php foreach (\Slate\Module\StudioBuilder\Render\Seo\StructuredData::TYPES as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $business['type'] === $k ? 'selected' : '' ?>><?= e(__('studio_business_type_' . strtolower($k), $label)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-name"><?= e(__('studio_business_name', 'Name')) ?></label>
                            <input type="text" name="biz_name" id="seo-biz-name" maxlength="160" class="form-control" value="<?= e($business['name']) ?>" placeholder="<?= e($siteName) ?>">
                        </div>
                    </div>
                    <div class="sb-form-grid">
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-alt"><?= e(__('studio_business_alternate', 'Other name (optional)')) ?></label>
                            <input type="text" name="biz_alternate" id="seo-biz-alt" maxlength="160" class="form-control" value="<?= e($business['alternate_name']) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-founder"><?= e(__('studio_business_founder', 'Founder (optional)')) ?></label>
                            <input type="text" name="biz_founder" id="seo-biz-founder" maxlength="160" class="form-control" value="<?= e($business['founder']) ?>">
                        </div>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-biz-desc"><?= e(__('studio_business_description', 'What the business does (optional)')) ?></label>
                        <textarea name="biz_description" id="seo-biz-desc" rows="2" maxlength="500" class="form-control"><?= e($business['description']) ?></textarea>
                    </div>
                    <div>
                        <?php
                        $bizLogoUrl = '';
                        if ($business['logo_media_id'] > 0) {
                            $lp = Database::value("SELECT path FROM media_files WHERE tenant_id = ? AND id = ?", [$tenantId, $business['logo_media_id']]);
                            $bizLogoUrl = is_string($lp) && $lp !== '' ? rtrim((string) SLATE_URL, '/') . '/' . ltrim($lp, '/') : '';
                        }
                        ?>
                        <input type="hidden" name="biz_logo_media_id" id="seo_biz_logo_media_id" value="<?= $business['logo_media_id'] > 0 ? (int) $business['logo_media_id'] : '' ?>" data-sb-id-for="seo_biz_logo_url">
                        <?= sb_media_picker_field([
                            'id' => 'seo_biz_logo_url', 'name' => 'biz_logo_url', 'label' => __('studio_business_logo', 'Logo'), 'value' => $bizLogoUrl,
                            'placeholder' => __('studio_business_logo_placeholder', 'Choose from the Media library'),
                            'help' => __('studio_business_logo_help', 'A square or wide logo of at least 112px; Google may show it beside your results.'),
                        ]) ?>
                    </div>
                    <div class="sb-form-grid">
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-phone"><?= e(__('studio_business_telephone', 'Telephone (optional)')) ?></label>
                            <input type="text" name="biz_telephone" id="seo-biz-phone" maxlength="40" class="form-control" value="<?= e($business['telephone']) ?>" placeholder="+32 …">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-email"><?= e(__('studio_business_email', 'Email (optional)')) ?></label>
                            <input type="email" name="biz_email" id="seo-biz-email" maxlength="160" class="form-control" value="<?= e($business['email']) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-vat"><?= e(__('studio_business_vat', 'VAT number (optional)')) ?></label>
                            <input type="text" name="biz_vat_id" id="seo-biz-vat" maxlength="40" class="form-control" value="<?= e($business['vat_id']) ?>" placeholder="BE0123456789">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-street"><?= e(__('studio_business_street', 'Street and number (optional)')) ?></label>
                            <input type="text" name="biz_street" id="seo-biz-street" maxlength="160" class="form-control" value="<?= e($business['street']) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-postal"><?= e(__('studio_business_postal_code', 'Postal code (optional)')) ?></label>
                            <input type="text" name="biz_postal_code" id="seo-biz-postal" maxlength="20" class="form-control" value="<?= e($business['postal_code']) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-city"><?= e(__('studio_business_locality', 'Town or city (optional)')) ?></label>
                            <input type="text" name="biz_locality" id="seo-biz-city" maxlength="100" class="form-control" value="<?= e($business['locality']) ?>">
                        </div>
                        <div class="sb-field">
                            <label class="sb-field__label" for="seo-biz-country"><?= e(__('studio_business_country', 'Country (optional)')) ?></label>
                            <input type="text" name="biz_country" id="seo-biz-country" maxlength="60" class="form-control" value="<?= e($business['country']) ?>" placeholder="BE">
                        </div>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="seo-biz-same"><?= e(__('studio_business_same_as', 'The business elsewhere (optional)')) ?></label>
                        <textarea name="biz_same_as" id="seo-biz-same" rows="3" class="form-control" spellcheck="false" placeholder="https://www.instagram.com/…"><?= e(implode("\n", $business['same_as'])) ?></textarea>
                        <p class="sb-field__hint"><?= e(__('studio_business_same_as_hint', 'One https address per line: its other websites and social profiles.')) ?></p>
                    </div>
                </div>
                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary"><?= sb_svg('save', 14) ?> <?= e(__('studio_business_save', 'Save business details')) ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// The previews follow the title format and description as they are typed.
(function () {
  var title = document.getElementById('seo-input-title'), desc = document.getElementById('seo-input-desc');
  var page = <?= json_encode($sampleTitle, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, site = <?= json_encode($siteName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
  function set(name, text) { document.querySelectorAll('[data-seo-preview="' + name + '"]').forEach(function (el) { el.textContent = text; }); }
  function update() {
    set('title', (title.value.trim() || '%title%').split('%title%').join(page).split('%site_name%').join(site));
    var d = desc.value.trim();
    set('description', d);
    set('card-description', d.length > 110 ? d.slice(0, 107) + '...' : d);
  }
  if (title && desc) { title.addEventListener('input', update); desc.addEventListener('input', update); }
})();
</script>

<?php sb_render_media_picker_modal(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
