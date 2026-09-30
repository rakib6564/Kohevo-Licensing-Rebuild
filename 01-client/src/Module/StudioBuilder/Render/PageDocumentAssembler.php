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

        $banner = $context->mode === RenderMode::Preview
            ? '<div class="sb-preview-banner" role="status">' . Html::e(Html::t('studio_preview_banner', 'Preview — this version is not published')) . '</div>'
            : '';

        $signature = $this->signature();

        return '<!DOCTYPE html>'
            . '<html lang="' . Html::e($context->site->locale) . '">'
            . '<head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . $seo->tags()
            . ($favicon !== '' ? '<link rel="icon" href="' . Html::e($favicon) . '">' : '')
            . '<style>' . str_ireplace('</style', '<\/style', $css) . '</style>'
            . '</head>'
            . '<body' . Html::classAttr(['sb-body', 'sb-body--' . $context->mode->value]) . '>'
            . $banner
            . $filled->html
            . ($signature !== '' ? '<div class="sb-platform-signature">' . $signature . '</div>' : '')
            . '</body></html>';
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
