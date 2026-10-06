<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Phase 2 interaction runtime.
 *
 * Autoloader only, no database. Phase 2 ships `assets/public/studio-runtime.js`
 * as a closed, same-origin bundle and wires it into every render EXCEPT the
 * builder canvas. The properties worth pinning:
 *
 *   - the canvas NEVER gets a <script> (its CSP is `script-src 'none'`)
 *   - preview and public DO get it (a runtime that only ran live would make
 *     preview a lie)
 *   - the emitted tag is a plain external file — no inline script, no on* handler
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Render\Compile\CompiledPage;
use Slate\Module\StudioBuilder\Render\Compile\FilledPage;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeAsset;

const SBP2R_ROOT = __DIR__ . '/../..';

function sbp2r_assemble(RenderMode $mode): string
{
    $assembler = new PageDocumentAssembler(static fn(): string => 'Powered by Kohevo');
    $site = new SiteContext('https://example.test', 'Acme Studio');

    $context = match ($mode) {
        RenderMode::Public  => RenderContext::forPublic(101, $site),
        RenderMode::Preview => RenderContext::forPreview(101, $site, StudioActor::authenticated(3, ['studio-builder.view'])),
        default             => RenderContext::forEditor(101, $site, StudioActor::authenticated(3, ['studio-builder.edit'])),
    };

    $compiled = new CompiledPage(101, 9, 'public', '', '', [], ['seo' => []], 'hash', 'test');

    return $assembler->assemble($compiled, new FilledPage('<main class="sb-main"></main>', ''), $context);
}

// ── The asset itself ────────────────────────────────────────────────────

unit('phase2: the runtime asset is deployed and resolvable', function (): void {
    StudioRuntimeAsset::resetCache();
    assert_true(StudioRuntimeAsset::exists(), 'assets/public/studio-runtime.js must ship');
    assert_true(StudioRuntimeAsset::url() !== null, 'url must resolve');
    assert_true(StudioRuntimeAsset::version() !== null, 'version must resolve');
});

unit('phase2: the script tag is an external deferred file, never inline', function (): void {
    StudioRuntimeAsset::resetCache();
    $tag = StudioRuntimeAsset::scriptTag();
    assert_true(str_contains($tag, '<script src='), 'must be an external src: ' . $tag);
    assert_true(str_contains($tag, 'defer'), 'must be deferred: ' . $tag);
    assert_true(str_contains($tag, '?v='), 'must be cache-busted: ' . $tag);
    // The reason a strict CSP could never reject this page.
    assert_false(preg_match('/\son[a-z]+=/i', $tag) === 1, 'no inline event handler: ' . $tag);
    assert_false(str_contains($tag, '<script>'), 'no inline body: ' . $tag);
});

unit('phase2: the version is content-derived, so an edit changes it', function (): void {
    StudioRuntimeAsset::resetCache();
    $first = StudioRuntimeAsset::version();
    assert_true(is_string($first) && $first !== '', 'version must be a non-empty token');
    assert_true(preg_match('/^[a-f0-9]+$/', $first) === 1, 'expected a hex digest prefix, got: ' . $first);
    StudioRuntimeAsset::resetCache();
    assert_eq($first, StudioRuntimeAsset::version(), 'must be stable for identical content');
});
// ── Emission per mode ───────────────────────────────────────────────────

unit('phase2: the public page emits the runtime', function (): void {
    $html = sbp2r_assemble(RenderMode::Public);
    assert_true(str_contains($html, 'assets/public/studio-runtime.js'), 'public must load the runtime');
});

unit('phase2: preview emits the runtime, so preview matches production', function (): void {
    $html = sbp2r_assemble(RenderMode::Preview);
    assert_true(str_contains($html, 'assets/public/studio-runtime.js'), 'preview must load the runtime');
});

unit('phase2: the editor canvas NEVER emits a script', function (): void {
    // The load-bearing assertion of the whole phase. StudioCanvasPolicy pins the
    // canvas to `script-src 'none'`; a <script> here would be dead AND would
    // contradict the header sent with it.
    $html = sbp2r_assemble(RenderMode::Editor);
    assert_false(str_contains($html, 'studio-runtime.js'), 'canvas must not load the runtime: ' . $html);
    assert_false(str_contains($html, '<script'), 'canvas must contain no script at all: ' . $html);
});

unit('phase2: the runtime tag sits inside <head>', function (): void {
    $html = sbp2r_assemble(RenderMode::Public);
    $headEnd = strpos($html, '</head>');
    $scriptAt = strpos($html, 'studio-runtime.js');
    assert_true($headEnd !== false && $scriptAt !== false, 'both markers must exist');
    assert_true($scriptAt < $headEnd, 'the runtime tag must be inside <head>');
});

// ── The stylesheet the runtime needs ─────────────────────────────────────

unit('phase2: the stylesheet ships rules for every runtime pattern', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    foreach (['sb-tabs', 'sb-accordion', 'sb-carousel', 'sb-lightbox', 'sb-scroll-lock', 'sb-carousel__track'] as $selector) {
        assert_true(str_contains($css, $selector), 'stylesheet must define ' . $selector);
    }
});

unit('phase2: runtime styles keep the reduced-motion contract', function (): void {
    // The runtime animates the carousel track and rotates the accordion icon;
    // both must be neutralised for visitors who ask for less motion.
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(
        substr_count($css, 'prefers-reduced-motion:reduce') >= 2,
        'reduced-motion must cover both the motion presets and the runtime widgets'
    );
    assert_true(
        str_contains($css, '.sb-carousel__track{transition:none!important}'),
        'the carousel track must stop moving under reduced motion'
    );
});

unit('phase2: scroll lock targets <html> only', function (): void {
    // Locking <body> as well causes a visible jump on iOS Safari.
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    assert_true(str_contains($css, 'html.sb-scroll-lock{overflow:hidden}'), 'must lock via <html>');
    assert_false(str_contains($css, 'body.sb-scroll-lock'), 'must not lock <body> as well');
});

// ── The CSS ↔ runtime contract ───────────────────────────────────────────
// Regression guard. The runtime marks an open overlay with the `data-sb-open`
// attribute, while the stylesheet originally revealed overlays with an `.is-open`
// class. Nothing threw, no test failed, and the modal simply never appeared —
// the two halves disagreed on a selector name. Any future rename must move BOTH
// sides together, so the agreement is asserted from both ends here.

unit('phase2: overlay open state is revealed by the selector the runtime sets', function (): void {
    StudioStylesheet::resetCache();
    $css = StudioStylesheet::css();
    $src = (string) file_get_contents(SBP2R_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');

    assert_true(
        str_contains($src, "el.setAttribute('data-sb-open', '')"),
        'the runtime must mark an open overlay with data-sb-open'
    );

    foreach (['sb-modal', 'sb-offcanvas', 'sb-lightbox'] as $kind) {
        assert_true(
            str_contains($css, '.' . $kind . ':target,.' . $kind . '[data-sb-open]'),
            'stylesheet must reveal .' . $kind . ' on [data-sb-open]'
        );
        assert_false(
            str_contains($css, '.' . $kind . '[data-sb-open].is-open'),
            'stylesheet must not require both markers on .' . $kind
        );
    }
});

unit('phase2: the runtime sets and clears the class the stylesheet locks on', function (): void {
    // Same class of bug: the stylesheet locks scroll via `html.sb-scroll-lock`,
    // so the runtime has to both set it and take it back off, or the page is
    // left permanently unscrollable after the last overlay closes.
    $src = (string) file_get_contents(SBP2R_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(
        str_contains($src, "documentElement.classList.add('sb-scroll-lock')"),
        'the runtime must lock scroll with the class the stylesheet defines'
    );
    assert_true(
        str_contains($src, "documentElement.classList.remove('sb-scroll-lock')"),
        'the runtime must release the scroll lock'
    );
});

// ── The runtime's own reachability ──────────────────────────────────────

unit('phase2: the runtime enhances the blocks that exist today', function (): void {
    // No renderer change is needed for these: the runtime matches what
    // ModalRenderer / OffcanvasRenderer already emit.
    $src = (string) file_get_contents(SBP2R_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true($src !== '', 'the runtime asset must be readable');
    assert_true(str_contains($src, '.sb-modal'), 'must enhance core.modal by class');
    assert_true(str_contains($src, '.sb-offcanvas'), 'must enhance layout.offcanvas by class');
    assert_true(str_contains($src, '.sb-gallery__item'), 'must build a lightbox for core.gallery');
});

unit('phase2: the runtime degrades cleanly when disabled', function (): void {
    // The A/B contract from the prototype: `disable()` must undo every DOM
    // mutation, which is what makes the behaviour safe to ship.
    $src = (string) file_get_contents(SBP2R_ROOT . '/plugins/studio-builder/assets/public/studio-runtime.js');
    assert_true(str_contains($src, 'disable: function'), 'must expose disable()');
    assert_true(str_contains($src, 'teardownAll'), 'must have a teardown registry');
});