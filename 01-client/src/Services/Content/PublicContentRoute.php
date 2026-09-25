<?php
declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Presentation\RenderContext;

final class PublicContentRoute
{
    public static function dispatch(string $path): bool
    {
        $slug = trim($path, '/');
        if ($slug === '' || str_contains($slug, '?')) return false;
        $page = ContentServiceFactory::pageRepository()->findBySlug($slug);
        if ($page === null) return false;
        $revision = ContentServiceFactory::revisionStore()->published(ContentPageRepository::OWNER_TYPE, (int)$page['id']);
        if ($revision === null) return false;
        $result = (new PublicContentService(
            ContentServiceFactory::compilationStore(),
            ContentServiceFactory::pageContentCompiler(),
            ContentServiceFactory::RENDERER_VERSION,
        ))->render(
            ContentPageRepository::OWNER_TYPE,
            (int)$page['id'],
            (int)$revision['id'],
            RevisionStore::documentOf($revision),
            // The tenant's real, configured brand (Site Settings / Settings ->
            // Branding) — previously never attached here at all, so every
            // published page silently rendered with the platform DEFAULT
            // tokens regardless of what an admin had configured.
            RenderContext::for(function_exists('current_tenant_id') ? (int)current_tenant_id() : 1)->withTheme(ContentServiceFactory::theme()),
            ContentServiceFactory::themeVersion(),
        );
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Slate-Compilation: ' . ($result['cache_hit'] ? 'hit' : 'miss'));
        echo self::withBlockStylesheet($result['html']);
        return true;
    }

    /**
     * DocumentTemplate::render() (Presentation, kept pure/DB-free) emits only
     * the --slate-* token block — never the block-level CSS (hero/card/cta/
     * testimonial/columns/rx-* …) that LegacyBlockBridge's markup depends on.
     * That stylesheet lives in a static asset (assets/css/content-blocks.css,
     * the live home for what was plugins/content-builder/assets/css/public.css
     * before that plugin was archived — see admin/editor.php's own $cssFiles),
     * so a Services-layer class — not Presentation — is the right place to
     * link it in, rather than hardcoding an asset URL inside the Template.
     *
     * Public so admin/editor-preview.php's draft preview (a separate route,
     * not this dispatch()) can apply the exact same treatment — a preview
     * that doesn't match what publishing will actually produce isn't a real
     * preview.
     */
    public static function withBlockStylesheet(string $html): string
    {
        if (!defined('SLATE_URL') || !function_exists('e') || !str_contains($html, '</head>')) {
            return $html;
        }
        $href = rtrim(\SLATE_URL, '/') . '/assets/css/content-blocks.css';
        $link = '<link rel="stylesheet" href="' . \e($href) . '">';
        return str_replace('</head>', $link . '</head>', $html);
    }
}
