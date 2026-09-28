<?php
/**
 * Slate — customer-facing shell header.
 *
 * Renders one of two layouts depending on $customerPageVariant:
 *
 *   'auth' (default for auth pages)
 *     — full-width light canvas, centered card, brand mark + product
 *       name at top. Used by login/register/verify/forgot/reset.
 *
 *   'dashboard'
 *     — topbar with brand + signed-in user + Sign out. Main content
 *       area centered to --content-max. Used by /customer/index.php
 *       and any plugin that adds customer-side pages.
 *
 * Pages set $customerPageVariant before requiring this partial,
 * then must require partials/footer.php at the bottom.
 */

if (!defined('SLATE_ROOT')) exit;

$customerPageVariant = $customerPageVariant ?? 'auth';
$pageTitle           = $pageTitle           ?? 'Account';
$siteName            = Database::setting('site_name') ?: 'Kohevo';
$brandSublabel       = Database::setting('brand_sublabel') ?: __('customer_portal', 'Customer Portal');

// Branding for the two-column 'auth-split' variant (same settings the
// admin login uses — Settings → Branding).
$brandLogoUrls  = slate_logo_urls();
$brandLogoUrl   = $brandLogoUrls['light'];
if ($brandLogoUrl === '' && stripos(trim((string)$siteName), 'Kohevo') === 0) {
    $brandLogoUrl = \Slate\Services\Content\PlatformIdentity::wordmarkUrl();
}
$brandHeroPath  = (string)Database::setting('brand_login_image_path');
$brandHeroUrl   = $brandHeroPath !== '' ? SLATE_URL . '/' . ltrim($brandHeroPath, '/') : '';
$brandTagline   = trim((string)Database::setting('brand_login_tagline'));
$brandAccentRaw = (string)Database::setting('brand_accent_color');
$brandAccent    = preg_match('/^#[0-9a-fA-F]{6}$/', $brandAccentRaw) ? $brandAccentRaw : '#111111';
$brandInitial   = e(mb_strtoupper(mb_substr($siteName, 0, 1)));
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FAFAFA">
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); ?>
    <?php slate_brand_accent_emit(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <style>
    body { background: var(--bg); min-height: 100vh; min-height: 100dvh; }

    /* ── Auth-page shell (centered card) ──────────────────── */
    .auth-shell {
        min-height: 100vh; min-height: 100dvh;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: var(--space-5) var(--space-4);
        gap: var(--space-5);
    }
    .auth-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: var(--text);
    }
    .auth-brand:hover { color: var(--text); text-decoration: none; }
    .auth-brand-mark {
        width: 36px; height: 36px;
        border-radius: var(--radius);
        background: linear-gradient(135deg, var(--accent), var(--accent-deep));
        color: var(--on-accent);
        display: grid;
        place-items: center;
        font-family: var(--font-display);
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -0.04em;
        box-shadow: 0 6px 16px color-mix(in srgb, var(--accent) 30%, transparent);
    }
    .auth-brand-text { line-height: 1.2; }
    .auth-brand-name {
        font-family: var(--font-display);
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    .auth-brand-sub {
        font-size: 11px;
        color: var(--subtle);
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-weight: 600;
        margin-top: 1px;
    }
    .auth-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 28px 28px 24px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 1px 2px rgba(15,17,23,0.04);
    }
    .auth-card h1 {
        font-family: var(--font-display);
        font-size: 22px;
        margin: 0 0 6px;
    }
    .auth-card .auth-card-sub {
        font-size: 13px;
        color: var(--muted);
        margin: 0 0 var(--space-5);
    }
    .auth-footer {
        font-size: 13px;
        color: var(--muted);
        text-align: center;
    }
    .auth-footer a { color: var(--accent); }
    .auth-footer a:hover { color: var(--accent-deep); text-decoration: underline; }
    /* ── Platform signature (Kohevo) — closes the auth-split card (see
       partials/footer.php); same small/muted treatment as the admin login's
       platform signature, kept visually distinct from .auth-footer above it. */
    .auth-platform-signature {
        margin: 18px 0 0;
        padding-top: 14px;
        border-top: 1px solid var(--border, #e5e7eb);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 600;
        color: var(--muted);
    }
    .auth-platform-signature img { display: block; height: 14px; width: auto; }

    /* Customer pages sit on a solid surface where the admin's translucent
       --glass-border is nearly invisible. Use the solid hairline so inputs read
       as real fields (not just an underline on focus). */
    .field input, .field select, .field textarea, .input,
    main.cust-content :is(input, select, textarea) {
        background: var(--surface);
        border: 1px solid var(--border);
    }
    .field input:hover, .field select:hover, .field textarea:hover,
    main.cust-content :is(input, select, textarea):hover { border-color: var(--border-stronger); }

    /* ── Two-column auth shell (hero image + form) ────────── */
    .auth-split {
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        min-height: 100vh; min-height: 100dvh;
    }
    .auth-hero {
        position: relative; overflow: hidden;
        display: flex; flex-direction: column; justify-content: space-between;
        padding: 44px 44px 48px; color: #fff;
        background: linear-gradient(160deg,
            color-mix(in srgb, var(--accent) 92%, #0E1117),
            color-mix(in srgb, var(--accent) 40%, #0E1117) 55%,
            #0E1117 100%);
    }
    <?php if ($brandHeroUrl !== ''): ?>
    .auth-hero {
        background-image: url("<?= e($brandHeroUrl) ?>");
        background-size: cover; background-position: center;
    }
    <?php endif; ?>
    /* Fine dot-grid texture — same device as the admin dashboard hero
       (.dash-hero::before) and the admin login page. */
    .auth-hero::before {
        content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .35;
        background-image: radial-gradient(rgba(255,255,255,0.35) 1px, transparent 1.5px);
        background-size: 22px 22px;
        -webkit-mask-image: linear-gradient(180deg, transparent 0%, #000 62%);
                mask-image: linear-gradient(180deg, transparent 0%, #000 62%);
    }
    .auth-hero::after {
        content: ""; position: absolute; inset: 0; pointer-events: none;
        background:
            radial-gradient(120% 90% at 100% 0%, rgba(255,255,255,0.10), transparent 55%),
            linear-gradient(195deg,
                rgba(6,8,12,0.20) 0%, rgba(6,8,12,0.10) 30%, rgba(6,8,12,0.78) 100%);
    }
    .auth-hero-top, .auth-hero-bottom { position: relative; z-index: 1; }
    .auth-hero-top { display: flex; align-items: center; justify-content: flex-end; gap: 16px; }
    .auth-back {
        display: inline-flex; align-items: center; gap: 8px;
        color: rgba(255,255,255,0.85); text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap;
        transition: color 0.15s ease;
    }
    .auth-back:hover { color: #fff; text-decoration: underline; }
    .auth-hero-tagline {
        margin: 0; max-width: 460px; font-family: var(--font-display);
        font-size: clamp(26px, 3vw, 38px); font-weight: 700; line-height: 1.15; letter-spacing: -0.02em;
        text-shadow: 0 2px 18px rgba(0,0,0,0.35);
    }
    .auth-hero-eyebrow {
        display: inline-flex; align-items: center; gap: 9px;
        font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; text-transform: uppercase;
        color: rgba(255,255,255,0.65); margin: 0 0 14px;
    }
    .auth-hero-eyebrow::before { content: ""; width: 20px; height: 1px; background: rgba(255,255,255,0.55); }
    .auth-hero-rule {
        display: block; width: 56px; height: 3px; border-radius: 2px; margin-top: 20px;
        background: linear-gradient(90deg, rgba(255,255,255,0.9), rgba(255,255,255,0));
    }
    .auth-form-panel {
        position: relative; overflow: hidden;
        background:
            radial-gradient(1100px 620px at 85% -10%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 60%),
            radial-gradient(900px 600px at -10% 110%, color-mix(in srgb, var(--accent) 7%, transparent), transparent 55%),
            var(--bg);
        display: flex; align-items: center; justify-content: center; padding: 40px;
    }
    .auth-form-panel::before {
        content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .6;
        background-image: radial-gradient(color-mix(in srgb, var(--text) 7%, transparent) 1px, transparent 1.5px);
        background-size: 26px 26px;
        -webkit-mask-image: radial-gradient(60% 55% at 50% 42%, #000 0%, transparent 75%);
                mask-image: radial-gradient(60% 55% at 50% 42%, #000 0%, transparent 75%);
    }
    .auth-form-inner {
        position: relative; z-index: 1;
        width: 100%; max-width: 400px;
        background: var(--glass-bg-strong);
        -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(180%);
        backdrop-filter: blur(var(--glass-blur)) saturate(180%);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius-2xl);
        box-shadow: var(--glass-shadow-lg);
        padding: 44px 40px 36px;
    }
    .auth-form-inner .auth-brand { margin-bottom: 24px; }
    .auth-form-inner .auth-card { border: 0; box-shadow: none; padding: 0; max-width: none; background: transparent; }
    .auth-eyebrow {
        display: inline-flex; align-items: center; gap: 8px;
        font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase;
        color: var(--accent-deep); font-weight: 600; margin: 0 0 14px;
    }
    .auth-eyebrow::before { content: ""; width: 18px; height: 1px; background: var(--accent); opacity: .8; }
    .auth-form-inner .field input {
        background: color-mix(in srgb, var(--surface) 68%, transparent);
        border-radius: var(--radius);
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .auth-form-inner .field input:hover { border-color: var(--border-stronger); }
    .auth-form-inner .field input:focus {
        outline: none; border-color: var(--accent); background: var(--surface);
        box-shadow: 0 0 0 4px var(--ring);
    }
    .auth-form-inner .btn-primary {
        background: linear-gradient(135deg, var(--accent), var(--accent-deep));
        border: none;
        box-shadow: 0 10px 26px color-mix(in srgb, var(--accent) 38%, transparent),
                    inset 0 1px 0 rgba(255,255,255,0.25);
        transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }
    .auth-form-inner .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 32px color-mix(in srgb, var(--accent) 42%, transparent),
                    inset 0 1px 0 rgba(255,255,255,0.3);
        filter: brightness(1.03);
    }
    .auth-form-inner .btn-primary:active { transform: translateY(0); }
    @media (max-width: 860px) {
        .auth-split { grid-template-columns: 1fr; }
        .auth-hero { min-height: 220px; padding: 24px; }
        .auth-hero-tagline { font-size: 22px; }
        .auth-form-panel { padding: 32px 22px; }
        .auth-form-inner { padding: 30px 24px 26px; }
    }
    @media (max-width: 520px) {
        .auth-hero-tagline { display: none; }
        .auth-hero { min-height: 150px; }
    }

    /* ── Dashboard shell ──────────────────────────────────── */
    .cust-topbar {
        position: sticky;
        top: 0;
        z-index: 30;
        height: var(--topbar-height);
        background: rgba(245, 244, 241, 0.82);
        backdrop-filter: saturate(180%) blur(12px);
        -webkit-backdrop-filter: saturate(180%) blur(12px);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 20px;
    }
    .cust-topbar .auth-brand-name { font-size: 15px; }
    .cust-topbar .auth-brand-sub  { display: none; }
    .cust-topbar .auth-brand-mark { width: 28px; height: 28px; font-size: 14px; }
    .cust-topbar-spacer { flex: 1; }
    .cust-topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: var(--text-2);
    }
    .cust-topbar-user .topbar-avatar { width: 28px; height: 28px; }
    .cust-topbar-user a { color: var(--muted); }
    .cust-topbar-user a:hover { color: var(--text); text-decoration: underline; }

    /* ── Platform signature (Kohevo) — small, muted, after the tenant's own
       brand/user cluster so it reads as platform attribution, not a second logo. */
    .cust-topbar-signature {
        display: flex;
        align-items: center;
        margin-left: 12px;
        padding-left: 12px;
        border-left: 1px solid var(--border);
    }
    .cust-topbar-signature img { display: block; height: 14px; width: auto; }

    main.cust-content {
        max-width: 900px;
        width: 100%;
        margin: 0 auto;
        padding: 24px 20px 56px;
    }

    @media (max-width: 480px) {
        .cust-topbar-user-name { display: none; }
        .cust-topbar { padding: 0 14px; }
        main.cust-content { padding: 16px 14px 40px; }
        .auth-card { padding: 22px 20px 20px; }
    }
    </style>
    <?php
    // Plugin-injected head content. Coaching uses this to load customer-shell.css
    // (premium overlay) and customer.css (bento). Same pattern as admin_head.
    if (class_exists('Hook')) Hook::doAction('customer_head');
    if (class_exists('PluginLoader')) echo PluginLoader::renderQueuedStyles();
    ?>
</head>
<body>
    <a class="skip-link" href="#cust-content"><?= __('skip_to_content', 'Skip to main content') ?></a>

<?php if ($customerPageVariant === 'dashboard'):
    $cust = Auth::customer();
?>
    <header class="cust-topbar">
        <a href="<?= e(SLATE_URL) ?>/customer/" class="auth-brand">
            <?php if ($brandLogoUrl !== ''): ?>
                <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>" style="max-height:32px;max-width:160px">
            <?php else: ?>
                <span class="auth-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
                <span class="auth-brand-text">
                    <span class="auth-brand-name"><?= e($siteName) ?></span>
                    <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
                </span>
            <?php endif; ?>
        </a>
        <div class="cust-topbar-spacer"></div>
        <?php if ($cust): ?>
            <div class="cust-topbar-user">
                <span class="topbar-avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($cust['name'] ?? $cust['email'] ?? 'A', 0, 1))) ?>
                </span>
                <span class="cust-topbar-user-name"><?= e($cust['name'] ?? $cust['email']) ?></span>
                <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"><?= __('sign_out', 'Sign out') ?></a>
            </div>
        <?php endif; ?>
        <?php /* Kohevo platform signature — dashboard variant only (never auth-split,
                 that's Phase 3/login work). Routed entirely through PlatformSignature/
                 PlatformIdentity, never a literal path or name here. Compact (icon
                 only, alt text still carries the name) rather than standard — the
                 topbar is a single horizontal row already carrying tenant name +
                 sublabel and, on wider screens, the signed-in user block, so a
                 second text label would compete rather than stay subordinate. The
                 mark's dark ink sits on this topbar's light background
                 (rgba(245,244,241,.82)) with no legibility problem, unlike the
                 admin sidebar, so no tile/variant treatment is needed here. */ ?>
        <div class="cust-topbar-signature">
            <?= \Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_COMPACT) ?>
        </div>
    </header>
    <main class="cust-content" id="cust-content">

<?php elseif ($customerPageVariant === 'auth-split'): /* two-column hero + form */ ?>

    <div class="auth-split" id="cust-content">
        <aside class="auth-hero" aria-hidden="true">
            <div class="auth-hero-top">
                <?php
                // The brand mark is shown once, in the form panel below —
                // this hero panel is decorative, so it only needs the
                // "back to website" link, not a second logo.
                ?>
                <a href="<?= e(SLATE_URL) ?>/" class="auth-back">
                    <?= __('back_to_website', 'Back to website') ?>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                </a>
            </div>
            <div class="auth-hero-bottom">
                <span class="auth-hero-eyebrow"><?= e($brandSublabel) ?></span>
                <?php if ($brandTagline !== ''): ?>
                    <p class="auth-hero-tagline"><?= nl2br(e($brandTagline)) ?></p>
                <?php endif; ?>
                <span class="auth-hero-rule" aria-hidden="true"></span>
            </div>
        </aside>
        <main class="auth-form-panel">
            <div class="auth-form-inner">
                <a href="<?= e(SLATE_URL) ?>/customer/" class="auth-brand">
                    <?php /* Always the light --bg panel -- no dark-mode CSS exists for it, so always the light logo. */ ?>
                    <?php if ($brandLogoUrl !== ''): ?>
                        <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>" style="max-height:40px;max-width:180px">
                    <?php else: ?>
                        <span class="auth-brand-mark" aria-hidden="true"><?= $brandInitial ?></span>
                        <span class="auth-brand-text">
                            <span class="auth-brand-name"><?= e($siteName) ?></span>
                            <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
                        </span>
                    <?php endif; ?>
                </a>

<?php else: /* 'auth' variant */ ?>

    <div class="auth-shell" id="cust-content">
        <a href="<?= e(SLATE_URL) ?>/" class="auth-brand">
            <?php if ($brandLogoUrl !== ''): ?>
                <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>" style="max-height:40px;max-width:180px">
            <?php else: ?>
                <span class="auth-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
                <span class="auth-brand-text">
                    <span class="auth-brand-name"><?= e($siteName) ?></span>
                    <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
                </span>
            <?php endif; ?>
        </a>

<?php endif; ?>
