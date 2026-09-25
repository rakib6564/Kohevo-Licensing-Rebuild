<?php
/**
 * The SBK golden lane — the gate for sb.css's 211 consumers.
 *
 * Built the same way SbkFontDeliveryTest loads SBK: REQUIRE the classes, do not
 * activate the plugin. Activating it would change what every other golden in the
 * suite renders, which is the 2b mistake in a new costume — a gate that damages
 * the thing it is meant to measure.
 *
 * The document deliberately includes sb-cta-band and sb-page-hero. Those are the
 * two blocks whose roots do NOT carry `.sb` today, and the next commit adds it.
 * That change is appearance-sensitive — any existing `.sb`-scoped rule in sb.css
 * would newly match them — so they have to be inside this golden BEFORE they are
 * touched, or the normalisation lands ungated.
 *
 * The theme is pinned to editorial-bold: it uses the $bold typography preset, so
 * the golden carries the 750 weight and .08em tracking that broke the drafted
 * primitive scale. The value most likely to be quietly rounded is the one worth
 * having in the fixture.
 */

declare(strict_types=1);

const SLATE_SBK_TEST_THEME = 'editorial-bold';

/** Load SBK without activating it, and register its blocks once. */
function slate_sbk_boot(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;

    $dir = dirname(__DIR__, 3) . '/plugins/small-business-kit';
    if (!is_file($dir . '/SBKitAPI.php')) return ($ready = false);

    foreach (['SBKitAPI.php', 'lib/Themes.php', 'lib/Blocks.php',
              'lib/Templates.php', 'lib/Header.php', 'lib/Footer.php'] as $f) {
        if (is_file($dir . '/' . $f)) require_once $dir . '/' . $f;
    }
    if (!class_exists('SBKBlocks') || !class_exists('BlockRegistry')) return ($ready = false);

    SBKBlocks::registerAll('BlockRegistry');
    return ($ready = true);
}

/** A document exercising the blocks whose roots the normalisation touches. */
function slate_sbk_source(): string
{
    return (string) json_encode([
        'schema' => 1,
        'type'   => 'page',
        'sections' => [[
            'id' => 's1',
            'layout' => ['kind' => 'default'],
            'blocks' => [
                ['type' => 'sb-hero',      'props' => ['eyebrow' => 'Gate', 'align' => 'left']],
                ['type' => 'sb-split',     'props' => []],
                ['type' => 'sb-cta-band',  'props' => []],   // root lacks .sb today
                ['type' => 'sb-page-hero', 'props' => []],   // root lacks .sb today
            ],
        ]],
    ]);
}

/** Render the SBK document through the site template, theme pinned. */
function slate_render_sbk_document(string $json): string
{
    if (!slate_sbk_boot()) return '';

    $priorTheme = SBKitAPI::activeTheme();
    $priorBrand = [];
    foreach (slate_site_brand_settings() as $k => $v) {
        $priorBrand[$k] = ContentBuilderAPI::getSiteSetting($k, null);
        ContentBuilderAPI::setSiteSetting($k, (string) $v);
    }
    SBKitAPI::setActiveTheme(SLATE_SBK_TEST_THEME);

    try {
        $post = [
            'id'     => 0,
            'type'   => 'page',
            'title'  => 'SBK gate',
            'status' => 'published',
            'layout' => json_decode($json, true)['sections'][0]['blocks'],
        ];

        // headInjection is appended directly rather than through the
        // content_head_tags filter: registering the filter would leak SBK's head
        // into every other golden rendered in the same process.
        $head = (string) Hook::applyFilters('content_head_tags', '<title>SBK gate</title>', $post)
              . SBKitAPI::headInjection($post);

        $body = '<main class="cb-page">' . ContentCoreBridge::renderLayoutForPublic($post['layout']) . '</main>';

        $doc = ContentCoreBridge::renderDocumentForPublic($post, $head, $body);
        slate_record_document_engine($doc['served']);
        return $doc['html'];
    } finally {
        SBKitAPI::setActiveTheme($priorTheme);
        foreach ($priorBrand as $k => $v) {
            ContentBuilderAPI::setSiteSetting($k, (string) ($v ?? ''));
        }
    }
}
