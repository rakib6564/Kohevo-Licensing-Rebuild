<?php
/**
 * Slate — customer portal UI kit.
 *
 * PHP counterpart to assets/css/portal.css. Provides the inline SVG icon
 * engine plus the shell and component helpers every customer-facing page
 * builds from, so no plugin has to hand-roll a <head>, a topbar, or a
 * progress dial again.
 *
 * Usage:
 *     require_once SLATE_ROOT . '/includes/portal_ui.php';
 *     slate_portal_head('My goals');           // opens <html>…<body class="portal">
 *     slate_portal_topbar(['eyebrow' => 'Today', 'title' => 'Monday, 20 July']);
 *     echo '<main class="wrap">';
 *     …
 *     echo '</main>';
 *     slate_portal_foot();
 *
 * COLOUR: never hardcode an accent here. The CSS resolves every brand tone
 * from var(--accent); markup below only ever names semantic classes.
 */

if (!defined('SLATE_ROOT')) exit;

/**
 * Cache-busted URL for a core asset.
 *
 * Same reasoning as PluginLoader::assetVersion() — assets are served with a
 * long max-age, so the URL must change whenever the file does or browsers
 * and the CDN keep serving a stale copy for a week.
 */
function slate_portal_asset_url(string $rel): string {
    $rel  = ltrim($rel, '/');
    $disk = SLATE_ROOT . '/' . $rel;
    $ver  = @filemtime($disk);
    return SLATE_URL . '/' . $rel . ($ver !== false ? '?v=' . $ver : '');
}

function slate_portal_emit_css(): void {
    if (defined('SLATE_PORTAL_CSS_EMITTED')) return;
    define('SLATE_PORTAL_CSS_EMITTED', true);
    echo '<link rel="stylesheet" href="' . e(slate_portal_asset_url('assets/css/portal.css')) . '">' . "\n";
}

/* ═══ Icon engine ═══════════════════════════════════════════════════════
   Inline SVG only — an external icon CDN is a network dependency that can
   fail and leave every control unlabelled. Paths are normalised to a
   24×24 viewBox; stroke/size come from CSS (svg.icon).                    */

function slate_icon(string $name, string $class = 'icon'): string {
    static $paths = [
        // ── Chrome ──────────────────────────────────────────────────────
        'search'    => '<circle cx="11" cy="11" r="7.25"/><path d="M20.5 20.5l-4.15-4.15"/>',
        'bell'      => '<path d="M18 8.5a6 6 0 1 0-12 0c0 3.6-.85 5.6-1.72 6.76a.86.86 0 0 0 .68 1.37h14.08a.86.86 0 0 0 .68-1.37C18.85 14.1 18 12.1 18 8.5z"/><path d="M10.1 20a2.2 2.2 0 0 0 3.8 0"/>',
        'settings'  => '<circle cx="12" cy="12" r="3.1"/><path d="M12 2.8a2 2 0 0 1 1.9 1.37l.2.6a1.5 1.5 0 0 0 2.05 1.19l.58-.25a2 2 0 0 1 2.53 2.8l-.32.55a1.5 1.5 0 0 0 .58 2.1l.55.31a2 2 0 0 1 0 3.06l-.55.31a1.5 1.5 0 0 0-.58 2.1l.32.55a2 2 0 0 1-2.53 2.8l-.58-.25a1.5 1.5 0 0 0-2.05 1.19l-.2.6a2 2 0 0 1-3.8 0l-.2-.6a1.5 1.5 0 0 0-2.05-1.19l-.58.25a2 2 0 0 1-2.53-2.8l.32-.55a1.5 1.5 0 0 0-.58-2.1l-.55-.31a2 2 0 0 1 0-3.06l.55-.31a1.5 1.5 0 0 0 .58-2.1l-.32-.55a2 2 0 0 1 2.53-2.8l.58.25A1.5 1.5 0 0 0 9.9 4.77l.2-.6A2 2 0 0 1 12 2.8z"/>',
        'logout'    => '<path d="M9.5 21H6a2.5 2.5 0 0 1-2.5-2.5v-13A2.5 2.5 0 0 1 6 3h3.5"/><path d="M16.2 16.3L20.5 12l-4.3-4.3"/><path d="M20.5 12H9.8"/>',

        // ── Navigation ──────────────────────────────────────────────────
        'home'      => '<path d="M3.5 10.2l8-6.2a1 1 0 0 1 1.2 0l8 6.2"/><path d="M5.4 11.7v7.6a1.7 1.7 0 0 0 1.7 1.7h9.8a1.7 1.7 0 0 0 1.7-1.7v-7.6"/><path d="M9.8 21v-5.3a1 1 0 0 1 1-1h2.4a1 1 0 0 1 1 1V21"/>',
        'calendar'  => '<rect x="3.3" y="5" width="17.4" height="16" rx="2.6"/><path d="M3.3 9.8h17.4"/><path d="M8.3 3v3.6"/><path d="M15.7 3v3.6"/>',
        'chart'     => '<path d="M4 20.2h16.2"/><path d="M6.6 20v-6.6"/><path d="M11.4 20V6.8"/><path d="M16.2 20v-9"/>',
        'message'   => '<path d="M20.6 11.7a8 8 0 0 1-8.6 8 9 9 0 0 1-3.3-.72L3.6 20.4l1.44-4.9a8 8 0 0 1-.72-3.34 8 8 0 0 1 8-8.05 8 8 0 0 1 8.28 7.6z"/>',
        'user'      => '<circle cx="12" cy="8.2" r="3.9"/><path d="M4.6 20.4a7.6 7.6 0 0 1 14.8 0"/>',
        'arrow'     => '<path d="M4.5 12h15"/><path d="M13.6 6.1L19.5 12l-5.9 5.9"/>',
        'chevron'   => '<path d="M9.2 5.6l6.4 6.4-6.4 6.4"/>',

        // ── Health / coaching ───────────────────────────────────────────
        'droplet'   => '<path d="M12 3.1c2.9 2.9 5.7 5.5 5.7 9a5.7 5.7 0 0 1-11.4 0c0-3.5 2.8-6.1 5.7-9z"/>',
        'utensils'  => '<path d="M6.3 3v6.1a2.6 2.6 0 0 0 2.6 2.6v9.3"/><path d="M11.5 3v4.6"/><path d="M17.7 3c-1.5 1.4-2.3 3.4-2.3 5.6 0 1.7.7 2.7 2.3 3.1v9.3"/>',
        'target'    => '<circle cx="12" cy="12" r="8.4"/><circle cx="12" cy="12" r="4.7"/><circle cx="12" cy="12" r="1.2" fill="currentColor" stroke="none"/>',
        'activity'  => '<path d="M3 12.3h3.9l2.3-6.6 4.1 12.6 2.4-6h5.3"/>',
        'flame'     => '<path d="M12 3.2c.6 3.1-1.9 4.3-1.9 7a3.3 3.3 0 0 0 6.5.4c.6 1 .9 2.2.9 3.4a5.5 5.5 0 0 1-11 0c0-4.5 5.5-6.1 5.5-10.8z"/>',

        // ── Status / actions ────────────────────────────────────────────
        'check'     => '<path d="M4.8 12.6l4.6 4.6L19.2 7.4"/>',
        'plus'      => '<path d="M12 5.2v13.6"/><path d="M5.2 12h13.6"/>',
        'minus'     => '<path d="M5.2 12h13.6"/>',
        'close'     => '<path d="M6.4 6.4l11.2 11.2"/><path d="M17.6 6.4L6.4 17.6"/>',
        'clock'     => '<circle cx="12" cy="12" r="8.4"/><path d="M12 7.2V12l3.1 1.9"/>',
        'shield'    => '<path d="M12 3.2l7 2.6v5.5c0 4.2-2.9 7.6-7 9.5-4.1-1.9-7-5.3-7-9.5V5.8z"/><path d="M9.3 12.2l1.9 1.9 3.6-3.6"/>',
        'lock'      => '<rect x="4.8" y="10.6" width="14.4" height="10.2" rx="2.4"/><path d="M8.2 10.6V7.9a3.8 3.8 0 0 1 7.6 0v2.7"/>',
        'star'      => '<path d="M12 3.6l2.6 5.3 5.8.85-4.2 4.1 1 5.78L12 16.9l-5.2 2.73 1-5.78-4.2-4.1 5.8-.85z"/>',
        'card'      => '<rect x="2.8" y="5.4" width="18.4" height="13.2" rx="2.6"/><path d="M2.8 10.1h18.4"/><path d="M6.6 14.8h3.1"/>',
        'file'      => '<path d="M13.6 3.2H7.4a2.2 2.2 0 0 0-2.2 2.2v13.2a2.2 2.2 0 0 0 2.2 2.2h9.2a2.2 2.2 0 0 0 2.2-2.2V8.4z"/><path d="M13.6 3.2v5.2h5.2"/>',

        // ── Portal navigation ───────────────────────────────────────────
        // Plugins name icons in their `customer_nav_items` contributions.
        // Coaching alone asks for clipboard/coffee/folder/gift/mail/percent/
        // users, none of which existed — every one of its nav items was
        // silently falling back to the generic 'file' glyph.
        'clipboard' => '<path d="M9.2 4.4H7.4a2.2 2.2 0 0 0-2.2 2.2v12a2.2 2.2 0 0 0 2.2 2.2h9.2a2.2 2.2 0 0 0 2.2-2.2v-12a2.2 2.2 0 0 0-2.2-2.2h-1.8"/><rect x="9.2" y="2.8" width="5.6" height="3.2" rx="1.1"/>',
        'coffee'    => '<path d="M4.6 8.4h12v6.2a4.6 4.6 0 0 1-4.6 4.6H9.2a4.6 4.6 0 0 1-4.6-4.6z"/><path d="M16.6 9.6h1.3a2.4 2.4 0 0 1 0 4.8h-1.3"/><path d="M7 3.2v2"/><path d="M10.6 3.2v2"/><path d="M14.2 3.2v2"/>',
        'folder'    => '<path d="M3.6 6.6a2 2 0 0 1 2-2h3.2l2 2.4h7.6a2 2 0 0 1 2 2v8.4a2 2 0 0 1-2 2H5.6a2 2 0 0 1-2-2z"/>',
        'gift'      => '<rect x="3.4" y="10.6" width="17.2" height="10.2" rx="1.8"/><path d="M2.6 7.4h18.8v3.2H2.6z"/><path d="M12 7.4v13.4"/><path d="M12 7.4S10.8 3.2 8.6 3.2a2.1 2.1 0 0 0 0 4.2z"/><path d="M12 7.4s1.2-4.2 3.4-4.2a2.1 2.1 0 0 1 0 4.2z"/>',
        'mail'      => '<rect x="3" y="5.4" width="18" height="13.2" rx="2.2"/><path d="M3.6 7l7.3 5.2a2 2 0 0 0 2.2 0L20.4 7"/>',
        'percent'   => '<path d="M19 5L5 19"/><circle cx="7.6" cy="7.6" r="2.6"/><circle cx="16.4" cy="16.4" r="2.6"/>',
        'users'     => '<circle cx="9.4" cy="8.2" r="3.6"/><path d="M2.8 20.2a6.6 6.6 0 0 1 13.2 0"/><path d="M16.2 4.9a3.6 3.6 0 0 1 0 6.9"/><path d="M18 13.6a6.6 6.6 0 0 1 3.2 5.3"/>',
        'tag'       => '<path d="M3.4 11.2V5.2a1.8 1.8 0 0 1 1.8-1.8h6l9.4 9.4a1.7 1.7 0 0 1 0 2.4l-5.8 5.8a1.7 1.7 0 0 1-2.4 0z"/><circle cx="8.2" cy="8.2" r="1.4"/>',
        'qr'        => '<rect x="3.2" y="3.2" width="7" height="7" rx="1.3"/><rect x="13.8" y="3.2" width="7" height="7" rx="1.3"/><rect x="3.2" y="13.8" width="7" height="7" rx="1.3"/><path d="M13.8 13.8h3v3"/><path d="M20.8 13.8v7"/><path d="M16.8 20.8h4"/>',
        'music'     => '<path d="M9.2 18V5.4l10-2v12.2"/><circle cx="6.6" cy="18" r="2.6"/><circle cx="16.6" cy="15.6" r="2.6"/>',
        'menu'      => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',

        // ── Mood / expression (diary mood-tags) ─────────────────────────
        'smile'     => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 14.5c1 1.3 2.3 2 3.8 2s2.8-.7 3.8-2"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
        'frown'     => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 16c1-1.3 2.3-2 3.8-2s2.8.7 3.8 2"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
        'meh'       => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 15h7.6"/><circle cx="9" cy="9.5" r=".6" fill="currentColor" stroke="none"/><circle cx="15" cy="9.5" r=".6" fill="currentColor" stroke="none"/>',
        'angry'     => '<circle cx="12" cy="12" r="9.2"/><path d="M8.2 16c1-1.3 2.3-2 3.8-2s2.8.7 3.8 2"/><path d="M7.6 8.6l3 1.3"/><path d="M16.4 8.6l-3 1.3"/>',
        'worried'   => '<circle cx="12" cy="12" r="9.2"/><path d="M9 15.6a3.2 2 0 0 1 6 0"/><path d="M8 9l2-.9"/><path d="M16 9l-2-.9"/>',
        'moon'      => '<path d="M20.5 14.3A8.6 8.6 0 1 1 9.7 3.5a7 7 0 0 0 10.8 10.8z"/>',
        'sleepy'    => '<circle cx="12" cy="12" r="9.2"/><path d="M6.6 9.4h4.4"/><path d="M13 9.4h4.4"/><path d="M8.2 15.4c1-1 2.3-1.5 3.8-1.5s2.8.5 3.8 1.5"/>',
        'serene'    => '<circle cx="12" cy="12" r="9.2"/><path d="M7 9.6c.7.6 1.5.9 2 .9s1.3-.3 2-.9"/><path d="M13 9.6c.7.6 1.5.9 2 .9s1.3-.3 2-.9"/><path d="M8.2 14.7c1 1 2.3 1.5 3.8 1.5s2.8-.5 3.8-1.5"/>',
        'bulb'      => '<path d="M9 18h6"/><path d="M10 21h4"/><path d="M12 3a6.5 6.5 0 0 0-3.8 11.8c.5.4.8 1 .8 1.7v.5h6v-.5c0-.7.3-1.3.8-1.7A6.5 6.5 0 0 0 12 3z"/>',

        // ── Food / diary meal-type tags ─────────────────────────────────
        'egg'       => '<path d="M12 3.4C8.4 8 6.5 12.4 6.5 15.8a5.5 5.5 0 0 0 11 0C17.5 12.4 15.6 8 12 3.4z"/>',
        'salad'     => '<path d="M3.5 12h17a7.5 6 0 0 1-8.5 6 7.5 6 0 0 1-8.5-6z"/><path d="M12 12c0-3 1.6-5.4 4-6.4-2.6-1-5.4 0-6.4 2.6"/>',
        'plate'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4.6"/>',
        'cookie'    => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="10" r=".9" fill="currentColor" stroke="none"/><circle cx="14.2" cy="8.8" r=".9" fill="currentColor" stroke="none"/><circle cx="15" cy="14" r=".9" fill="currentColor" stroke="none"/><circle cx="10" cy="15.5" r=".9" fill="currentColor" stroke="none"/>',
        'stack'     => '<ellipse cx="12" cy="6" rx="7.5" ry="2.4"/><path d="M4.5 6v6c0 1.3 3.4 2.4 7.5 2.4s7.5-1.1 7.5-2.4V6"/><path d="M4.5 12v6c0 1.3 3.4 2.4 7.5 2.4s7.5-1.1 7.5-2.4v-6"/>',

        // ── Context tags / nav / misc (diary, portal chrome) ────────────
        'briefcase' => '<rect x="2.6" y="7.4" width="18.8" height="12.2" rx="2"/><path d="M8.4 7.4V5.6a2 2 0 0 1 2-2h3.2a2 2 0 0 1 2 2v1.8"/><path d="M2.6 12.4h18.8"/>',
        'bus'       => '<rect x="3.4" y="4.6" width="17.2" height="12" rx="2.2"/><path d="M3.4 13.4h17.2"/><circle cx="7.4" cy="19.4" r="1.6"/><circle cx="16.6" cy="19.4" r="1.6"/>',
        'map-pin'   => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'book'      => '<path d="M4 5.2a2 2 0 0 1 2-2h12.5v16.6H6a2 2 0 0 0-2 2z"/><path d="M4 5.2v14.6"/><path d="M18.5 19.8H6a2 2 0 0 0-2 2"/>',
        'cart'      => '<circle cx="9.5" cy="20" r="1.3"/><circle cx="17.5" cy="20" r="1.3"/><path d="M2.6 3.6h2.5l2.4 12.3h11l2-8.3H6.4"/>',
        'dumbbell'  => '<path d="M4 10v4"/><path d="M2.6 8.8v6.4"/><path d="M20 10v4"/><path d="M21.4 8.8v6.4"/><path d="M8 12h8"/><rect x="5.5" y="9" width="3" height="6" rx="1"/><rect x="15.5" y="9" width="3" height="6" rx="1"/>',
        'camera'    => '<path d="M4 8.4a2 2 0 0 1 2-2h1.7l1.2-1.8h6.2l1.2 1.8H18a2 2 0 0 1 2 2v9.4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.6"/>',
        'trash'     => '<path d="M4.6 7.2h14.8"/><path d="M9 7.2V5.4a1.6 1.6 0 0 1 1.6-1.6h2.8A1.6 1.6 0 0 1 15 5.4v1.8"/><path d="M6.6 7.2l.9 12a2 2 0 0 0 2 1.9h5a2 2 0 0 0 2-1.9l.9-12"/>',
        'award'     => '<circle cx="12" cy="9" r="5.2"/><path d="M8.6 13.4L7.4 21l4.6-2.4 4.6 2.4-1.2-7.6"/>',
        'phone'     => '<path d="M6.6 3.6L9.4 8l-2 2.2a12.8 12.8 0 0 0 6.4 6.4l2.2-2 4.4 2.8v3a1.6 1.6 0 0 1-1.75 1.6A17.6 17.6 0 0 1 3 5.35 1.6 1.6 0 0 1 4.6 3.6z"/>',
    ];
    $d = $paths[$name] ?? $paths['file'];

    // fill/stroke are set as ATTRIBUTES, not left to CSS. A caller that
    // renders an icon without the .icon class (watermarks, decorative marks)
    // would otherwise inherit the UA default fill:black and render as a
    // solid blob — which is exactly what happened to the stat watermarks.
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    return '<svg' . $cls . ' viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/* ═══ Document shell ════════════════════════════════════════════════════ */

/**
 * Opens the portal document. Mirrors the <head> that customer/partials/
 * header.php emits — core UI CSS, brand accent, a11y head, the customer_head
 * hook and queued plugin styles — so a page using this kit gets exactly the
 * same core treatment without inheriting the auth/dashboard chrome.
 */
function slate_portal_head(string $title, string $bodyClass = ''): void {
    $siteName = Database::setting('site_name') ?: 'Kohevo';

    // Mobile browser chrome follows the tenant brand. Can't be a CSS var:
    // meta content is read before the cascade exists.
    $accentRaw  = (string) Database::setting('brand_accent_color');
    $themeColor = preg_match('/^#[0-9a-fA-F]{6}$/', $accentRaw) ? $accentRaw : '#F6F7FA';
    $cls = trim('portal ' . $bodyClass);
    ?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="<?= e($themeColor) ?>">
    <title><?= e($title) ?> — <?= e($siteName) ?></title>
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); ?>
    <?php slate_brand_accent_emit(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <?php slate_portal_emit_css(); ?>
    <?php
    if (class_exists('Hook')) Hook::doAction('customer_head');
    if (class_exists('PluginLoader')) echo PluginLoader::renderQueuedStyles();
    ?>
</head>
<body class="<?= e($cls) ?>">
<a class="skip-link" href="#portal-main"><?= __('skip_to_content', 'Skip to main content') ?></a>
<?php
}

function slate_portal_foot(): void {
    echo "</body>\n</html>\n";
}

/**
 * The single branded topbar. Renders the tenant's own logo when
 * brand_logo_path is set, falling back to an initial mark — and nothing
 * else in the portal renders a brand block, so the logo appears once.
 *
 * $opts:
 *   eyebrow, title  — page identity
 *   search  (bool)  — show the topbar search field
 *   brand_href      — where the brand mark links (default /customer/)
 *   title_in_bar    — render eyebrow/title inside the bar rather than in a
 *                     container below it. App-style surfaces (coaching) want
 *                     this: their body is a narrower mobile-first column, so
 *                     a 1280px title block underneath would not line up.
 */
function slate_portal_topbar(array $opts = []): void {
    static $done = false;
    if ($done) return;                 // one topbar per request, belt and braces
    $done = true;

    $siteName = Database::setting('site_name') ?: 'Kohevo';
    $logoUrls = slate_logo_urls();
    $logoUrl  = $logoUrls['light'];
    $logoDarkUrl = $logoUrls['dark'];
    $cust     = class_exists('Auth') ? Auth::customer() : null;

    $eyebrow    = (string)($opts['eyebrow'] ?? '');
    $title      = (string)($opts['title']   ?? '');
    $search     = (bool)  ($opts['search']  ?? false);
    $titleInBar = (bool)  ($opts['title_in_bar'] ?? false);
    $brandHref  = (string)($opts['brand_href'] ?? (SLATE_URL . '/customer/'));
    ?>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="<?= e($brandHref) ?>">
                <?php /* This topbar's background is always the light --m-surface token -- no dark-mode CSS exists for it, so always the light logo. */ ?>
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
                <?php else: ?>
                    <span class="brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
                    <span><?= e($siteName) ?></span>
                <?php endif; ?>
            </a>

            <?php if ($titleInBar && ($eyebrow !== '' || $title !== '')): ?>
                <div class="topbar-page">
                    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
                    <?php if ($title !== ''): ?><span class="topbar-title"><?= e($title) ?></span><?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($search): ?>
                <form class="search" role="search" method="get">
                    <?= slate_icon('search', 'icon icon-sm') ?>
                    <input type="text" name="q" placeholder="<?= e(__('search', 'Search')) ?>"
                           value="<?= e((string)($_GET['q'] ?? '')) ?>" aria-label="<?= e(__('search', 'Search')) ?>">
                </form>
            <?php else: ?>
                <div class="topbar-spacer"></div>
            <?php endif; ?>

            <div class="topbar-actions">
                <?php if ($cust): ?>
                    <div class="user-chip">
                        <span class="avatar" aria-hidden="true"><?=
                            e(mb_strtoupper(mb_substr((string)($cust['name'] ?? $cust['email'] ?? 'A'), 0, 1)))
                        ?></span>
                        <span>
                            <span class="user-name"><?= e((string)($cust['name'] ?? $cust['email'])) ?></span>
                            <span class="user-sub"><?= e(__('signed_in', 'Signed in')) ?></span>
                        </span>
                    </div>
                    <a class="icon-btn" href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"
                       title="<?= e(__('sign_out', 'Sign out')) ?>" aria-label="<?= e(__('sign_out', 'Sign out')) ?>">
                        <?= slate_icon('logout') ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>
    <?php if (!$titleInBar && ($eyebrow !== '' || $title !== '')): ?>
        <div class="wrap" style="padding-bottom:0">
            <?php if ($eyebrow !== ''): ?><span class="hero-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
            <?php if ($title !== ''): ?><h2 style="margin:0;font-size:22px;letter-spacing:-0.02em;"><?= e($title) ?></h2><?php endif; ?>
        </div>
    <?php endif;
}

/* ═══ Components ════════════════════════════════════════════════════════ */

/** Gradient welcome hero with dot-matrix blueprint mask. */
function slate_portal_hero(string $eyebrow, string $headline, string $name = '', string $sub = ''): void {
    ?>
    <section class="hero">
        <?php if ($eyebrow !== ''): ?><span class="hero-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <h1><?= e($headline) ?><?php if ($name !== ''): ?> <span class="name"><?= e($name) ?></span><?php endif; ?></h1>
        <?php if ($sub !== ''): ?><p><?= e($sub) ?></p><?php endif; ?>
    </section>
    <?php
}

/**
 * Stat card for the overview matrix.
 * $tone: '' | 'green' | 'amber' | 'plum' — tints the icon box only.
 */
function slate_portal_stat(string $label, string $value, string $icon = 'chart',
                           string $tone = '', ?string $delta = null, bool $deltaUp = true): void {
    $boxCls = 'icon-box' . ($tone !== '' ? ' icon-box-' . $tone : '');
    ?>
    <div class="card">
        <div class="watermark"><?= slate_icon($icon, '') ?></div>
        <div class="row between">
            <div>
                <div class="stat-value"><?= e($value) ?></div>
                <div class="stat-label"><?= e($label) ?></div>
                <?php if ($delta !== null): ?>
                    <span class="stat-delta <?= $deltaUp ? 'stat-delta-up' : 'stat-delta-down' ?>">
                        <?= slate_icon($deltaUp ? 'arrow' : 'clock', 'icon icon-sm') ?><?= e($delta) ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="<?= e($boxCls) ?>"><?= slate_icon($icon) ?></span>
        </div>
    </div>
    <?php
}

/**
 * KPI card — the dashboard headline metric.
 *
 * Plugins contribute these through the `customer_dashboard_kpis` filter
 * rather than each rendering its own card, so the row stays visually
 * uniform no matter which plugins are active.
 *
 * $kpi keys:
 *   label  (required) — what is being measured
 *   value  (required) — the figure itself
 *   unit              — trailing qualifier ("days", "left")
 *   meta              — sub-line under the figure; may contain <strong>
 *   icon              — slate_icon() name
 *   tone              — '' | green | amber | blue | plum
 *   href              — makes the whole card a link
 */
function slate_portal_kpi(array $kpi): void {
    $label = (string)($kpi['label'] ?? '');
    $value = (string)($kpi['value'] ?? '—');
    $unit  = (string)($kpi['unit']  ?? '');
    $meta  = (string)($kpi['meta']  ?? '');
    $icon  = (string)($kpi['icon']  ?? 'chart');
    $tone  = (string)($kpi['tone']  ?? '');
    $href  = (string)($kpi['href']  ?? '');

    $cls = 'card kpi' . ($tone !== '' ? ' kpi-' . $tone : '');
    $box = 'icon-box' . ($tone !== '' && $tone !== 'blue' ? ' icon-box-' . $tone : '');

    echo $href !== ''
        ? '<a class="' . e($cls) . '" href="' . e($href) . '">'
        : '<div class="' . e($cls) . '">';
    ?>
        <div class="kpi-top">
            <div>
                <span class="eyebrow"><?= e($label) ?></span>
                <span class="kpi-figure"><?= e($value) ?><?php
                    if ($unit !== '') echo '<span class="kpi-unit">' . e($unit) . '</span>';
                ?></span>
            </div>
            <span class="<?= e($box) ?>"><?= slate_icon($icon) ?></span>
        </div>
        <?php if ($meta !== ''): ?>
            <?php /* meta is trusted plugin markup: allows <strong> emphasis */ ?>
            <div class="kpi-meta"><?= $meta ?></div>
        <?php endif; ?>
    <?php
    echo $href !== '' ? '</a>' : '</div>';
}

/**
 * Radial progress dial.
 *
 * Geometry is fixed by the design spec: viewBox 0 0 100 100, r=42,
 * stroke-width 7, circumference 264 — so dashoffset runs 264 → 0.
 */
function slate_portal_dial(float $pct, string $caption, string $value,
                           string $icon = '', string $tone = ''): void {
    $pct    = max(0.0, min(1.0, $pct));
    $offset = 264 - (264 * $pct);
    $fill   = 'dial-fill' . ($tone !== '' ? ' dial-fill-' . $tone : '');
    ?>
    <div class="dial-item">
        <div class="dial">
            <svg viewBox="0 0 100 100" role="img"
                 aria-label="<?= e($caption . ': ' . $value) ?>">
                <circle class="dial-track" cx="50" cy="50" r="42"/>
                <circle class="<?= e($fill) ?>" cx="50" cy="50" r="42"
                        stroke-dashoffset="<?= e((string)round($offset, 1)) ?>"/>
            </svg>
            <div class="dial-centre">
                <?php if ($icon !== ''): ?><span class="dial-icon"><?= slate_icon($icon, 'icon icon-sm') ?></span><?php endif; ?>
                <span class="dial-value"><?= e($value) ?></span>
            </div>
        </div>
        <div class="dial-caption"><?= e($caption) ?></div>
    </div>
    <?php
}

/** Card opener — pairs with slate_portal_card_close(). */
function slate_portal_card_open(string $eyebrow = '', string $title = '',
                                ?string $actionLabel = null, ?string $actionHref = null,
                                string $extraClass = ''): void {
    echo '<section class="card ' . e($extraClass) . '">';
    if ($eyebrow !== '' || $title !== '' || $actionLabel !== null) {
        echo '<div class="card-head"><div>';
        if ($eyebrow !== '') echo '<span class="eyebrow">' . e($eyebrow) . '</span>';
        if ($title !== '')   echo '<h3 class="card-title">' . e($title) . '</h3>';
        echo '</div>';
        if ($actionLabel !== null && $actionHref !== null) {
            echo '<a class="card-action" href="' . e($actionHref) . '">' . e($actionLabel) . '</a>';
        }
        echo '</div>';
    }
}

function slate_portal_card_close(): void { echo '</section>'; }

/** Empty state block. */
function slate_portal_empty(string $title, string $sub = '', string $icon = 'file'): void {
    ?>
    <div class="empty">
        <span class="icon-box"><?= slate_icon($icon) ?></span>
        <div class="empty-title"><?= e($title) ?></div>
        <?php if ($sub !== ''): ?><div class="empty-sub"><?= e($sub) ?></div><?php endif; ?>
    </div>
    <?php
}

/** Flash / alert banner. $type: 'success' | 'error' | '' */
function slate_portal_alert(string $msg, string $type = ''): void {
    $cls = 'alert' . ($type !== '' ? ' alert-' . $type : '');
    $ico = $type === 'success' ? 'check' : ($type === 'error' ? 'bell' : 'file');
    echo '<div class="' . e($cls) . '" role="status">' . slate_icon($ico) .
         '<span>' . e($msg) . '</span></div>';
}
