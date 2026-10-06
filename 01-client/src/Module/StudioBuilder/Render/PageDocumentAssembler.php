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
use Slate\Services\Content\PlatformSignature;

final class PageDocumentAssembler
{
    public function __construct(private readonly ?\Closure $signatureSource = null) {}

    public function assemble(CompiledPage $compiled, FilledPage $filled, RenderContext $context): string
    {
        $seo = SeoHead::fromArray(is_array($compiled->headAssets['seo'] ?? null) ? $compiled->headAssets['seo'] : [])
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
            ? '<div class="sb-preview-banner" role="status">' . Html::e(Html::t('studio_preview_banner', 'Preview — this version is not published')) . '</div>'
            : '';

        $signature = $this->signature();

        // The guard is only meaningful when there IS a tenant stylesheet to
        // out-rank, so a site that never uses the feature pays nothing.
        $signatureGuard = ($tenantCss !== '' && $signature !== '')
            ? StudioCodePolicy::signatureProtectionCss()
            : '';

        return '<!DOCTYPE html>'
            . '<html lang="' . Html::e($context->site->locale) . '">'
            . '<head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . $seo->tags()
            . ($favicon !== '' ? '<link rel="icon" href="' . Html::e($favicon) . '">' : '')
            . '<style>' . str_ireplace('</style', '<\/style', $css) . '</style>'
            . $tenantCss
            . $signatureGuard
            . $analytics
            . $this->runtimeTag($context)
            . '</head>'
            . '<body' . Html::classAttr(['sb-body', 'sb-body--' . $context->mode->value]) . '>'
            . $banner
            . $filled->html
            . ($signature !== '' ? '<div class="sb-platform-signature">' . $signature . '</div>' : '')
            . $analyticsBody
            . '</body></html>';
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

    private function signature(): string
    {
        try {
            return $this->signatureSource !== null
                ? (string) ($this->signatureSource)()
                : PlatformSignature::render(PlatformSignature::MODE_SIGNATURE);
        } catch (\Throwable $ignored) {
            return '';
        }
    }
}
