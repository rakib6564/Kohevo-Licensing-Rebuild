<?php
/**
 * The document-envelope golden cases.
 *
 * Every other golden in this directory is BODY-ONLY: it goes through
 * ContentCoreBridge::render(), which stops at PageRenderer. None of them contains
 * a doctype, so none of them observes the <head> — and the token block lives in
 * the head. That gap is why the color-scheme divergence between
 * CoreBridge::tokenHeadCss() (false, deliberate and documented) and
 * DocumentTemplate (TokenEmitter's default of true) could sit in the tree
 * unnoticed: no checked-in expectation could see it.
 *
 * These cases render the full envelope instead, under two explicit contexts, so
 * both halves of the contract are pinned:
 *
 *   unthemed → no theme tokens, and NO color-scheme declaration (inert)
 *   themed   → the tenant accent in the head, and color-scheme opted into
 *
 * The generator and the test both read this file, so the contexts they compare
 * cannot drift apart.
 *
 * The accent is a literal, not `brand_accent_color`: a golden must not depend on
 * what the generating install happens to have stored — the same reasoning that
 * makes these goldens base-neutral.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Rendering\PageAssembler;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Templates\DocumentTemplate;
use Slate\Presentation\Templates\TemplateResolver;
use Slate\Presentation\Theme\ArrayTheme;
use Slate\Presentation\Theme\TenantThemeResolver;

/** The accent the themed case brands with. Arbitrary, but fixed. */
const SLATE_ENVELOPE_TEST_ACCENT = '#0E7490';

/**
 * A tenant that differs from the --slate-* defaults on EVERY token class Phase E
 * step 2 migrates — not just colour.
 *
 * The themed case above overrides the accent only, which is why it could not
 * catch the thing step 2 actually risks. `--cb-ink` is tenant-fed while
 * `--slate-color-text` is a fixed neutral-900, so renaming a consumer from one to
 * the other changes the colour on every branded site — and a fixture that only
 * varies the accent renders that change as a zero-byte diff.
 *
 * Every value here is deliberately NOT a slate default:
 *   text     #0f172a → #2B1B12    surface  #ffffff → #FFFDF7
 *   canvas   #f8fafc → #F3EAD8    radius   8px     → 2px
 *   heading weight  500 → 750     tracking -.015em → .04em
 *
 * The two typographic rows matter as much as the colours: 750 is the value that
 * broke the drafted primitive scale, so the fixture that guards the migration
 * should carry it.
 */
const SLATE_ENVELOPE_BRAND = [
    'slate-color-text'          => '#2B1B12',
    'slate-color-surface'       => '#FFFDF7',
    'slate-color-canvas'        => '#F3EAD8',
    'slate-color-text-muted'    => '#7A665C',
    'slate-radius-md'           => '2px',
    'slate-font-weight-heading' => '750',
    'slate-tracking-heading'    => '.04em',
];

/** @return array<string, RenderContext> case name => the context it renders under */
function slate_envelope_cases(): array
{
    return [
        'unthemed' => RenderContext::for(1),
        'themed'   => RenderContext::for(1)
            ->withTheme(TenantThemeResolver::fromBrandAccent(SLATE_ENVELOPE_TEST_ACCENT))
            ->withColorScheme(true),
        // The gate for step 2. Without a tenant that differs from the defaults,
        // a batch that silently changed every branded site's text colour would
        // produce a zero-byte diff and pass review.
        'branded'  => RenderContext::for(1)
            ->withTheme(new ArrayTheme(tokens: SLATE_ENVELOPE_BRAND))
            ->withColorScheme(true),
    ];
}

/** The one fixture the envelope goldens are built from. */
function slate_envelope_source(): string
{
    return (string) file_get_contents(__DIR__ . '/envelope-basic.json');
}

/** Document JSON + context → the full HTML document. */
function slate_render_envelope(string $json, RenderContext $ctx): string
{
    $assembler = new PageAssembler(
        new PageRenderer(ContentCoreBridge::coreRegistry()),
        (new TemplateResolver())->register(new DocumentTemplate())->setFallback('document'),
    );

    return $assembler->assemble(DocumentSchema::toPage($json, 'page'), $ctx);
}
