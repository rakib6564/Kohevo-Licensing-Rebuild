<?php
/**
 * Membership — public marketing / join landing page.  URL: /membership
 *
 * Public (no login). Modern, light, brand-aligned: pulls the site logo +
 * accent colour from the content-builder branding settings so it matches the
 * host site exactly (falls back to the core theme accent when absent).
 * Bilingual via ?lang=fr|en. Mobile-first responsive.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('membership');
ModuleGuard::requirePublic('membership');
require_once dirname(__DIR__) . '/MembershipAPI.php';
MembershipAPI::ensureSchema();

/** Inline SVG icon helper (stroke = currentColor). Defined before first use. */
if (!function_exists('mlp_icon')) {
    function mlp_icon(string $name, int $size = 20): string {
        $p = [
            'check'    => '<polyline points="20 6 9 17 4 12"/>',
            'card'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>',
            'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
            'qr'       => '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="21"/><line x1="18" y1="14" x2="21" y2="14"/><line x1="21" y1="18" x2="21" y2="21"/>',
            'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'arrow'    => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'spark'    => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M5.6 18.4l2.8-2.8M15.6 8.4l2.8-2.8"/>',
        ];
        $body = $p[$name] ?? $p['check'];
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
    }
}

/** Darken a hex colour by $f (0–1) for gradients / hovers. */
if (!function_exists('mlp_darken')) {
    function mlp_darken(string $hex, float $f = 0.14): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        if (strlen($hex) < 6) return '#' . $hex;
        $r = (int) max(0, hexdec(substr($hex, 0, 2)) * (1 - $f));
        $g = (int) max(0, hexdec(substr($hex, 2, 2)) * (1 - $f));
        $b = (int) max(0, hexdec(substr($hex, 4, 2)) * (1 - $f));
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

// ── Brand (from content-builder site settings, with safe fallbacks) ───────
$cust     = Auth::customer();                 // may be null (public page)
$plans    = MembershipAPI::plans(true);
$types    = MembershipAPI::planTypes();
$insFee   = MembershipAPI::insuranceFeeCents();
$siteName = Database::setting('site_name') ?: 'Kohevo';
$locale   = I18n::currentLocale();
$self     = SLATE_URL . '/membership';

// ContentBuilderAPI (the original source of these settings) is archived out
// of this build, so class_exists() below was always false and this page's
// logo/accent/tagline silently ignored the tenant's real Settings -> Branding
// values. Reads the same brand_* settings every other page uses instead.
$logoUrls = function_exists('slate_logo_urls') ? slate_logo_urls() : ['light' => '', 'dark' => ''];
$logoUrl  = $logoUrls['light'];
$logoDarkUrl = $logoUrls['dark'];
if ($logoUrl === '' && stripos(trim((string) $siteName), 'Kohevo') === 0 && class_exists(\Slate\Services\Content\PlatformIdentity::class)) {
    $logoUrl = \Slate\Services\Content\PlatformIdentity::wordmarkUrl();
}
$accent  = trim((string) Database::setting('brand_accent_color'));
$tagline = (string) (Database::setting('site_tagline') ?: '');
if ($tagline === '') $tagline = trim((string) Database::setting('brand_login_tagline'));
if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#111111';
$accentDark = mlp_darken($accent, 0.16);

$featuredId = 0;
foreach ($plans as $p) { if ($p['plan_type'] === 'membership') { $featuredId = (int)$p['id']; break; } }
if (!$featuredId && $plans) $featuredId = (int)$plans[0]['id'];

$registerUrl = SLATE_URL . '/customer/register.php?next=' . rawurlencode('/member?view=plans');
$loginUrl    = SLATE_URL . '/customer/login.php?next='    . rawurlencode('/member?view=plans');

$planFeatures = function (array $p) use ($types): array {
    $f = [];
    $f[] = (int)$p['duration_days'] . ' ' . __('membership_lp_day_access', 'days of access');
    $f[] = $types[$p['plan_type']] ?? $p['plan_type'];
    if ((int)$p['grace_days'] > 0) $f[] = (int)$p['grace_days'] . ' ' . __('membership_lp_grace', 'day grace period');
    return $f;
};

/** Render the brand lockup (logo image, or letter mark fallback). */
// Always the light --m-bg page background -- no dark-mode CSS exists for it,
// so always the light logo variant.
$brandMark = function (string $cls = 'mlp-brand') use ($logoUrl, $siteName): string {
    $inner = $logoUrl !== ''
        ? '<img class="mlp-logo" src="' . e($logoUrl) . '" alt="' . e($siteName) . '">'
        : '<span class="mlp-logo-mark">' . e(mb_strtoupper(mb_substr($siteName, 0, 1))) . '</span><span class="mlp-logo-name">' . e($siteName) . '</span>';
    return '<a href="' . e(SLATE_URL) . '/" class="' . $cls . '">' . $inner . '</a>';
};
?>
<!DOCTYPE html>
<html lang="<?= e($locale) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($accent) ?>">
    <title><?= e(__('membership_lp_title', 'Membership')) ?> — <?= e($siteName) ?></title>
    <?php if (function_exists('slate_favicon_url')): ?>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <?php endif; ?>
    <meta name="description" content="<?= e(__('membership_lp_meta', 'Become a member — choose a plan, manage it online, and book your sessions.')) ?>">
    <?php slate_ui_emit_css(); ?>
    <style>
    /* ═══ Membership landing — brand-aligned, light, responsive ═══ */
    :root{
        --accent: <?= $accent ?>;
        --accent-deep: <?= $accentDark ?>;
        --on-accent:#ffffff;
        --accent-soft: color-mix(in srgb, <?= $accent ?> 9%, #ffffff);
        --accent-ring: color-mix(in srgb, <?= $accent ?> 22%, transparent);
        --m-ink:#15181E; --m-ink-2:#3C4250; --m-muted:#6B7280;
        --m-bg:#FBFAF9; --m-surface:#FFFFFF; --m-line:#ECE9E6;
        --m-maxw:1140px;
    }
    *{box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{background:var(--m-bg);color:var(--m-ink);margin:0;font-family:var(--font-sans);-webkit-font-smoothing:antialiased;}
    img{max-width:100%;display:block;}
    a{color:inherit;text-decoration:none;}
    .mlp-wrap{max-width:var(--m-maxw);margin:0 auto;padding:0 clamp(16px,4vw,28px);}

    /* Buttons */
    .mlp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:600;font-size:15px;line-height:1;padding:12px 20px;border-radius:12px;border:1.5px solid transparent;cursor:pointer;transition:transform .12s,background .15s,border-color .15s,box-shadow .15s;min-height:46px;white-space:nowrap;}
    .mlp-btn:active{transform:translateY(1px);}
    .mlp-btn-primary{background:var(--accent);color:var(--on-accent);box-shadow:0 6px 16px var(--accent-ring);}
    .mlp-btn-primary:hover{background:var(--accent-deep);}
    .mlp-btn-ghost{background:#fff;color:var(--m-ink);border-color:var(--m-line);}
    .mlp-btn-ghost:hover{border-color:var(--accent);color:var(--accent);}
    .mlp-btn-lg{padding:15px 28px;font-size:16px;}
    .mlp-btn-block{width:100%;}

    /* Header */
    .mlp-header{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--m-bg) 86%,transparent);backdrop-filter:saturate(180%) blur(14px);-webkit-backdrop-filter:saturate(180%) blur(14px);border-bottom:1px solid var(--m-line);}
    .mlp-header-in{display:flex;align-items:center;gap:14px;min-height:68px;}
    .mlp-brand{display:flex;align-items:center;gap:10px;}
    .mlp-logo{height:38px;width:auto;object-fit:contain;}
    .mlp-logo-mark{width:36px;height:36px;border-radius:10px;background:var(--accent);color:#fff;display:grid;place-items:center;font-weight:800;}
    .mlp-logo-name{font-weight:800;font-size:18px;letter-spacing:-.02em;color:var(--m-ink);}
    .mlp-nav{margin-left:auto;display:flex;align-items:center;gap:10px;}
    .mlp-lang{display:flex;gap:2px;border:1px solid var(--m-line);border-radius:999px;padding:3px;background:#fff;}
    .mlp-lang a{font-size:12px;font-weight:700;padding:5px 11px;border-radius:999px;color:var(--m-muted);}
    .mlp-lang a.on{background:var(--m-ink);color:#fff;}

    /* Hero */
    .mlp-hero{position:relative;overflow:hidden;}
    .mlp-hero::before{content:"";position:absolute;inset:0;background:
        radial-gradient(60% 80% at 85% -10%, var(--accent-soft), transparent 70%),
        radial-gradient(50% 60% at 0% 110%, var(--accent-soft), transparent 70%);pointer-events:none;}
    .mlp-hero-in{position:relative;padding:clamp(48px,8vw,96px) 0 clamp(44px,7vw,84px);max-width:760px;}
    .mlp-eyebrow{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:700;letter-spacing:.02em;color:var(--accent);background:var(--accent-soft);border:1px solid var(--accent-ring);padding:7px 14px;border-radius:999px;margin-bottom:22px;}
    .mlp-hero h1{font-size:clamp(33px,6vw,58px);line-height:1.04;letter-spacing:-.035em;margin:0 0 20px;font-weight:800;color:var(--m-ink);}
    .mlp-hero h1 .hl{color:var(--accent);}
    .mlp-hero-sub{font-size:clamp(16px,2.4vw,20px);line-height:1.55;color:var(--m-ink-2);margin:0 0 32px;max-width:580px;}
    .mlp-hero-cta{display:flex;gap:12px;flex-wrap:wrap;}
    .mlp-trust{display:flex;gap:22px;flex-wrap:wrap;margin-top:36px;}
    .mlp-trust div{display:flex;align-items:center;gap:8px;font-size:14px;font-weight:500;color:var(--m-ink-2);}
    .mlp-trust svg{color:var(--accent);flex:none;}

    /* Section scaffolding */
    .mlp-sec{padding:clamp(52px,8vw,88px) 0;}
    .mlp-sec--tint{background:var(--m-surface);border-block:1px solid var(--m-line);}
    .mlp-head{text-align:center;max-width:640px;margin:0 auto clamp(36px,5vw,52px);}
    .mlp-head h2{font-size:clamp(26px,4vw,40px);letter-spacing:-.025em;margin:0 0 14px;font-weight:800;line-height:1.1;}
    .mlp-head p{color:var(--m-muted);font-size:clamp(15px,2vw,18px);line-height:1.6;margin:0;}

    /* Benefits */
    .mlp-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
    .mlp-feature{background:var(--m-surface);border:1px solid var(--m-line);border-radius:16px;padding:26px;transition:border-color .15s,box-shadow .15s,transform .15s;}
    .mlp-feature:hover{border-color:var(--accent-ring);box-shadow:0 10px 30px rgba(20,24,30,.06);transform:translateY(-3px);}
    .mlp-feature-ic{width:48px;height:48px;border-radius:13px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;margin-bottom:18px;}
    .mlp-feature h3{font-size:17px;margin:0 0 8px;letter-spacing:-.01em;}
    .mlp-feature p{color:var(--m-muted);font-size:14.5px;line-height:1.6;margin:0;}

    /* Plans */
    .mlp-plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:22px;max-width:980px;margin:0 auto;align-items:stretch;}
    .mlp-plan{position:relative;background:var(--m-surface);border:1.5px solid var(--m-line);border-radius:20px;padding:30px;display:flex;flex-direction:column;}
    .mlp-plan.feat{border-color:var(--accent);box-shadow:0 18px 44px var(--accent-ring);}
    .mlp-plan-badge{position:absolute;top:-13px;left:50%;transform:translateX(-50%);background:var(--accent);color:#fff;font-size:12px;font-weight:700;letter-spacing:.02em;padding:6px 15px;border-radius:999px;white-space:nowrap;box-shadow:0 6px 14px var(--accent-ring);}
    .mlp-plan-type{font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--accent);margin-bottom:12px;}
    .mlp-plan h3{font-size:21px;margin:0 0 6px;letter-spacing:-.02em;}
    .mlp-plan-desc{color:var(--m-muted);font-size:14px;line-height:1.55;margin:0 0 18px;}
    .mlp-price{display:flex;align-items:baseline;gap:7px;margin-bottom:22px;}
    .mlp-price b{font-size:clamp(32px,5vw,40px);font-weight:800;letter-spacing:-.03em;color:var(--m-ink);}
    .mlp-price span{color:var(--m-muted);font-size:14px;}
    .mlp-plan ul{list-style:none;padding:0;margin:0 0 22px;display:flex;flex-direction:column;gap:12px;}
    .mlp-plan li{display:flex;gap:10px;align-items:flex-start;font-size:14.5px;color:var(--m-ink-2);}
    .mlp-plan li svg{color:var(--accent);flex:none;margin-top:1px;}
    .mlp-plan-foot{margin-top:auto;}
    .mlp-ins{display:flex;gap:9px;align-items:center;font-size:13.5px;color:var(--m-ink-2);background:var(--accent-soft);border:1px solid var(--accent-ring);border-radius:10px;padding:10px 12px;margin-bottom:12px;cursor:pointer;}
    .mlp-ins input{accent-color:var(--accent);width:17px;height:17px;flex:none;}
    .mlp-ins-note{font-size:13px;color:var(--m-muted);margin:0 0 12px;}
    .mlp-signin-row{text-align:center;color:var(--m-muted);font-size:14.5px;margin-top:26px;}
    .mlp-signin-row a{color:var(--accent);font-weight:700;}

    /* Steps */
    .mlp-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:26px;max-width:900px;margin:0 auto;}
    .mlp-step-n{width:42px;height:42px;border-radius:50%;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-weight:800;font-size:17px;margin-bottom:16px;border:1px solid var(--accent-ring);}
    .mlp-step h3{font-size:17.5px;margin:0 0 7px;}
    .mlp-step p{color:var(--m-muted);font-size:14.5px;line-height:1.6;margin:0;}

    /* FAQ */
    .mlp-faq{max-width:760px;margin:0 auto;}
    .mlp-faq details{border:1px solid var(--m-line);border-radius:14px;background:var(--m-surface);padding:0 22px;margin-bottom:12px;transition:border-color .15s;}
    .mlp-faq details[open]{border-color:var(--accent-ring);}
    .mlp-faq summary{cursor:pointer;font-weight:600;font-size:16px;padding:20px 0;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:16px;}
    .mlp-faq summary::-webkit-details-marker{display:none;}
    .mlp-faq summary::after{content:"";width:11px;height:11px;border-right:2px solid var(--m-muted);border-bottom:2px solid var(--m-muted);transform:rotate(45deg);transition:transform .2s;flex:none;}
    .mlp-faq details[open] summary::after{transform:rotate(-135deg);}
    .mlp-faq p{color:var(--m-muted);font-size:15px;line-height:1.7;margin:0 0 20px;}

    /* CTA band */
    .mlp-cta-band{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--accent),var(--accent-deep));border-radius:24px;padding:clamp(40px,6vw,64px) clamp(24px,5vw,48px);text-align:center;color:#fff;}
    .mlp-cta-band::after{content:"";position:absolute;inset:0;background:radial-gradient(60% 120% at 100% 0%,rgba(255,255,255,.16),transparent 60%);}
    .mlp-cta-band h2{position:relative;font-size:clamp(24px,4vw,38px);margin:0 0 14px;letter-spacing:-.025em;font-weight:800;}
    .mlp-cta-band p{position:relative;opacity:.92;font-size:clamp(15px,2vw,18px);margin:0 0 28px;}
    .mlp-cta-band .mlp-btn{position:relative;background:#fff;color:var(--accent-deep);border:none;}
    .mlp-cta-band .mlp-btn:hover{background:#fff;transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,0,0,.2);}

    /* Footer */
    .mlp-foot{border-top:1px solid var(--m-line);padding:30px 0;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;color:var(--m-muted);font-size:13.5px;}
    .mlp-foot .mlp-logo{height:30px;}
    .mlp-foot-links{display:flex;gap:18px;}
    .mlp-foot-links a:hover{color:var(--accent);}

    /* ── Responsive ── */
    @media(max-width:900px){
        .mlp-grid{grid-template-columns:repeat(2,1fr);}
        .mlp-steps{grid-template-columns:1fr;max-width:460px;gap:22px;}
    }
    @media(max-width:560px){
        .mlp-grid{grid-template-columns:1fr;}
        .mlp-logo{height:32px;} .mlp-logo-name{font-size:16px;}
        .mlp-nav{gap:7px;}
        .mlp-btn{padding:11px 15px;font-size:14px;min-height:44px;}
        .mlp-hero-cta .mlp-btn{flex:1 1 auto;}
        .mlp-trust{gap:14px;}
        .mlp-plan{padding:26px 22px;}
        .mlp-foot{flex-direction:column;text-align:center;}
    }
    @media(max-width:380px){
        .mlp-signin-text{display:none;}
    }
    </style>
</head>
<body>

<header class="mlp-header">
    <div class="mlp-wrap mlp-header-in">
        <?= $brandMark('mlp-brand') ?>
        <nav class="mlp-nav">
            <span class="mlp-lang" role="group" aria-label="Language">
                <a href="?lang=fr" class="<?= $locale === 'fr' ? 'on' : '' ?>">FR</a>
                <a href="?lang=en" class="<?= $locale === 'en' ? 'on' : '' ?>">EN</a>
            </span>
            <?php if ($cust): ?>
                <a href="<?= e(SLATE_URL) ?>/member" class="mlp-btn mlp-btn-primary"><?= __('membership_lp_my', 'My membership') ?></a>
            <?php else: ?>
                <a href="<?= e($loginUrl) ?>" class="mlp-btn mlp-btn-ghost"><span class="mlp-signin-text"><?= __('membership_lp_signin', 'Sign in') ?></span><span style="display:none" class="mlp-signin-ico"><?= mlp_icon('arrow',16) ?></span></a>
                <a href="<?= e($registerUrl) ?>" class="mlp-btn mlp-btn-primary"><?= __('membership_lp_join', 'Join now') ?></a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- Hero -->
<section class="mlp-hero">
    <div class="mlp-wrap mlp-hero-in">
        <span class="mlp-eyebrow"><?= mlp_icon('spark', 15) ?> <?= e($tagline !== '' ? $tagline : $siteName) ?></span>
        <h1><?= __('membership_lp_hero_h1', 'Become a member.') ?> <span class="hl"><?= __('membership_lp_hero_h2', 'Enjoy more.') ?></span></h1>
        <p class="mlp-hero-sub"><?= __('membership_lp_hero_p', 'One membership, everything in one place — pick a plan, manage it online, and book your sessions in seconds.') ?></p>
        <div class="mlp-hero-cta">
            <a href="#plans" class="mlp-btn mlp-btn-primary mlp-btn-lg"><?= __('membership_lp_see_plans', 'See plans') ?> <?= mlp_icon('arrow', 18) ?></a>
            <?php if (!$cust): ?>
                <a href="<?= e($registerUrl) ?>" class="mlp-btn mlp-btn-ghost mlp-btn-lg"><?= __('membership_lp_create', 'Create account') ?></a>
            <?php endif; ?>
        </div>
        <div class="mlp-trust">
            <div><?= mlp_icon('check', 18) ?> <?= __('membership_lp_strip1', 'Secure online payment') ?></div>
            <div><?= mlp_icon('check', 18) ?> <?= __('membership_lp_strip2', 'Cancel anytime') ?></div>
            <div><?= mlp_icon('check', 18) ?> <?= __('membership_lp_strip3', 'Digital member card') ?></div>
        </div>
    </div>
</section>

<!-- Benefits -->
<section class="mlp-sec">
    <div class="mlp-wrap">
        <div class="mlp-head">
            <h2><?= __('membership_lp_ben_h', 'Everything your membership unlocks') ?></h2>
            <p><?= __('membership_lp_ben_p', 'Built to make joining, paying and booking effortless.') ?></p>
        </div>
        <div class="mlp-grid">
            <div class="mlp-feature"><div class="mlp-feature-ic"><?= mlp_icon('card') ?></div>
                <h3><?= __('membership_lp_f1_h', 'Flexible plans') ?></h3>
                <p><?= __('membership_lp_f1_p', 'Choose the membership, insurance or course pass that fits you.') ?></p></div>
            <div class="mlp-feature"><div class="mlp-feature-ic"><?= mlp_icon('calendar') ?></div>
                <h3><?= __('membership_lp_f2_h', 'Easy booking') ?></h3>
                <p><?= __('membership_lp_f2_p', 'Your active membership unlocks instant online session booking.') ?></p></div>
            <div class="mlp-feature"><div class="mlp-feature-ic"><?= mlp_icon('qr') ?></div>
                <h3><?= __('membership_lp_f3_h', 'Digital member card') ?></h3>
                <p><?= __('membership_lp_f3_p', 'Check in with a QR code from your phone — no plastic card.') ?></p></div>
            <div class="mlp-feature"><div class="mlp-feature-ic"><?= mlp_icon('shield') ?></div>
                <h3><?= __('membership_lp_f4_h', 'Manage it yourself') ?></h3>
                <p><?= __('membership_lp_f4_p', 'Renew, view your wallet and update your profile anytime.') ?></p></div>
        </div>
    </div>
</section>

<!-- Plans -->
<section class="mlp-sec mlp-sec--tint" id="plans">
    <div class="mlp-wrap">
        <div class="mlp-head">
            <h2><?= __('membership_lp_plans_h', 'Choose your plan') ?></h2>
            <p><?= __('membership_lp_plans_p', 'Transparent pricing. No hidden fees.') ?></p>
        </div>
        <?php if (!$plans): ?>
            <div class="mlp-feature" style="text-align:center;max-width:480px;margin:0 auto;">
                <h3><?= __('membership_lp_no_plans', 'Plans coming soon') ?></h3>
                <p><?= __('membership_lp_no_plans_p', 'Memberships will be available here shortly. Check back soon.') ?></p>
            </div>
        <?php else: ?>
        <div class="mlp-plans">
            <?php foreach ($plans as $p):
                $feat    = (int)$p['id'] === $featuredId;
                $desc    = MembershipAPI::planDescription($p);
                $insMode = (string)($p['insurance_mode'] ?? 'none');
            ?>
                <div class="mlp-plan <?= $feat ? 'feat' : '' ?>">
                    <?php if ($feat): ?><span class="mlp-plan-badge"><?= __('membership_lp_popular', 'Most popular') ?></span><?php endif; ?>
                    <div class="mlp-plan-type"><?= e($types[$p['plan_type']] ?? $p['plan_type']) ?></div>
                    <h3><?= e(MembershipAPI::planName($p)) ?></h3>
                    <?php if ($desc !== ''): ?><p class="mlp-plan-desc"><?= e($desc) ?></p><?php endif; ?>
                    <div class="mlp-price">
                        <b><?= e(MembershipAPI::money((int)$p['price_cents'], $p['currency'])) ?></b>
                        <span>/ <?= (int)$p['duration_days'] ?> <?= __('membership_days', 'days') ?></span>
                    </div>
                    <ul>
                        <?php foreach ($planFeatures($p) as $bullet): ?>
                            <li><?= mlp_icon('check', 17) ?> <span><?= e($bullet) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mlp-plan-foot">
                    <?php if ($cust): ?>
                        <form method="post" action="<?= e(SLATE_URL) ?>/member?view=plans">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="buy">
                            <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                            <?php if ($insMode !== 'none' && $insFee > 0): ?>
                                <?php if ($insMode === 'required'): ?>
                                    <input type="hidden" name="add_insurance" value="1">
                                    <p class="mlp-ins-note">+ <?= e(MembershipAPI::money($insFee, $p['currency'])) ?> · <?= __('membership_ins_inc', 'insurance included') ?></p>
                                <?php else: ?>
                                    <label class="mlp-ins">
                                        <input type="checkbox" name="add_insurance" value="1">
                                        <span><?= __('membership_add_insurance', 'Add insurance') ?> (+<?= e(MembershipAPI::money($insFee, $p['currency'])) ?>)</span>
                                    </label>
                                <?php endif; ?>
                            <?php endif; ?>
                            <button type="submit" class="mlp-btn <?= $feat ? 'mlp-btn-primary' : 'mlp-btn-ghost' ?> mlp-btn-block"><?= __('membership_lp_choose', 'Choose plan') ?></button>
                        </form>
                    <?php else: ?>
                        <?php if ($insMode !== 'none' && $insFee > 0): ?>
                            <p class="mlp-ins-note"><?= $insMode === 'required' ? __('membership_ins_inc', 'insurance included') : __('membership_ins_avail', 'insurance available') ?> · <?= e(MembershipAPI::money($insFee, $p['currency'])) ?></p>
                        <?php endif; ?>
                        <a href="<?= e($registerUrl) ?>" class="mlp-btn <?= $feat ? 'mlp-btn-primary' : 'mlp-btn-ghost' ?> mlp-btn-block"><?= __('membership_lp_choose', 'Choose plan') ?></a>
                    <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$cust): ?>
            <p class="mlp-signin-row"><?= __('membership_lp_have_acct', 'Already a member?') ?> <a href="<?= e($loginUrl) ?>"><?= __('membership_lp_signin', 'Sign in') ?></a></p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- How it works -->
<section class="mlp-sec">
    <div class="mlp-wrap">
        <div class="mlp-head"><h2><?= __('membership_lp_how_h', 'How it works') ?></h2></div>
        <div class="mlp-steps">
            <div class="mlp-step"><div class="mlp-step-n">1</div><h3><?= __('membership_lp_s1_h', 'Create your account') ?></h3><p><?= __('membership_lp_s1_p', 'Sign up in under a minute and verify your email.') ?></p></div>
            <div class="mlp-step"><div class="mlp-step-n">2</div><h3><?= __('membership_lp_s2_h', 'Pick & pay for a plan') ?></h3><p><?= __('membership_lp_s2_p', 'Secure checkout. Your membership activates instantly.') ?></p></div>
            <div class="mlp-step"><div class="mlp-step-n">3</div><h3><?= __('membership_lp_s3_h', 'Book your sessions') ?></h3><p><?= __('membership_lp_s3_p', 'Use your digital card and book online whenever you like.') ?></p></div>
        </div>
    </div>
</section>

<!-- FAQ -->
<section class="mlp-sec mlp-sec--tint">
    <div class="mlp-wrap">
        <div class="mlp-head"><h2><?= __('membership_lp_faq_h', 'Frequently asked questions') ?></h2></div>
        <div class="mlp-faq">
            <details open><summary><?= __('membership_lp_q1', 'How do I pay?') ?></summary><p><?= __('membership_lp_a1', 'Payment is handled securely online by card. Your membership activates as soon as the payment succeeds.') ?></p></details>
            <details><summary><?= __('membership_lp_q2', 'Can I cancel?') ?></summary><p><?= __('membership_lp_a2', 'Yes — you can cancel your membership anytime from your member dashboard.') ?></p></details>
            <details><summary><?= __('membership_lp_q3', 'What is the member card?') ?></summary><p><?= __('membership_lp_a3', 'A digital QR card on your phone used to check in at the front desk. No plastic required.') ?></p></details>
            <details><summary><?= __('membership_lp_q4', 'Do I need a membership to book?') ?></summary><p><?= __('membership_lp_a4', 'Most sessions require an active membership and a completed profile. Some also require insurance.') ?></p></details>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="mlp-sec" style="padding-top:0;">
    <div class="mlp-wrap">
        <div class="mlp-cta-band">
            <h2><?= __('membership_lp_cta_h', 'Ready to get started?') ?></h2>
            <p><?= __('membership_lp_cta_p', 'Join today and book your first session.') ?></p>
            <a href="<?= $cust ? e(SLATE_URL . '/member?view=plans') : e($registerUrl) ?>" class="mlp-btn mlp-btn-lg"><?= $cust ? __('membership_lp_choose', 'Choose plan') : __('membership_lp_join', 'Join now') ?> <?= mlp_icon('arrow', 18) ?></a>
        </div>
    </div>
</section>

<footer>
    <div class="mlp-wrap mlp-foot">
        <?= $brandMark('mlp-brand') ?>
        <div class="mlp-foot-links">
            <a href="<?= e($loginUrl) ?>"><?= __('membership_lp_signin', 'Sign in') ?></a>
            <a href="<?= e($registerUrl) ?>"><?= __('membership_lp_join', 'Join now') ?></a>
        </div>
        <span>© <?= e(date('Y')) ?> <?= e($siteName) ?></span>
    </div>
</footer>

</body>
</html>
