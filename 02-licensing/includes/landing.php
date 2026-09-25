<?php
/**
 * Slate — public landing page renderer.
 *
 * Single source of truth for the "choose your vessel" entry page, shared by
 * the site root (index.php) and the forms index (/forms/). All content is
 * driven by the Landing settings tab (admin/settings.php → Landing), so it is
 * fully editable from the backend with no code change:
 *
 *   landing_eyebrow, landing_title, landing_intro, landing_footer,
 *   landing_website_url, landing_website_label, landing_forms_json,
 *   landing_booking_enabled, landing_booking_label, landing_booking_blurb,
 *   landing_booking_button, landing_booking_icon,
 *   landing_show_customer_login, landing_show_admin_login
 *
 * Layout is a two-panel split — a dark brand/hero panel and a light entry
 * panel — matching admin/login.php and customer/login.php so the "choose
 * where to go" screen reads as the same product as the screens it leads to.
 * The entry panel lists tiles in a fixed order: the booking catalog (when
 * the Booking plugin is active and enabled), the customer login, the admin
 * login, then any featured forms — each independently toggleable from the
 * Landing settings tab and on by default. The brand logo renders exactly
 * once (in the entry panel), not repeated on the hero panel — see the same
 * fix applied to admin/login.php.
 */

if (!function_exists('slate_render_landing')) {

/** Inline SVG icon for a tile's icon key (stroke = currentColor). */
function slate_landing_icon(string $kind): string {
    switch ($kind) {
        case 'user':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/></svg>';
        case 'shield':
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<path d="M12 3l7 3v6c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V6z"/><path d="m9.5 12 2 2 3.5-3.5"/></svg>';
        case 'sailboat':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<path d="M32 6v34"/>'
                 . '<path d="M32 10c10 5 15 14 16 26H32z" fill="currentColor" fill-opacity=".14"/>'
                 . '<path d="M30 12C22 17 18 26 16 36h14z" fill="currentColor" fill-opacity=".08"/>'
                 . '<path d="M10 44h44l-7 11a6 6 0 0 1-5 3H22a6 6 0 0 1-5-3z" fill="currentColor" fill-opacity=".16"/>'
                 . '<path d="M6 50c4 2 6 2 10 0s6-2 10 0 6 2 10 0 6-2 10 0 6 2 10 0"/></svg>';
        case 'boat':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<path d="M10 40h44l-6 11a6 6 0 0 1-5 3H21a6 6 0 0 1-5-3z" fill="currentColor" fill-opacity=".16"/>'
                 . '<path d="M16 40V22l28 18z" fill="currentColor" fill-opacity=".08"/>'
                 . '<path d="M4 54c4 2 6 2 10 0s6-2 10 0 6 2 10 0 6-2 10 0 6 2 10 0"/></svg>';
        case 'anchor':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<circle cx="32" cy="14" r="5"/><path d="M32 19v33"/><path d="M20 30h24"/>'
                 . '<path d="M12 36c0 11 9 18 20 18s20-7 20-18"/><path d="M12 36l-5 4M12 36l6 2M52 36l5 4M52 36l-6 2"/></svg>';
        case 'compass':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<circle cx="32" cy="32" r="24"/><path d="M42 22 36 36 22 42 28 28z" fill="currentColor" fill-opacity=".14"/></svg>';
        case 'star':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<path d="M32 8l7.6 15.4L56 25.8 44 37.5l2.8 16.5L32 46.2 17.2 54l2.8-16.5L8 25.8l16.4-2.4z" fill="currentColor" fill-opacity=".12"/></svg>';
        case 'clipboard':
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<rect x="16" y="10" width="32" height="44" rx="4" fill="currentColor" fill-opacity=".08"/>'
                 . '<rect x="24" y="6" width="16" height="8" rx="2"/><path d="M24 28h16M24 36h16M24 44h10"/></svg>';
        case 'powerboat':
        default:
            return '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
                 . '<path d="M8 34h40l8-12-30 4z" fill="currentColor" fill-opacity=".10"/>'
                 . '<path d="M6 40h52l-6 10a6 6 0 0 1-5 3H17a6 6 0 0 1-5-3z" fill="currentColor" fill-opacity=".18"/>'
                 . '<path d="M30 22v12M30 22l18-2"/>'
                 . '<path d="M4 52c4 2 6 2 10 0s6-2 10 0 6 2 10 0 6-2 10 0 6 2 10 0"/></svg>';
    }
}

/** Render and echo the full landing page document. */
function slate_render_landing(): void {
    $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $siteName = Database::setting('site_name')     ?: 'Kohevo';
    $bizName  = Database::setting('business_name')  ?: $siteName;
    $accent   = preg_match('/^#[0-9a-fA-F]{6}$/', (string)Database::setting('brand_accent_color'))
                ? (string)Database::setting('brand_accent_color') : '#01aced';
    $logoUrls = slate_logo_urls();
    $logoUrl  = $logoUrls['light'];
    $heroPath = (string)Database::setting('brand_login_image_path');
    $heroUrl  = $heroPath !== '' ? SLATE_URL . '/' . ltrim($heroPath, '/') : '';

    // Editable copy (fall back to sensible defaults when unset). The eyebrow
    // and intro live on the hero (left) panel; the entry (right) panel's
    // "Welcome" heading and subtitle are fixed chrome, same as the login
    // pages' own static "Welcome back" heading.
    $eyebrow = (string)Database::setting('landing_eyebrow');
    $title   = (string)Database::setting('landing_title')   ?: $bizName;
    $intro   = (string)Database::setting('landing_intro')   ?:
        'Choose an option below to get started.';
    $footer  = (string)Database::setting('landing_footer')  ?:
        ('© ' . date('Y') . ' ' . $bizName . '. All rights reserved.');

    // Back-to-website link (main domain) — hidden when blank.
    $siteUrl   = (string)Database::setting('landing_website_url');
    $siteLabel = (string)Database::setting('landing_website_label') ?: 'Back to website';

    // Contact details from the business profile (Settings → Profile). Each is
    // shown only when set; phone/email become tel:/mailto: links.
    $cPhone = trim((string)Database::setting('business_phone'));
    $cEmail = trim((string)Database::setting('business_email'));
    $cAddr  = trim((string)Database::setting('business_address'));
    $phoneDigits   = preg_replace('/\D/', '', $cPhone);
    $phoneDialable = $cPhone !== '' && !preg_match('/[A-Za-z]/', $cPhone)
                     && strlen($phoneDigits) >= 7 && strlen($phoneDigits) <= 15;
    $telHref = $phoneDialable ? 'tel:' . preg_replace('/[^0-9+]/', '', $cPhone) : '';
    $hasContact = $cPhone !== '' || $cEmail !== '' || $cAddr !== '';

    // ── Entry-point tiles, in a fixed order, each on by default ──
    // Book online (only meaningful with the Booking plugin active).
    $tiles = [];
    if (class_exists('PluginLoader') && PluginLoader::isActive('booking')
        && Database::setting('landing_booking_enabled') !== '0') {
        $tiles[] = [
            'label'  => (string)Database::setting('landing_booking_label') ?: __('landing_book_now', 'Book now'),
            'blurb'  => (string)Database::setting('landing_booking_blurb') ?: __('landing_book_now_blurb', 'Browse services and pick a time.'),
            'icon'   => (string)(Database::setting('landing_booking_icon') ?: 'clipboard'),
            'button' => (string)Database::setting('landing_booking_button') ?: __('view_availability', 'View availability'),
            'url'    => SLATE_URL . '/book',
        ];
    }
    if (Database::setting('landing_show_customer_login') !== '0') {
        $tiles[] = [
            'label'  => __('landing_customer_login', 'Customer login'),
            'blurb'  => __('landing_customer_login_blurb', 'Manage your bookings and account.'),
            'icon'   => 'user',
            'button' => __('sign_in', 'Sign in'),
            'url'    => SLATE_URL . '/customer/login.php',
        ];
    }
    if (Database::setting('landing_show_admin_login') !== '0') {
        $tiles[] = [
            'label'  => __('landing_admin_login', 'Admin login'),
            'blurb'  => __('landing_admin_login_blurb', 'Staff dashboard and settings.'),
            'icon'   => 'shield',
            'button' => __('sign_in', 'Sign in'),
            'url'    => SLATE_URL . '/admin/login.php',
        ];
    }

    // Featured forms, appended after the fixed tiles above.
    $configured = (array)json_decode((string)Database::setting('landing_forms_json'), true);
    if (!$configured && !$tiles) {
        // Only fall back to the sample forms when there is truly nothing
        // else to show, so a fresh install still looks complete.
        $configured = [
            ['slug' => 'powerboat-survey-order', 'label' => 'Powerboat', 'icon' => 'powerboat',
             'blurb' => 'Motor yachts, cruisers, and powered vessels — engines, hull, and systems.'],
            ['slug' => 'sailboat-survey-order',  'label' => 'Sailboat',  'icon' => 'sailboat',
             'blurb' => 'Sailing yachts and keelboats — rigging, sails, hull, and systems.'],
        ];
    }
    if (class_exists('FormsAPI')) {
        foreach ($configured as $c) {
            $form = isset($c['id']) ? FormsAPI::getFormById((int)$c['id'])
                  : (isset($c['slug']) ? FormsAPI::getForm((string)$c['slug']) : null);
            if (!$form || ($form['status'] ?? '') !== 'published') continue;
            $tiles[] = [
                'label'  => ($c['label'] ?? '') !== '' ? $c['label'] : $form['title'],
                'blurb'  => (string)($c['blurb'] ?? ''),
                'icon'   => (string)($c['icon'] ?? 'clipboard'),
                'button' => (string)($c['button'] ?? '') ?: __('landing_start', 'Start'),
                'url'    => SLATE_URL . '/forms/' . $form['slug'],
            ];
        }
    }
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($eyebrow !== '' ? $title . ' — ' . $eyebrow : $title) ?></title>
<link rel="icon" href="<?= $e($logoUrl !== '' ? $logoUrl : slate_default_favicon_url()) ?>">
<style>
    :root { --accent: <?= $e($accent) ?>; }
    * { box-sizing: border-box; }
    html, body { margin: 0; height: 100%; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        min-height: 100vh; min-height: 100dvh;
    }
    .split { display: grid; grid-template-columns: 1fr 1fr; min-height: 100vh; min-height: 100dvh; }
    @media (max-width: 860px) { .split { grid-template-columns: 1fr; } }

    /* ── Hero (left) panel ── */
    .hero {
        position: relative; color: #fff; padding: clamp(20px, 3vw, 40px);
        display: flex; flex-direction: column; justify-content: space-between;
        min-height: 260px; overflow: hidden;
        <?php if ($heroUrl !== ''): ?>background: url("<?= $e($heroUrl) ?>") center/cover no-repeat;
        <?php else: ?>background: radial-gradient(120% 80% at 50% -10%, color-mix(in srgb, var(--accent) 45%, #0b1c2c), #0b1c2c);<?php endif; ?>
    }
    .hero::before { content: ""; position: absolute; inset: 0;
        background: linear-gradient(195deg, rgba(6,8,12,.20) 0%, rgba(6,8,12,.10) 30%, rgba(6,8,12,.78) 100%);
        pointer-events: none;
    }
    .hero-top, .hero-bottom { position: relative; z-index: 1; }
    .back {
        display: inline-flex; align-items: center; gap: 8px; text-decoration: none;
        font-size: 13.5px; font-weight: 600; color: rgba(255,255,255,.92);
        padding: 9px 16px; border-radius: 999px;
        background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.22);
        -webkit-backdrop-filter: blur(10px) saturate(180%); backdrop-filter: blur(10px) saturate(180%);
        transition: background .15s ease;
    }
    .back:hover { background: rgba(255,255,255,.24); }
    .back svg { width: 15px; height: 15px; }
    .hero-eyebrow {
        display: inline-block; font-size: 11.5px; letter-spacing: .12em; text-transform: uppercase;
        font-weight: 700; color: rgba(255,255,255,.7); margin-bottom: 10px;
    }
    .hero-tagline { margin: 0; max-width: 46ch; font-size: clamp(22px, 3.4vw, 34px); font-weight: 700;
        line-height: 1.2; letter-spacing: -.01em; }

    /* ── Entry (right) panel ── */
    .panel { background: #fff; color: #16181c; display: flex; flex-direction: column;
        justify-content: center; padding: clamp(28px, 5vw, 56px); }
    .brand-row { display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
    .brand-row img { height: 30px; width: auto; }
    .brand-row-name { font-size: 14px; font-weight: 700; color: #16181c; }
    h1 { font-size: clamp(24px, 3.4vw, 30px); font-weight: 800; margin: 14px 0 4px; letter-spacing: -.01em; }
    .sub { font-size: 13.5px; color: #6b7280; margin: 0 0 24px; }
    .tiles { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
    .tile {
        display: flex; align-items: center; gap: 12px; padding: 13px 14px;
        border: 1px solid #e5e7eb; border-radius: 12px; text-decoration: none; color: inherit;
        transition: border-color .15s ease, background .15s ease;
    }
    .tile:hover { border-color: color-mix(in srgb, var(--accent) 55%, #e5e7eb); background: #fafafa; }
    .tile-ico { flex: none; width: 22px; height: 22px; color: var(--accent); }
    .tile-ico svg { width: 100%; height: 100%; }
    .tile-body { flex: 1; min-width: 0; }
    .tile-label { font-size: 14px; font-weight: 700; margin: 0; }
    .tile-blurb { font-size: 12.5px; color: #6b7280; margin: 2px 0 0; }
    .tile-cta { flex: none; font-size: 12.5px; font-weight: 700; color: var(--accent); white-space: nowrap; }
    .empty { border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; font-size: 13.5px; color: #6b7280; margin-bottom: 24px; }

    .contact { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-bottom: 8px; }
    .contact > * { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: #6b7280; text-decoration: none; }
    .contact a:hover { color: #16181c; }
    .contact svg { width: 13px; height: 13px; flex: none; color: var(--accent); }
    .foot-copy { font-size: 11.5px; color: #9ca3af; }
    .foot-signature { margin-top: 6px; display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; color: #9ca3af; }
    .foot-signature img { display: block; height: 13px; width: auto; }
</style>
</head>
<body>
<div class="split">

    <aside class="hero" aria-hidden="true">
        <div class="hero-top">
            <?php if ($siteUrl !== ''): ?>
                <a class="back" href="<?= $e($siteUrl) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                    <?= $e($siteLabel) ?>
                </a>
            <?php endif; ?>
        </div>
        <div class="hero-bottom">
            <?php if ($eyebrow !== ''): ?><span class="hero-eyebrow"><?= $e($eyebrow) ?></span><?php endif; ?>
            <p class="hero-tagline"><?= $e($intro) ?></p>
        </div>
    </aside>

    <main class="panel">
        <div class="brand-row">
            <?php if ($logoUrl !== ''): ?><img src="<?= $e($logoUrl) ?>" alt="<?= $e($title) ?>">
            <?php else: ?><span class="brand-row-name"><?= $e($title) ?></span><?php endif; ?>
        </div>
        <h1><?= __('landing_welcome', 'Welcome') ?></h1>
        <p class="sub"><?= __('landing_choose_hint', 'Choose where you’d like to go.') ?></p>

        <?php if ($tiles): ?>
            <div class="tiles">
                <?php foreach ($tiles as $t): ?>
                    <a class="tile" href="<?= $e($t['url']) ?>">
                        <span class="tile-ico"><?= slate_landing_icon($t['icon']) ?></span>
                        <span class="tile-body">
                            <p class="tile-label"><?= $e($t['label']) ?></p>
                            <?php if ($t['blurb'] !== ''): ?><p class="tile-blurb"><?= $e($t['blurb']) ?></p><?php endif; ?>
                        </span>
                        <span class="tile-cta"><?= $e($t['button']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty"><?= __('landing_nothing_yet', 'Nothing is featured here yet. Check back shortly.') ?></div>
        <?php endif; ?>

        <?php if ($hasContact): ?>
        <div class="contact">
            <?php if ($cPhone !== ''):
                $phoneTag = $phoneDialable ? 'a' : 'span';
                $phoneAttr = $phoneDialable ? ' href="' . $e($telHref) . '" aria-label="Call us"' : '';
            ?>
                <<?= $phoneTag ?><?= $phoneAttr ?>>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    <?= $e($cPhone) ?>
                </<?= $phoneTag ?>>
            <?php endif; ?>
            <?php if ($cEmail !== ''): ?>
                <a href="mailto:<?= $e($cEmail) ?>" aria-label="Email us">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/></svg>
                    <?= $e($cEmail) ?>
                </a>
            <?php endif; ?>
            <?php if ($cAddr !== ''): ?>
                <span class="addr">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span><?= $e(preg_replace('/\s*\R\s*/u', ' · ', $cAddr)) ?></span>
                </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="foot-copy"><?= $e($footer) ?></div>
        <?php
        // Kohevo platform signature — secondary to, and separate from, the
        // business's own footer copy directly above (that credit is
        // unchanged, not merged with this). Routed entirely through
        // PlatformSignature/PlatformIdentity, same pattern and MODE_SIGNATURE
        // as admin/login.php; this file never names a platform asset path or
        // the literal platform name. Renders '' (and this wrapper is skipped)
        // when the current tenant is licensed to white-label.
        $platformSignature = \Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_SIGNATURE);
        if ($platformSignature !== ''): ?>
        <div class="foot-signature"><?= $platformSignature ?></div>
        <?php endif; ?>
    </main>

</div>
</body>
</html>
    <?php
}

}
