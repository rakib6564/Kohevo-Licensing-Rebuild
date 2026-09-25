<?php
/**
 * Slate — shared customer portal shell.
 *
 * The customer-side counterpart to admin/partials/header.php. That file is
 * what makes the admin section feel like one product despite eighteen plugins
 * contributing to it: each plugin ships its own pages, and they all `require`
 * one shell that renders one navigation built from `admin_nav_items`.
 *
 * This does the same for customers, off `customer_nav_items`. A plugin's
 * customer page requires this shell instead of hand-rolling its own chrome:
 *
 *     $currentPortalNav = 'studio';                 // marks the active tab
 *     slate_portal_shell_head(__('studio_my_studio', 'My studio'));
 *     slate_portal_shell_open();
 *         … page content, using the portal-app.css kit (pcard/stat/pill/…) …
 *     slate_portal_shell_close();
 *
 * Navigation renders twice from one item list, exactly as admin does: pill
 * tabs in the top bar on desktop, and a fixed bottom tab bar under 768px with
 * the overflow behind "More". CustomerNav (src/Presentation) does the pure
 * work — normalise, sort, group, split — so the rules are unit-tested without
 * a database; everything here is markup and wiring.
 *
 * Gating note: customers have no permission system, so unlike the admin shell
 * this does NOT filter items centrally. Each plugin decides in its own
 * callback whether the item applies to this customer — see the contract
 * documented on CustomerNav.
 */

if (!defined('SLATE_ROOT')) { exit; }

require_once __DIR__ . '/portal_ui.php';   // slate_icon(), slate_portal_asset_url()

use Slate\Presentation\CustomerNav;

if (!function_exists('slate_portal_context')) {
    function slate_portal_context(string $area, array $data = []): void
    {
        $GLOBALS['slate_portal_context'] = array_merge(['area' => $area], $data);
    }
}

if (!function_exists('slate_portal_get_context')) {
    function slate_portal_get_context(): array
    {
        return $GLOBALS['slate_portal_context'] ?? [];
    }
}

if (!function_exists('slate_portal_nav')) {
    /**
     * Resolve the customer navigation for the signed-in customer.
     *
     * Cached per request: the shell renders the same list three times (top
     * tabs, bottom bar, More sheet) and a plugin callback may hit the database
     * to decide whether its item applies.
     *
     * @return array{items: array, groups: array, tabs: array, more: array}
     */
    function slate_portal_nav(?string $activeSlug = null): array
    {
        static $cache = [];
        $key = (string) $activeSlug;
        if (isset($cache[$key])) { return $cache[$key]; }

        if (class_exists('\Slate\Services\Portal\CustomerPortal')) {
            return $cache[$key] = \Slate\Services\Portal\CustomerPortal::current()->navigation($activeSlug);
        }

        // Fallback if CustomerPortal class is unavailable
        $core = [
            ['slug' => 'home',    'label' => __('portal_home', 'Home'),
             'href' => SLATE_URL . '/member',               'icon' => 'home', 'order' => 10,  'group' => 'main'],
            ['slug' => 'account', 'label' => __('portal_account', 'Account'),
             'href' => SLATE_URL . '/member/account',       'icon' => 'user', 'order' => 990, 'group' => 'main'],
        ];

        $raw = class_exists('Hook') ? Hook::applyFilters('customer_nav_items', $core) : $core;
        if (!is_array($raw)) { $raw = $core; }

        $items = CustomerNav::resolve($raw, $activeSlug);
        $split = CustomerNav::split($items);

        return $cache[$key] = [
            'items'  => $items,
            'groups' => CustomerNav::group($items),
            'tabs'   => $split['tabs'],
            'more'   => $split['more'],
        ];
    }
}

require_once __DIR__ . '/brand_tokens.php';

if (!function_exists('slate_portal_accent_tokens')) {
    /**
     * Derive readable companions for a tenant's brand accent.
     *
     * A brand colour is chosen to look right, not to be legible under text, and
     * this tenant's (#F89DC1) makes the point: white on it is 1.98:1, so every
     * primary button in the kit — "Save QR", "Pay now" — was failing AA while
     * looking perfectly deliberate. Dark ink on the same pink is 8.96:1.
     *
     * So rather than hardcode #fff, pick per tenant:
     *   on_accent  — text placed ON the accent (buttons, filled avatars)
     *   accent_ink — the accent used AS text, darkened until it clears 4.5:1
     *                on the pale accent-soft tint (active tabs, pills, the
     *                name in the hero). Keeps the brand hue, gains contrast.
     *
     * The maths moved to includes/brand_tokens.php when the admin chrome
     * needed it too. This name stays as the portal's way in.
     *
     * @return array{on_accent:string,accent_ink:string}
     */
    function slate_portal_accent_tokens(string $hex, ?string $onAccentOverride = null): array
    {
        return slate_brand_accent_tokens($hex, $onAccentOverride);
    }
}

if (!function_exists('slate_portal_avatar')) {
    /**
     * Avatar for a signed-in customer: supplied image → Gravatar → initials.
     *
     * Core has no avatar column (membership keeps one on its profile), so the
     * image is asked for via a filter rather than assumed. Gravatar is loaded
     * with d=404 and the <img> removes itself on error, which lets the initials
     * underneath show through without a flash of a broken image.
     */
    function slate_portal_avatar(?array $cust, string $class = 'mapp-avatar'): string
    {
        $name  = trim((string) ($cust['name'] ?? $cust['email'] ?? ''));
        $email = strtolower(trim((string) ($cust['email'] ?? '')));

        $initials = '';
        foreach (preg_split('/\s+/', $name) as $w) {
            if ($w !== '') { $initials .= mb_substr($w, 0, 1); }
            if (mb_strlen($initials) >= 2) { break; }
        }
        $initials = mb_strtoupper($initials !== '' ? $initials : '?');

        $url = '';
        if (class_exists('Hook')) {
            $url = (string) Hook::applyFilters('customer_portal_avatar', '', (int) ($cust['id'] ?? 0));
        }
        if ($url === '' && $email !== '' && str_contains($email, '@')) {
            $url = 'https://www.gravatar.com/avatar/' . md5($email) . '?s=96&d=404';
        }

        $html = '<span class="' . e($class) . '" title="' . e($name) . '">' . e($initials);
        if ($url !== '') {
            $html .= '<img src="' . e($url) . '" alt="" loading="lazy" onerror="this.remove()">';
        }
        return $html . '</span>';
    }
}

if (!function_exists('slate_portal_shell_head')) {
    /**
     * Opens the document. Mirrors slate_portal_head() but loads the app kit
     * (portal-app.css) rather than portal.css, and sets the brand accent as an
     * inline custom property the way the member app already did — the kit is
     * built on --accent / --accent-deep / --accent-soft / --accent-ring.
     */
    function slate_portal_shell_head(string $title, string $bodyClass = ''): void
    {
        $siteName = Database::setting('site_name') ?: 'Kohevo';

        $accent = trim((string) Database::setting('brand_accent_color'));
        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) { $accent = '#2563EB'; }
        // Settings -> Branding -> Button text color: manual override for
        // the text/icon color placed ON the accent fill (buttons, filled
        // avatars). 'auto' (default) keeps the measured pick.
        $onAccentMode = strtolower(trim((string) Database::setting('brand_button_text_mode')));
        if ($onAccentMode !== 'light' && $onAccentMode !== 'dark') { $onAccentMode = null; }
        $accentTokens = slate_portal_accent_tokens($accent, $onAccentMode);

        $cls = trim('mapp ' . $bodyClass);
        ?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($accent) ?>">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); ?>
    <link rel="stylesheet" href="<?= e(slate_portal_asset_url('assets/css/portal-app.css')) ?>">
    <style>
    :root{
        --accent:<?= $accent ?>;
        --accent-deep:color-mix(in srgb,<?= $accent ?> 84%, #000);
        --accent-soft:color-mix(in srgb,<?= $accent ?> 9%, #fff);
        --accent-ring:color-mix(in srgb,<?= $accent ?> 22%, transparent);
        /* Derived per tenant — see slate_portal_accent_tokens(). --on-accent is
           whichever of white/ink reads on this brand; --accent-ink is the brand
           hue darkened until it clears AA as text. */
        --on-accent:<?= $accentTokens['on_accent'] ?>;
        --accent-ink:<?= $accentTokens['accent_ink'] ?>;
    }
    <?php slate_portal_shell_css(); ?>
    </style>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <?php
    if (class_exists('Hook')) { Hook::doAction('customer_head'); }
    if (class_exists('PluginLoader')) { echo PluginLoader::renderQueuedStyles(); }
    ?>
    <script>
    /* Generic auto-resize for a classic <iframe src=".../book?embed=1">
       booking-widget embed, should the portal (or anything else in this
       shell) ever use one instead of a native/fragment include. The
       widget already posts {type:"cb-booking-height",height} on load/
       resize -- nothing on this side listened for it, so an iframed
       widget taller than its fixed box just got cut off. No-op if no
       matching iframe is on the page. */
    (function () {
        window.addEventListener('message', function (e) {
            if (!e.data || e.data.type !== 'cb-booking-height' || !e.data.height) return;
            var frames = document.querySelectorAll('iframe');
            for (var i = 0; i < frames.length; i++) {
                if (frames[i].contentWindow === e.source) {
                    frames[i].style.height = Math.max(0, parseInt(e.data.height, 10)) + 'px';
                    break;
                }
            }
        });
    })();
    </script>
</head>
<body class="<?= e($cls) ?>">
<a class="skip-link" href="#portal-main"><?= __('skip_to_content', 'Skip to main content') ?></a>
<?php
    }
}

if (!function_exists('slate_portal_shell_css')) {
    /** The few rules the app kit doesn't already ship: More sheet + empty nav. */
    function slate_portal_shell_css(): void
    {
        ?>
    /* "More" overflow sheet — the mobile counterpart to the admin nav's More
       sheet. Closed by default; opened by the tab bar's More button. */
    .mapp-more[hidden]{display:none;}
    .mapp-more{position:fixed;inset:0;z-index:60;display:grid;align-items:end;}
    .mapp-more-scrim{position:absolute;inset:0;background:rgba(15,18,26,.42);border:0;padding:0;}
    .mapp-more-panel{position:relative;background:var(--m-surface,#fff);border-radius:20px 20px 0 0;
        padding:10px 10px calc(14px + env(safe-area-inset-bottom));max-height:70vh;overflow:auto;}
    .mapp-more-grip{width:38px;height:4px;border-radius:999px;background:var(--m-line,#ECEEF1);margin:6px auto 10px;}
    .mapp-more a{display:flex;align-items:center;gap:13px;padding:13px 14px;border-radius:13px;
        font-size:15px;font-weight:600;color:var(--m-ink,#15181E);}
    .mapp-more a:hover{background:var(--m-bg,#F4F5F7);}
    .mapp-more a.on{background:var(--accent-soft);color:var(--accent);}
    .mapp-more a svg{width:20px;height:20px;flex:none;}
    .mapp-tabbar button.mapp-more-btn{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;
        padding:7px 2px;border:0;background:none;border-radius:12px;color:var(--m-muted,#737886);
        font:inherit;font-size:10.5px;font-weight:600;cursor:pointer;}
    .mapp-tabbar button.mapp-more-btn svg{width:23px;height:23px;}
    /* The kit's .stat-grid is a fixed 3-up. The portal can't know how many KPIs
       plugins will contribute, and a 4th on a 3-col grid strands itself on its
       own row — so the portal home uses an auto-fit track instead. */
    .stat-grid--auto{grid-template-columns:repeat(auto-fit,minmax(132px,1fr));}
    /* Section nav — the local counterpart to the global tabs, for a plugin
       area with several pages of its own (Membership: Plans/Card/Schedule/…).
       This used to be its own always-visible bar sitting right under the one
       above, on every device, permanently -- two full nav bars on screen at
       once (with, e.g., two different "Home" links) is what read as a
       "double menu". It's now a dropdown riding on the active tab that owns
       it instead: a panel on desktop (below, since .mapp-toptabs is a top
       bar), the ".mapp-more" bottom sheet's own styles reused as-is on
       mobile (see #portal-section-nav in slate_portal_shell_close()). */
    .mapp-navdrop{position:relative;}
    .mapp-navdrop-caret{width:14px;height:14px;margin-left:-2px;flex:none;}
    .mapp-navdrop-panel{position:absolute;top:calc(100% + 8px);left:0;z-index:40;
        display:flex;flex-direction:column;gap:2px;min-width:190px;padding:6px;
        background:var(--m-surface,#fff);border-radius:14px;
        box-shadow:0 4px 16px rgba(15,18,26,.10),0 16px 40px -12px rgba(15,18,26,.22);}
    .mapp-navdrop-panel[hidden]{display:none;}
    .mapp-navdrop-panel a{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;
        font-size:14px;font-weight:600;color:var(--m-ink,#15181E);white-space:nowrap;}
    .mapp-navdrop-panel a:hover{background:var(--m-bg,#F4F5F7);}
    .mapp-navdrop-panel a.on{background:var(--accent-soft);color:var(--accent);}
    .mapp-navdrop-panel a svg{width:18px;height:18px;flex:none;}
    /* A nav badge (e.g. unpaid tuition) on both the pill tab and the bar. */
    .mapp-badge{display:inline-grid;place-items:center;min-width:17px;height:17px;padding:0 5px;
        border-radius:999px;background:var(--m-amber,#D97706);color:#fff;font-size:10.5px;font-weight:800;}
    .mapp-tabbar .mapp-badge{position:absolute;transform:translate(12px,-8px);}
    .mapp-tabbar a{position:relative;}
        <?php
    }
}

if (!function_exists('slate_portal_shell_open')) {
    /**
     * Renders the top bar (brand + nav + account) and opens <main>.
     *
     * @param array $opts 'active'        => slug (defaults to $currentPortalNav),
     *                    'area'          => feature area string (e.g. 'membership', 'coaching'),
     *                    'subnav_active' => active tab in contextual subnav,
     *                    'auto_subnav'   => bool (defaults to true if area set),
     *                    'title'         => page title shown to assistive tech.
     */
    function slate_portal_shell_open(array $opts = []): void
    {
        $active = (string) ($opts['active'] ?? $GLOBALS['currentPortalNav'] ?? '');
        $area   = (string) ($opts['area'] ?? $GLOBALS['slate_portal_context']['area'] ?? '');
        if ($area !== '') {
            slate_portal_context($area, ['subnav_active' => $opts['subnav_active'] ?? '']);
        }

        $nav = slate_portal_nav($active !== '' ? $active : null);

        // Section-nav items (Diary/Goals/... for coaching, Plans/Card/... for
        // membership). Computed here -- before the tabs below render -- so
        // the active tab that owns them can carry a dropdown/sheet instead
        // of this being a second, always-visible bar (see the CSS note on
        // .mapp-navdrop above for why that changed).
        $subItems = [];
        if ($area !== '' && $area !== 'home' && ($opts['auto_subnav'] ?? true) && class_exists('\Slate\Services\Portal\CustomerPortal')) {
            $activeSubnav = (string) ($opts['subnav_active'] ?? $GLOBALS['slate_portal_context']['subnav_active'] ?? '');
            $s = \Slate\Services\Portal\CustomerPortal::current()->contextNavigation($area, $activeSubnav !== '' ? $activeSubnav : null);
            if ($s && count($s) >= 2) { $subItems = $s; }
        }
        $GLOBALS['slate_portal_subnav_items'] = $subItems;

        $siteName = Database::setting('site_name') ?: 'Kohevo';
        $logoUrls = slate_logo_urls();
        $logoUrl  = $logoUrls['light'];
        $logoDarkUrl = $logoUrls['dark'];
        $cust     = class_exists('Auth') ? Auth::customer() : null;
        $name     = (string) ($cust['name'] ?? $cust['email'] ?? '');
        $initial  = mb_strtoupper(mb_substr($name !== '' ? $name : $siteName, 0, 1));
        $home     = SLATE_URL . '/member';
        ?>
<header class="mapp-bar">
    <div class="mapp-bar-in">
        <a href="<?= e($home) ?>" class="mapp-brand" aria-label="<?= e($siteName) ?>">
            <?php /* Always the light --m-surface bar -- no dark-mode CSS exists for it, so always the light logo. */ ?>
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
            <?php else: ?>
                <span class="mapp-brand-mark"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
            <?php endif; ?>
        </a>

        <?php if ($nav['items']): ?>
        <nav class="mapp-toptabs" aria-label="<?= e(__('portal_nav', 'Portal')) ?>">
            <?php foreach ($nav['items'] as $i): ?>
                <?php if ($i['is_active'] && $subItems): ?>
                    <div class="mapp-navdrop">
                        <a href="<?= e($i['href']) ?>" class="on" aria-current="page"
                           aria-haspopup="true" aria-expanded="false" data-portal-navdrop-trigger>
                            <?= slate_icon($i['icon'], 'icon') ?><span><?= e($i['label']) ?></span>
                            <?php if ($i['badge'] !== ''): ?><span class="mapp-badge"><?= e($i['badge']) ?></span><?php endif; ?>
                            <svg class="mapp-navdrop-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <polyline points="6 9 12 15 18 9"/></svg>
                        </a>
                        <div class="mapp-navdrop-panel" hidden>
                            <?php foreach ($subItems as $si): ?>
                                <a href="<?= e((string)($si['href'] ?? '#')) ?>" class="<?= !empty($si['active']) ? 'on' : '' ?>">
                                    <?= isset($si['icon']) ? slate_icon((string)$si['icon'], 'icon') : '' ?><span><?= e((string)($si['label'] ?? '')) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?= e($i['href']) ?>" class="<?= $i['is_active'] ? 'on' : '' ?>"
                       <?= $i['is_active'] ? 'aria-current="page"' : '' ?>>
                        <?= slate_icon($i['icon'], 'icon') ?><span><?= e($i['label']) ?></span>
                        <?php if ($i['badge'] !== ''): ?><span class="mapp-badge"><?= e($i['badge']) ?></span><?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <div class="mapp-bar-end">
            <?php
            // Plugin-supplied bar controls, mirroring admin_topbar_actions.
            $barActions = class_exists('Hook') ? Hook::applyFilters('customer_portal_bar_actions', []) : [];
            if (is_array($barActions)) { foreach ($barActions as $html) { echo $html; } }
            ?>
            <?php if ($cust): ?>
                <?= slate_portal_avatar($cust) ?>
                <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"
                   class="mapp-signout" title="<?= e(__('sign_out', 'Sign out')) ?>"
                   aria-label="<?= e(__('sign_out', 'Sign out')) ?>">
                    <?= slate_icon('logout', 'icon') ?>
                </a>
            <?php else: ?>
                <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="mbtn mbtn-ghost"><?= __('sign_in', 'Sign in') ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main class="mapp-main" id="portal-main">
<?php
        // Desktop dropdown wiring for the tab rendered above. The mobile
        // counterpart (a bottom sheet, reusing the existing "More" sheet's
        // own markup/CSS) is rendered in slate_portal_shell_close(), once
        // this page's content -- and $GLOBALS['slate_portal_subnav_items']
        // set above -- are both available there.
        if ($subItems): ?>
        <script>
        (function () {
            var wrap = document.querySelector('.mapp-navdrop');
            if (!wrap) return;
            var trigger = wrap.querySelector('[data-portal-navdrop-trigger]');
            var panel = wrap.querySelector('.mapp-navdrop-panel');
            if (!trigger || !panel) return;
            function set(open) {
                panel.hidden = !open;
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            trigger.addEventListener('click', function (e) {
                e.preventDefault();
                set(panel.hidden);
            });
            document.addEventListener('click', function (e) {
                if (!panel.hidden && !wrap.contains(e.target)) set(false);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !panel.hidden) { set(false); trigger.focus(); }
            });
        })();
        </script>
        <?php endif; ?>
<?php
    }
}

if (!function_exists('slate_portal_welcome')) {
    /**
     * The welcome hero — the customer-side twin of the admin dashboard's.
     *
     * Named _welcome, not _hero, because portal_ui.php already has
     * slate_portal_hero() (the older gradient banner) and both files are loaded
     * on these pages.
     *
     * @param array $o eyebrow, title, name (accent-gradient), sub,
     *                 status => ['label'=>…, 'tone'=>'green'|'amber'], clock => bool
     */
    function slate_portal_welcome(array $o = []): void
    {
        $name   = trim((string) ($o['name'] ?? ''));
        $status = is_array($o['status'] ?? null) ? $o['status'] : null;
        ?>
        <div class="phero">
            <div class="phero-main">
                <?php if (!empty($o['eyebrow'])): ?>
                    <div class="phero-eyebrow"><?= e((string) $o['eyebrow']) ?></div>
                <?php endif; ?>
                <h1 class="phero-title"><?= e((string) ($o['title'] ?? ''))
                    ?><?php if ($name !== ''): ?>, <span class="name"><?= e($name) ?></span><?php endif; ?>.</h1>
                <?php if (!empty($o['sub'])): ?>
                    <p class="phero-sub"><?= e((string) $o['sub']) ?></p>
                <?php endif; ?>
            </div>
            <div class="phero-side">
                <?php if ($status !== null && ($status['label'] ?? '') !== ''): ?>
                    <span class="phero-status<?= ($status['tone'] ?? '') === 'amber' ? ' is-amber' : '' ?>">
                        <span class="dot"></span> <?= e((string) $status['label']) ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($o['clock'])): ?>
                    <span class="phero-clock"><?= e(I18n::localDate('D · j M Y')) ?> · <span class="t"><?= e(date('g:i a')) ?></span></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('slate_portal_stats')) {
    /**
     * The KPI card row. Same shape plugins already use for
     * `customer_dashboard_kpis`: label, value, icon, and an optional `sub`
     * caption and `href` that makes the whole card a link.
     *
     * @param array $stats [['label'=>…, 'value'=>…, 'icon'=>…, 'sub'=>…, 'href'=>…], …]
     */
    function slate_portal_stats(array $stats): void
    {
        $stats = array_values(array_filter($stats, 'is_array'));
        if (!$stats) { return; }
        echo '<div class="pstats">';
        foreach ($stats as $s) {
            $icon  = (string) ($s['icon'] ?? 'chart');
            $value = (string) ($s['value'] ?? '—');
            $href  = trim((string) ($s['href'] ?? ''));
            // A currency figure needs more room than a count before it wraps.
            $money = $value !== '' && preg_match('/[^\d\s,.\-]/u', $value) === 1;

            echo $href !== '' ? '<a class="pstat" href="' . e($href) . '">' : '<div class="pstat">';
            echo   '<div class="pstat-top">'
                 . '<span class="pstat-badge">' . slate_icon($icon, '') . '</span>'
                 . '<span class="pstat-num' . ($money ? ' is-money' : '') . '">' . e($value) . '</span>'
                 . '</div>'
                 . '<div class="pstat-k">' . e((string) ($s['label'] ?? '')) . '</div>';
            if (($s['sub'] ?? '') !== '') {
                echo '<div class="pstat-s">' . e((string) $s['sub']) . '</div>';
            }
            echo   '<span class="pstat-wm">' . slate_icon($icon, '') . '</span>';
            echo $href !== '' ? '</a>' : '</div>';
        }
        echo '</div>';
    }
}

if (!function_exists('slate_portal_subnav')) {
    /**
     * Registers section-nav items (Diary/Goals/... for coaching, Plans/Card/
     * ... for membership) for the current page.
     *
     * These used to render immediately as a second, always-visible bar under
     * the main one. They now ride as a dropdown (desktop) / bottom sheet
     * (mobile) attached to whichever main-nav tab is active instead -- see
     * slate_portal_shell_open() and slate_portal_shell_close() -- so this
     * just registers them into $GLOBALS['slate_portal_subnav_items'].
     * slate_portal_shell_open() already does this automatically from the
     * customer_portal_context_nav hook before a plugin's own page code
     * runs, so an explicit call here with the same items (coaching,
     * membership both do this) is a harmless no-op; kept so those call
     * sites don't need to change.
     *
     * @param array $items  [['href'=>…, 'label'=>…, 'icon'=>…, 'active'=>bool], …]
     */
    function slate_portal_subnav(array $items): void
    {
        if (!empty($GLOBALS['slate_portal_subnav_items'])) { return; }
        $items = array_values(array_filter($items, 'is_array'));
        if (count($items) < 2) { return; }   // one tab is not navigation
        $GLOBALS['slate_portal_subnav_items'] = $items;
    }
}

if (!function_exists('slate_portal_shell_close')) {
    /** Closes <main>, then renders the mobile tab bar + More sheet. */
    function slate_portal_shell_close(): void
    {
        $nav = slate_portal_nav($GLOBALS['currentPortalNav'] ?? null);
        $subItems = $GLOBALS['slate_portal_subnav_items'] ?? [];
        if (!$nav['items']) { echo "</main>\n</body>\n</html>\n"; return; }
        ?>
</main>

<nav class="mapp-tabbar" aria-label="<?= e(__('portal_nav', 'Portal')) ?>">
    <?php foreach ($nav['tabs'] as $i): ?>
        <?php if ($i['is_active'] && $subItems): ?>
            <a href="<?= e($i['href']) ?>" class="on" aria-current="page"
               data-portal-section-nav aria-haspopup="true" aria-expanded="false" aria-controls="portal-section-nav">
                <?= slate_icon($i['icon'], 'icon') ?><span><?= e($i['label']) ?></span>
                <?php if ($i['badge'] !== ''): ?><span class="mapp-badge"><?= e($i['badge']) ?></span><?php endif; ?>
            </a>
        <?php else: ?>
            <a href="<?= e($i['href']) ?>" class="<?= $i['is_active'] ? 'on' : '' ?>"
               <?= $i['is_active'] ? 'aria-current="page"' : '' ?>>
                <?= slate_icon($i['icon'], 'icon') ?><span><?= e($i['label']) ?></span>
                <?php if ($i['badge'] !== ''): ?><span class="mapp-badge"><?= e($i['badge']) ?></span><?php endif; ?>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($nav['more']): ?>
        <button type="button" class="mapp-more-btn" data-portal-more
                aria-expanded="false" aria-controls="portal-more">
            <?= slate_icon('menu', 'icon') ?><span><?= __('more', 'More') ?></span>
        </button>
    <?php endif; ?>
</nav>

<?php if ($nav['more']): ?>
<div class="mapp-more" id="portal-more" hidden>
    <button type="button" class="mapp-more-scrim" data-portal-more-close
            aria-label="<?= e(__('close', 'Close')) ?>"></button>
    <div class="mapp-more-panel" role="dialog" aria-modal="true"
         aria-label="<?= e(__('more', 'More')) ?>">
        <div class="mapp-more-grip"></div>
        <?php foreach ($nav['more'] as $i): ?>
            <a href="<?= e($i['href']) ?>" class="<?= $i['is_active'] ? 'on' : '' ?>">
                <?= slate_icon($i['icon'], 'icon') ?><span><?= e($i['label']) ?></span>
                <?php if ($i['badge'] !== ''): ?><span class="mapp-badge" style="margin-left:auto"><?= e($i['badge']) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script>
(function () {
    var sheet = document.getElementById('portal-more');
    var btn   = document.querySelector('[data-portal-more]');
    if (!sheet || !btn) return;
    function set(open) {
        sheet.hidden = !open;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Stop the page scrolling behind an open sheet.
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) { var a = sheet.querySelector('a'); if (a) a.focus(); }
    }
    btn.addEventListener('click', function () { set(sheet.hidden); });
    sheet.addEventListener('click', function (e) {
        if (e.target.closest('[data-portal-more-close]')) set(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !sheet.hidden) { set(false); btn.focus(); }
    });
})();
</script>
<?php endif; ?>

<?php if ($subItems): ?>
<div class="mapp-more" id="portal-section-nav" hidden>
    <button type="button" class="mapp-more-scrim" data-portal-section-nav-close
            aria-label="<?= e(__('close', 'Close')) ?>"></button>
    <div class="mapp-more-panel" role="dialog" aria-modal="true"
         aria-label="<?= e(__('portal_section_nav', 'Section')) ?>">
        <div class="mapp-more-grip"></div>
        <?php foreach ($subItems as $si): ?>
            <a href="<?= e((string)($si['href'] ?? '#')) ?>" class="<?= !empty($si['active']) ? 'on' : '' ?>">
                <?= isset($si['icon']) ? slate_icon((string)$si['icon'], 'icon') : '' ?><span><?= e((string)($si['label'] ?? '')) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<script>
(function () {
    // Mobile counterpart of the desktop dropdown above -- reuses the
    // "#portal-more" sheet's own pattern verbatim, just against a
    // different trigger/sheet pair (there can be more than one matching
    // trigger in the DOM -- .mapp-toptabs' and .mapp-tabbar's copies of
    // the active tab both exist at once, CSS just hides one per
    // breakpoint -- so all of them are wired to the one shared sheet).
    var sheet = document.getElementById('portal-section-nav');
    var triggers = document.querySelectorAll('[data-portal-section-nav]');
    if (!sheet || !triggers.length) return;
    function set(open) {
        sheet.hidden = !open;
        triggers.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) { var a = sheet.querySelector('a'); if (a) a.focus(); }
    }
    triggers.forEach(function (t) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            set(sheet.hidden);
        });
    });
    sheet.addEventListener('click', function (e) {
        if (e.target.closest('[data-portal-section-nav-close]')) set(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !sheet.hidden) { set(false); }
    });
})();
</script>
<?php endif; ?>

</body>
</html>
<?php
    }
}
