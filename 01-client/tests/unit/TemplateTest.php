<?php
/**
 * Unit tests for Phase 3B B3 — Template frame, content-type-aware selection, and
 * PageAssembler (Page → full document / bare fragment).
 * docs/05-Rendering/theme-and-template-engine.md, rendering-pipeline.md §4/§8.
 */

declare(strict_types=1);

use Slate\Presentation\Page;
use Slate\Presentation\Section;
use Slate\Presentation\LayoutSpec;
use Slate\Presentation\RenderContext;
use Slate\Presentation\FieldSchema;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Rendering\PageAssembler;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\CallbackBlock;
use Slate\Presentation\Templates\RegionContent;
use Slate\Presentation\Templates\Template;
use Slate\Presentation\Templates\DocumentTemplate;
use Slate\Presentation\Templates\TemplateResolver;
use Slate\Presentation\Theme\TenantThemeResolver;

/** A named no-op template for resolver precedence tests. */
function _tpl(string $name): Template {
    return new class($name) implements Template {
        public function __construct(private string $n) {}
        public function name(): string { return $this->n; }
        public function regions(): array { return ['content']; }
        public function render(RegionContent $r, RenderContext $c): string { return "[{$this->n}]" . $r->get('content'); }
    };
}

// ── RegionContent ─────────────────────────────────────────
unit('RegionContent.with is immutable; get/has behave', function () {
    $a = new RegionContent(['content' => 'X']);
    $b = $a->with('header', 'H');
    assert_false($a->has('header'));         // original untouched
    assert_true($b->has('header'));
    assert_eq('H', $b->get('header'));
    assert_eq('', $b->get('missing'));
});

// ── DocumentTemplate ──────────────────────────────────────
unit('DocumentTemplate emits a full document with tokens + regions in place', function () {
    $regions = (new RegionContent())
        ->with('head', '<title>Hi</title>')
        ->with('header', '<nav>H</nav>')
        ->with('content', '<h1>Body</h1>')
        ->with('footer', '<footer>F</footer>');
    $html = (new DocumentTemplate())->render($regions, RenderContext::for(1));

    assert_true(str_starts_with($html, '<!doctype html>'));
    assert_true(str_contains($html, '<style id="slate-tokens">'), 'one token block in head');
    assert_true(str_contains($html, '<title>Hi</title>'));
    assert_true(str_contains($html, '<main class="slate-content cb-public"><h1>Body</h1></main>'));
    assert_true(str_contains($html, '<nav>H</nav><main'), 'header before content');
    assert_true(str_contains($html, '</main><footer>F</footer>'), 'footer after content');
});

unit('DocumentTemplate folds the RenderContext Theme tokens into the head', function () {
    $ctx = RenderContext::for(1)->withTheme(TenantThemeResolver::fromBrandAccent('#0e7490'));
    $html = (new DocumentTemplate())->render(new RegionContent(), $ctx);
    assert_true(str_contains($html, '--slate-color-accent:#0E7490;'), 'themed accent in head');
});

// ── TemplateResolver precedence ───────────────────────────
unit('resolver honors precedence: page override > content-type > surface > fallback', function () {
    $r = (new TemplateResolver())
        ->register(_tpl('document'))
        ->register(_tpl('storefront'))
        ->register(_tpl('landing'))
        ->register(_tpl('embed-bare'))
        ->mapContentType('product', 'storefront')
        ->mapSurface(RenderContext::SURFACE_WIDGET, 'embed-bare')
        ->setFallback('document');

    // 1: explicit page override wins over everything.
    $p = new Page('product', 'landing', []);
    assert_eq('landing', $r->resolve($p, RenderContext::for(1))->name());

    // 2: content-type default when no page override.
    $p = new Page('product', '', []);
    assert_eq('storefront', $r->resolve($p, RenderContext::for(1))->name());

    // 3: surface default when type is unmapped.
    $p = new Page('page', '', []);
    $widget = RenderContext::for(1)->withSurface(RenderContext::SURFACE_WIDGET);
    assert_eq('embed-bare', $r->resolve($p, $widget)->name());

    // 4: platform fallback otherwise.
    assert_eq('document', $r->resolve(new Page('page', '', []), RenderContext::for(1))->name());

    // Unknown explicit name is skipped, not returned.
    assert_eq('document', $r->resolve(new Page('page', 'nope', []), RenderContext::for(1))->name());
});

// ── PageAssembler ─────────────────────────────────────────
function _assembler(): PageAssembler {
    $reg = new InMemoryBlockRegistry();
    $reg->register(new CallbackBlock('heading',
        FieldSchema::of([], ['text' => '', 'level' => '2']),
        fn (array $p, RenderContext $c) => "<h{$p['level']}>{$p['text']}</h{$p['level']}>"));
    $templates = (new TemplateResolver())->register(new DocumentTemplate())->setFallback('document');
    return new PageAssembler(new PageRenderer($reg), $templates);
}

function _page(): Page {
    return new Page('page', '', [
        new Section('s1', LayoutSpec::default(), [['type' => 'heading', 'props' => ['text' => 'Hello', 'level' => '1']]]),
    ]);
}

unit('PageAssembler wraps content in the full document for a page surface', function () {
    $html = _assembler()->assemble(_page(), RenderContext::for(1));
    assert_true(str_starts_with($html, '<!doctype html>'));
    assert_true(str_contains($html, '<main class="slate-content cb-public"><h1>Hello</h1></main>'));
});

unit('PageAssembler returns bare content (no frame) for a fragment surface', function () {
    $ctx = RenderContext::for(1)->withSurface(RenderContext::SURFACE_FRAGMENT);
    $html = _assembler()->assemble(_page(), $ctx);
    assert_eq('<h1>Hello</h1>', $html);
    assert_false(str_contains($html, '<!doctype'));
});

unit('PageAssembler falls back to content-only when no template resolves', function () {
    $reg = new InMemoryBlockRegistry();
    $reg->register(new CallbackBlock('heading', FieldSchema::of([], ['text' => '', 'level' => '2']),
        fn (array $p, RenderContext $c) => "<h{$p['level']}>{$p['text']}</h{$p['level']}>"));
    // Resolver with nothing registered → resolve() returns null.
    $assembler = new PageAssembler(new PageRenderer($reg), new TemplateResolver());
    $html = $assembler->assemble(_page(), RenderContext::for(1));
    assert_eq('<h1>Hello</h1>', $html);
});
