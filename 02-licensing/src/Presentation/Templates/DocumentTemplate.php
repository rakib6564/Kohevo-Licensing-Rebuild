<?php
/**
 * Slate — DocumentTemplate: the default full-page frame (Presentation, Phase 3B B3).
 *
 * The platform-fallback Template: a complete, crawlable HTML document with head +
 * header/content/footer regions. The head carries the one token block (emitted
 * from the RenderContext's Theme, once), the standard meta, and the 'head' region
 * slot the SEO stage fills (Phase 3D).
 *
 * It composes a STRING (it does not echo), so the single-emission concern is
 * satisfied structurally — there is exactly one token <style> in the one head.
 *
 * Regions: head, header, content, footer. Sections render into 'content'; chrome
 * presets fill header/footer; SEO/meta fills 'head'.
 *
 * @internal — a concrete implementation of the Template contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Templates;

use Slate\Presentation\RenderContext;
use Slate\Presentation\Theme\Theme;
use Slate\Presentation\Tokens\TokenEmitter;

final class DocumentTemplate implements Template
{
    public function name(): string
    {
        return 'document';
    }

    /** @return list<string> */
    public function regions(): array
    {
        return ['head', 'header', 'content', 'footer'];
    }

    public function render(RegionContent $regions, RenderContext $ctx): string
    {
        $theme    = $ctx->theme instanceof Theme ? $ctx->theme : null;
        // The color-scheme declaration comes from the CONTEXT, not from
        // TokenEmitter's default. Hardcoding true here would make the cutover
        // visually non-neutral: pages served through CoreBridge emit the block
        // WITHOUT color-scheme on purpose, so routing them through this template
        // would newly hand form controls and scrollbars to the OS preference on
        // tenants that never opted into dark. See RenderContext::$emitColorScheme.
        $tokenCss = TokenEmitter::css(
            $theme !== null ? $theme->tokens() : [],
            true,
            $ctx->emitColorScheme,
        );

        return '<!doctype html>'
            . '<html lang="en">'
            . '<head>'
            .   '<meta charset="utf-8">'
            .   '<meta name="viewport" content="width=device-width, initial-scale=1">'
            .   $tokenCss
            .   $regions->get('head')
            . '</head>'
            . '<body>'
            .   $regions->get('header')
            // cb-public: the same scope class the editor's own canvas preview
            // uses (admin/editor.php's <div class="ve-canvas cb-public">) —
            // most of content-blocks.css's rules (typography, links, buttons)
            // are written as ".cb-public X", so without it here a published
            // page's buttons/links would render unstyled while the editor
            // preview (which does carry the class) looks correct. Keeping the
            // wrapper class identical is what gives editor/public byte-parity
            // for anything the CSS itself controls.
            .   '<main class="slate-content cb-public">' . $regions->get('content') . '</main>'
            .   $regions->get('footer')
            . '</body>'
            . '</html>';
    }
}
