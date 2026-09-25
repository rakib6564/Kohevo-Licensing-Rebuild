<?php
/**
 * Phase 3B B5 — end-to-end render-stack proof (no DB, no production wiring).
 *
 * Composes the whole 3B stack into one document to prove it fits together:
 *   Page → Section → Block → Component → Tokens, framed by a Template, skinned by
 *   a Theme (docs/05-Rendering/rendering-pipeline.md). Asserts a well-formed
 *   document, the token block emitted exactly once, the tenant accent applied, and
 *   a Block that COMPOSES Components (the ADR-0007 layering) rendering correctly.
 *
 * This is the capstone check; nothing here is wired into the live render path.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/components/load.php';

use Slate\Presentation\Page;
use Slate\Presentation\Section;
use Slate\Presentation\LayoutSpec;
use Slate\Presentation\FieldSchema;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Rendering\PageAssembler;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\CallbackBlock;
use Slate\Presentation\Templates\DocumentTemplate;
use Slate\Presentation\Templates\TemplateResolver;
use Slate\Presentation\Templates\RegionContent;
use Slate\Presentation\Theme\TenantThemeResolver;

unit('the full stack renders a themed document: Page→Section→Block→Component→Tokens', function () {
    // A Block that COMPOSES Components (not raw markup) — the ADR-0007 rule.
    $registry = new InMemoryBlockRegistry();
    $registry->register(new CallbackBlock(
        'promo',
        FieldSchema::of([], ['heading' => '', 'cta' => '', 'href' => '#']),
        fn (array $p, RenderContext $c): string => slate_c_card([
            'title' => $p['heading'],
            'body'  => slate_c_button(['label' => $p['cta'], 'href' => $p['href'], 'tone' => 'accent']),
        ])
    ));

    $assembler = new PageAssembler(
        new PageRenderer($registry),
        (new TemplateResolver())->register(new DocumentTemplate())->setFallback('document')
    );

    $page = new Page('page', '', [
        new Section('s1', LayoutSpec::default(), [
            ['type' => 'promo', 'props' => ['heading' => 'Join us', 'cta' => 'Sign up', 'href' => '/p/signup']],
        ]),
    ]);

    // Tenant brand color → Theme → skins the document.
    $ctx = RenderContext::for(1)->withTheme(TenantThemeResolver::fromBrandAccent('#0e7490'));
    $head = (new RegionContent())->with('head', '<title>Home</title>');

    $html = $assembler->assemble($page, $ctx, $head);

    // Well-formed document.
    assert_true(str_starts_with($html, '<!doctype html>'));
    assert_true(str_contains($html, '<title>Home</title>'));

    // Token block emitted EXACTLY once (design-tokens.md §6).
    assert_eq(1, substr_count($html, '<style id="slate-tokens">'), 'one and only one token block');

    // Theme applied.
    assert_true(str_contains($html, '--slate-color-accent:#0E7490;'), 'tenant accent in head');

    // Block composed Components, inside the content region.
    assert_true(str_contains($html, '<main class="slate-content cb-public"><article class="slate-card'), 'card in content');
    assert_true(str_contains($html, '<a class="slate-btn slate-btn--accent slate-btn--md" href="/p/signup"'), 'button composed');
    assert_true(str_contains($html, '<h3 class="slate-card__title">Join us</h3>'));
    assert_true(str_contains($html, '>Sign up</a>'));
});

unit('the same page as an API fragment is bare content — no frame, no token block', function () {
    $registry = new InMemoryBlockRegistry();
    $registry->register(new CallbackBlock('promo',
        FieldSchema::of([], ['cta' => '', 'href' => '#']),
        fn (array $p, RenderContext $c): string => slate_c_button(['label' => $p['cta'], 'href' => $p['href']])));

    $assembler = new PageAssembler(new PageRenderer($registry),
        (new TemplateResolver())->register(new DocumentTemplate())->setFallback('document'));

    $page = new Page('page', '', [
        new Section('s1', LayoutSpec::default(), [['type' => 'promo', 'props' => ['cta' => 'Go', 'href' => '/x']]]),
    ]);
    $ctx = RenderContext::for(1)->withSurface(RenderContext::SURFACE_FRAGMENT);

    $html = $assembler->assemble($page, $ctx);
    assert_eq('<a class="slate-btn slate-btn--accent slate-btn--md" href="/x">Go</a>', $html);
    assert_false(str_contains($html, '<!doctype'));
    assert_false(str_contains($html, 'slate-tokens'));
});
