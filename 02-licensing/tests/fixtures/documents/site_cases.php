<?php
/**
 * The site-document golden lane — the gate Phase E step 2 actually needs.
 *
 * The envelope lane renders DocumentTemplate, which emits the token block and
 * the regions. It does NOT inline the stylesheets that CONSUME those tokens, so
 * it cannot see a consumer being renamed. That was demonstrated rather than
 * assumed: rewriting 14 `var(--cb-ink)` sites in public.css — a change that
 * moves the text colour on every branded tenant — produced no golden diff and a
 * fully green suite.
 *
 * Theme::renderPage() inlines Branding::cssVars() and the whole of public.css,
 * so a document rendered through it carries all 103 `var(--cb-*)` consumers and
 * a rename shows up as a byte diff. That is the path this lane renders.
 *
 * Determinism: Branding reads seven site settings, so the fixture pins them and
 * restores them. A golden that depends on whatever the database happened to hold
 * is not a golden — the same reason the document goldens are stored base-neutral.
 */

declare(strict_types=1);

/**
 * Which engine served the last golden render.
 *
 * The byte-golden alone cannot say. renderDocumentForPublic() falls back to the
 * legacy frame whenever the core path diverges, so a batch that broke parity
 * would fail the golden with a large mysterious diff rather than the actual
 * cause. Recording the engine turns that into a legible failure — and it is the
 * same reachability lesson as served === 'core' on the live route: correct-
 * looking output either way.
 */
function slate_record_document_engine(string $served): void
{
    $GLOBALS['__slate_last_document_engine'] = $served;
}

function slate_last_document_engine(): string
{
    return (string) ($GLOBALS['__slate_last_document_engine'] ?? '');
}

/**
 * A tenant that differs from the --slate-* defaults on every token class step 2
 * migrates, so no class can move without this golden moving.
 */
function slate_site_brand_settings(): array
{
    return [
        'palette'      => '',            // no preset, so accent_color below wins
        'accent_color' => '#8A3324',     // not the default blue
        'theme'        => 'light',
        'font_pairing' => '',
        'radius'       => '2',           // not the default
        'btn_shape'    => 'square',
        'type_scale'   => 'l',           // 1.12, not the default 1.0
    ];
}

/** Render a document through the SITE template, under pinned brand settings. */
function slate_render_site_document(string $json): string
{
    $prior = [];
    foreach (slate_site_brand_settings() as $k => $v) {
        $prior[$k] = ContentBuilderAPI::getSiteSetting($k, null);
        ContentBuilderAPI::setSiteSetting($k, (string) $v);
    }

    try {
        $post = [
            'id'     => 0,               // 0 so no per-page meta lookup is needed
            'type'   => 'page',
            'title'  => 'Brand gate',
            'status' => 'published',
            'layout' => json_decode($json, true)['sections'][0]['blocks'] ?? [],
        ];

        $head = (string) Hook::applyFilters('content_head_tags', '<title>Brand gate</title>', $post);
        $body = '<main class="cb-page">' . ContentCoreBridge::renderLayoutForPublic($post['layout']) . '</main>';

        $doc = ContentCoreBridge::renderDocumentForPublic($post, $head, $body);
        slate_record_document_engine($doc['served']);
        return $doc['html'];
    } finally {
        foreach ($prior as $k => $v) {
            ContentBuilderAPI::setSiteSetting($k, (string) ($v ?? ''));
        }
    }
}

/** The one fixture this lane renders. */
function slate_site_source(): string
{
    return (string) file_get_contents(__DIR__ . '/envelope-basic.json');
}
