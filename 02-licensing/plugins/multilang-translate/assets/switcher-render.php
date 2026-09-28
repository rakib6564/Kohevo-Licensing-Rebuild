<?php
/**
 * Included by MultilangTranslate::switcherHtml() via `require` (not
 * `include`+ob_start — see the long comment on that method for why).
 * MUST return a string, never echo — its return value IS the widget HTML.
 * $this is in scope (required from inside a Plugin method).
 */

$tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;
$langs = MLT_LangRepo::enabled($tid);
if (count($langs) < 2) return ''; // nothing to switch between

$active = MLT_I18nBridge::activeLocale($tid);

$style    = $this->setting('switcher_style', 'dropdown');   // dropdown | flags | list
$position = $this->setting('switcher_position', 'bottom-right');
// 'header' has no floating CSS rule (mlt-pos-header doesn't exist) — it only
// means something to the admin-topbar/customer-portal-bar hooks. If this
// floating widget is still being rendered while that's selected, it's on a
// page with no header-bar slot (public site, embeds, login screens, etc.),
// so fall back to a sane floating corner instead of an unpositioned widget.
if ($position === 'header') $position = 'bottom-right';
$bg       = $this->setting('switcher_bg', '#111827');
$fg       = $this->setting('switcher_fg', '#ffffff');
$accent   = $this->setting('switcher_accent', '#111111');

$baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
$qs = $_GET ?? [];
unset($qs['lang']); // core I18n's param — see MLT_I18nBridge::activeLocale()

if (!function_exists('mlt_switch_url')) {
    function mlt_switch_url(string $base, array $qs, string $code): string {
        $qs['lang'] = $code;
        return $base . '?' . http_build_query($qs);
    }
}

$activeLang = null;
foreach ($langs as $l) if ($l['code'] === $active) $activeLang = $l;

// No flag on record (custom/unrecognised code) -> a plain stroke globe glyph,
// not the emoji this switcher used to fall back to.
$activeFlagRaw  = $activeLang['flag'] ?? '';
$activeFlagHtml = $activeFlagRaw !== ''
    ? e($activeFlagRaw)
    : '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18"/><path d="M12 3a15 15 0 0 0 0 18"/></svg>';

$menuItems = '';
foreach ($langs as $l) {
    $menuItems .= '<li role="none">'
        . '<a role="menuitem" href="' . e(mlt_switch_url($baseUrl, $qs, $l['code'])) . '"'
        . ' class="' . ($l['code'] === $active ? 'is-active' : '') . '">'
        . '<span class="mlt-flag">' . e($l['flag']) . '</span>'
        . '<span>' . e($l['native_name'] ?: $l['name']) . '</span>'
        . '</a></li>';
}

return
    '<div class="mlt-switcher mlt-pos-' . e($position) . ' mlt-style-' . e($style) . '"'
    . ' style="--mlt-bg: ' . e($bg) . '; --mlt-fg: ' . e($fg) . '; --mlt-accent: ' . e($accent) . ';"'
    . ' data-active="' . e($active) . '">'
    . '<button type="button" class="mlt-switcher-toggle" aria-haspopup="true" aria-expanded="false">'
    . '<span class="mlt-flag">' . $activeFlagHtml . '</span>'
    . '<span class="mlt-code">' . e(strtoupper($active)) . '</span>'
    . '<svg width="10" height="10" viewBox="0 0 10 6" aria-hidden="true"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none"/></svg>'
    . '</button>'
    . '<ul class="mlt-switcher-menu" role="menu">' . $menuItems . '</ul>'
    . '</div>';
