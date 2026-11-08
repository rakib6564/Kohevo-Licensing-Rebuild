<?php
/**
 * Kohevo Studio (studio-builder) — Final HTML document assembly.
 *
 *   <head>  SEO (mode-enforced robots) + tenant favicon + compiled CSS
 *   <body>  [preview banner] + header/main/footer + platform signature slot
 *
 * Platform identity protection: the signature slot sits OUTSIDE every
 * tenant-controlled region. It is filled only by `PlatformSignature::render()`,
 * which consults `PlatformIdentityPolicy` (licensed white-label) and nothing
 * else — so neither a tenant token, tenant branding, `footer_mode: hidden`,
 * nor any authored content can remove, restyle or replace the Kohevo platform
 * identity. It is rendered per request (never baked into a compilation), so a
 * change in white-label entitlement takes effect immediately.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeAsset;
use Slate\Module\StudioBuilder\Render\Compile\CompiledPage;
use Slate\Module\StudioBuilder\Render\Compile\FilledPage;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\Seo\StructuredData;
use Slate\Module\StudioBuilder\Render\Seo\WebFonts;

final class PageDocumentAssembler
{
    public function __construct(private readonly ?\Closure $signatureSource = null) {}

    public function assemble(CompiledPage $compiled, FilledPage $filled, RenderContext $context): string
    {
        $seo = SeoHead::fromArray(is_array($compiled->headAssets['seo'] ?? null) ? $compiled->headAssets['seo'] : [])
            ->forListing($context)
            ->forMode($context->mode);

        $favicon = $context->site->faviconUrl;
        $css = $compiled->css . $filled->extraCss;

        // ── Tenant code injection (Phase 1) ───────────────────────────────
        // Emitted in a fixed cascade order inside <head>:
        //   1. compiled Studio CSS  (tenant overrides win — that is the feature)
        //   2. tenant custom CSS    (never in Editor; see StudioCodePolicy)
        //   3. platform signature guard (declared LAST, so it wins the cascade)
        // Analytics markup is public-only so preview traffic never pollutes
        // the tenant's real numbers. StudioCodePolicy owns all of it.
        $tenantId     = $context->tenantId;
        $tenantCss    = StudioCodePolicy::customCssMarkup($tenantId, $context->mode);
        $analytics    = StudioCodePolicy::headMarkup($tenantId, $context->mode);
        $analyticsBody = StudioCodePolicy::bodyMarkup($tenantId, $context->mode);

        $banner = $context->mode === RenderMode::Preview
            ? '<div class="sb-preview-banner" role="status">' . Html::e(Html::t('studio_preview_banner', 'Preview — this version is not published') . self::unmatchedNote($context)) . '</div>'
            : '';

        $signature = $this->signature();

        // Emitted whenever a signature is shown. Tenant CSS is no longer the
        // only way to restyle it: block styles (position, z-index, effects)
        // reach the page too, and the guard is ~700 bytes.
        $signatureGuard = $signature !== ''
            ? StudioCodePolicy::signatureProtectionCss()
            : '';

        return '<!DOCTYPE html>'
            . '<html lang="' . Html::e($context->site->locale) . '"' . self::smoothScrollAttr($tenantId, $context) . '>'
            . '<head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . $seo->tags()
            . ($favicon !== '' ? '<link rel="icon" href="' . Html::e($favicon) . '">' : '')
            // Site & SEO: the web fonts the theme names, and the business and item structured data.
            . WebFonts::tags($tenantId)
            . StructuredData::tags($context)
            . '<style>' . str_ireplace('</style', '<\/style', $css) . '</style>'
            // Site & SEO › Scrolling: the page's scroll bar (thin, branded or hidden), not on the editor canvas.
            . (($bar = $context->mode === RenderMode::Editor ? '' : SiteScrollbar::css($tenantId)) !== '' ? '<style>' . $bar . '</style>' : '')
            . $tenantCss
            . $signatureGuard
            . $analytics
            . $this->runtimeTag($context)
            . '</head>'
            . '<body' . Html::classAttr(['sb-body', 'sb-body--' . $context->mode->value]) . '>'
            . $banner
            . self::canvasBody($filled->html, $context)
            . ($signature !== '' ? '<div class="sb-platform-signature">' . $signature . '</div>' : '')
            . $analyticsBody
            . '</body></html>';
    }

    /** The setting that turns on smooth (eased) wheel scrolling for every public page, read by studio-runtime.js. */
    public const SMOOTH_SCROLL_SETTING = 'studio_smooth_scroll';

    /** ` data-sb-smooth` on <html> when the site has smooth scrolling on; never on the canvas, which runs no script. */
    private static function smoothScrollAttr(int $tenantId, RenderContext $context): string
    {
        if ($context->mode === RenderMode::Editor) {
            return '';
        }
        try {
            return (string) \Database::setting(self::SMOOTH_SCROLL_SETTING, $tenantId) === '1' ? ' data-sb-smooth' : '';
        } catch (\Throwable $ignored) {
            return '';
        }
    }

    /**
     * The canvas runs no script, so an address a proxy obfuscates on its way out (Cloudflare's Email Address Obfuscation
     * swaps it for "[email protected]" and a decoding script) would stay obfuscated there. Its `email_off` comments
     * keep the canvas's addresses as authored; preview and public pages keep the proxy's protection.
     */
    private static function canvasBody(string $html, RenderContext $context): string
    {
        return $context->mode === RenderMode::Editor ? '<!--email_off-->' . $html . '<!--/email_off-->' : $html;
    }

    /**
     * The interaction runtime <script>, for every mode EXCEPT the canvas.
     *
     * The Editor canvas is pinned to `script-src 'none'` by
     * `StudioCanvasPolicy`, so emitting a script tag there would be a dead
     * `<script>` that also contradicts the header the same response sends.
     * That is the whole reason for the check — it is not a display preference.
     *
     * Preview and Public both get it: preview is the "what will visitors
     * actually get" surface, and a runtime that only worked on the public page
     * would make preview a lie.
     *
     * Returns '' when the asset is not deployed, so an install missing the file
     * degrades to the current progressive-enhancement behaviour instead of
     * emitting a tag that 404s.
     */
    private function runtimeTag(RenderContext $context): string
    {
        if ($context->mode === RenderMode::Editor) {
            return '';
        }
        return StudioRuntimeAsset::scriptTag();
    }

    /**
     * Studio pages carry no platform mark: a page built here is the site owner's own. The slot, its protection CSS and
     * the injected `$signatureSource` stay so a signature can be shown again by passing one in; nothing in production does.
     */
    private function signature(): string
    {
        try {
            return $this->signatureSource !== null ? (string) ($this->signatureSource)() : '';
        } catch (\Throwable $ignored) {
            return '';
        }
    }

    /**
     * " — …" when "Preview as page" shows a template its display conditions would not select for that page on the
     * live site (StudioApplicationService::renderPreview sets `preview_unmatched` to the role), else ''. Only these
     * fixed sentences are ever shown.
     */
    private static function unmatchedNote(RenderContext $context): string
    {
        $note = match ($context->attribute('preview_unmatched')) {
            'header' => Html::t('studio_preview_unmatched_header', 'On the live site this page does not use this header: its display conditions do not match it.'),
            'footer' => Html::t('studio_preview_unmatched_footer', 'On the live site this page does not use this footer: its display conditions do not match it.'),
            'single' => Html::t('studio_preview_unmatched_single', 'On the live site this page does not use this template: its display conditions do not match it.'),
            default  => '',
        };
        return $note !== '' ? ' — ' . $note : '';
    }
}
