<?php
/**
 * Kohevo Studio — the Branding accent in Studio themes (gap report DT-M4).
 *
 * A primary button is text.inverse on surface.accent. With a brand accent, that text is whichever of
 * white or ink reads better on it, as the admin theme already does; a stored text.inverse still wins.
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
    $studioBrandingAccentStandalone = true;
}

use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;

unit('branding accent: a light accent gets dark button text, a dark accent gets white (DT-M4)', function (): void {
    $theme = static fn(string $accent): array => (new ThemeResolver(null, static fn(): array => ['accent' => $accent]))->resolve()->tokens();
    $yellow = $theme('#ffeb3b');
    assert_eq('#ffeb3b', $yellow['surface.accent'], 'the accent is the button fill');
    assert_eq('#15181e', $yellow['text.inverse'], 'and its text is ink, not white');
    assert_eq('#ffffff', $theme('#1d4ed8')['text.inverse'], 'a dark blue keeps white text');
    assert_eq('#15181e', $theme('#fe3')['text.inverse'], 'a short #RGB accent is measured too');
    assert_eq(ThemeResolver::DEFAULT_TOKENS['text.inverse'], $theme('')['text.inverse'], 'no accent: the default');
    assert_eq(ThemeResolver::DEFAULT_TOKENS['text.inverse'], $theme('rgb(255,235,59)')['text.inverse'], 'a non-hex accent is not guessed at');
});

unit('branding accent: Site settings show the derived button text as coming from Branding', function (): void {
    $layers = (new ThemeResolver(null, static fn(): array => ['accent' => '#ffeb3b']))->layers();
    assert_eq('#15181e', $layers['branding']['text.inverse'] ?? null, 'the branding layer carries it');
});

unit('branding accent: the same measure as the admin theme', function (): void {
    require_once SLATE_ROOT . '/includes/brand_tokens.php';
    foreach (['#ffeb3b', '#1d4ed8', '#e4572e', '#777777', '#00ff00', '#222222', '#f0f0f0'] as $hex) {
        $admin = strtolower(slate_brand_accent_tokens($hex)['on_accent']);
        assert_eq($admin, ThemeResolver::onAccent($hex), "{$hex} gets the admin's button text colour");
    }
});

if (!empty($studioBrandingAccentStandalone)) {
    exit(unit_summary());
}
