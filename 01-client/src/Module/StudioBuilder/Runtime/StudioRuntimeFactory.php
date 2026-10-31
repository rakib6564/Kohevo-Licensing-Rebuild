<?php
/**
 * Kohevo Studio (studio-builder) — Production wiring of the Studio service graph.
 *
 * The single place the full graph is assembled, so HTTP entry points and the
 * integration tests exercise the SAME wiring. Overrides exist for tests only
 * (fake media, deterministic branding/site/signature, extra blocks) and never
 * change an enforcement step.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Application\StudioPackageService;
use Slate\Module\StudioBuilder\Package\Html\HtmlImportConverter;
use Slate\Module\StudioBuilder\Provider\AuthorsProvider;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\PostsProvider;
use Slate\Module\StudioBuilder\Provider\TaxonomyProvider;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Registry\PluginWidgetCatalog;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompilationInvalidator;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Media\CoreMediaResolver;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\PageDocumentAssembler;
use Slate\Module\StudioBuilder\Render\ProviderBindingResolver;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\LockRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Module\StudioBuilder\Service\StudioEditLockService;
use Slate\Module\StudioBuilder\Service\StudioGlobalComponentService;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;
use Slate\Tenancy\TenantContext;

final class StudioRuntimeFactory
{
    /**
     * @param array{
     *   tenants?: TenantContext,
     *   registry?: BlockRegistry,
     *   renderers?: BlockRendererRegistry,
     *   providers?: DataProviderRegistry,
     *   media?: MediaResolverInterface,
     *   site?: \Closure,
     *   branding?: \Closure,
     *   signature?: \Closure,
     *   known_prefixes?: \Closure,
     *   html_capability?: \Closure
     * } $overrides
     */
    public static function build(array $overrides = []): StudioRuntime
    {
        \Slate\Module\StudioBuilder\Sdk\WidgetSdk::instance()->loadFromHooks();

        $tenants   = $overrides['tenants'] ?? new TenantContext();
        $registry  = $overrides['registry'] ?? ModuleBlockDefinitions::studioRegistry();
        $renderers = $overrides['renderers'] ?? BlockRendererRegistry::withStudioRenderers();
        $providers = $overrides['providers'] ?? self::dataProviders();

        $pages        = new PageRepository($tenants);
        $revisions    = new RevisionRepository($tenants);
        $templates    = new TemplateRepository($tenants);
        $dependencies = new DependencyRepository($tenants);
        $compilations = new CompilationRepository($tenants);
        $tokens       = new TokenRepository($tenants);

        $revisionService = new StudioRevisionService($tenants, $pages, $revisions, $dependencies, $registry);
        $reserved        = new StudioReservedRoutes($overrides['known_prefixes'] ?? null);
        $pageService     = new StudioPageAddressService($tenants, $pages, $revisionService, $registry, $reserved);
        $templateService = new StudioTemplateService($tenants, $templates, $pages, $revisionService, $registry, $dependencies);
        $componentService = new StudioGlobalComponentService($tenants, $pages, $revisions, $dependencies, $registry);

        $themes    = new ThemeResolver($tokens, $overrides['branding'] ?? null);
        $media     = $overrides['media'] ?? new CoreMediaResolver($tenants);
        $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver($providers, $tenants));
        $themeTemplates = new \Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver($pages, $revisions);

        $chrome = new ChromeResolver($pages, $revisions, $themeTemplates);
        $compiler = new StudioCompiler(
            $tenants,
            $registry,
            $renderers,
            $documents,
            $themes,
            $chrome,
            $media,
            $compilations,
            new GlobalComponentResolver($pages, $revisions),
        );
        $render = new StudioRenderService(
            $revisions,
            $compiler,
            new DynamicSlotResolver($documents),
            new PageDocumentAssembler($overrides['signature'] ?? null),
            $overrides['site'] ?? null,
        );
        $invalidator = new StudioCompilationInvalidator($dependencies, $compilations);
        $themeService = new StudioThemeService($tenants, $tokens, $themes);

        $app = new StudioApplicationService(
            $tenants, $pages, $templates, $revisions,
            $pageService, $revisionService, $templateService,
            $providers, $registry,
            $render, $invalidator,
            $themes,
            new StudioEditLockService($tenants, $pages, new LockRepository($tenants)),
            $themeService,
            $componentService,
            $media,
            // Phase 8A JSON packages — reached only through the application service above.
            new StudioPackageService($pages, $revisions, $templates, $pageService, $revisionService, $templateService, $themeService, $registry, $reserved),
            // Phase 8B HTML/CSS import converter (pure; its DOM/libxml capability check defaults to this runtime's).
            new HtmlImportConverter($overrides['html_capability'] ?? null),
            // Element Manager: the tenant's switched-off block types.
            $overrides['availability'] ?? new \Slate\Module\StudioBuilder\Registry\BlockAvailability(),
        );

        $publicRuntime = new StudioPublicRuntime(
            $tenants,
            $pages,
            $render,
            $reserved,
            null,
            new StudioSitemapService($tenants, $pages, $revisions, $reserved, $render, self::pluginSitemapItems($themeTemplates)),
            $themeTemplates,
        );

        return new StudioRuntime(
            $tenants, $registry, $renderers, $providers,
            $pages, $revisions, $templates, $dependencies, $compilations, $tokens,
            $compiler, $render, $invalidator, $app, $publicRuntime,
            new PublishedPageParts($pages, $revisions, $chrome),
        );
    }

    /**
     * The sitemap's plugin items (`/{prefix}/{slug}` of a PluginSingleInterface): only while the plugin is active and
     * licensed and a published Single template names its content type, as the runtime would serve them.
     *
     * @return \Closure(int): list<array{path: string, updated_at: ?string}>
     */
    private static function pluginSitemapItems(?\Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver $themes): \Closure
    {
        return static function (int $tenantId) use ($themes): array {
            $out = [];
            foreach (PluginWidgetCatalog::singles() as $single) {
                $prefix = \Slate\Module\StudioBuilder\Meta\PageRoutes::normalizePrefix($single->prefix($tenantId));
                if (!is_string($prefix) || $prefix === '' || $themes === null
                    || !\Slate\Services\Licensing\EntitlementService::canAccess($tenantId, $single->requiredEntitlement())
                    || $themes->resolvePluginSingle($single->contentType(), '') === null) {
                    continue;
                }
                foreach ($single->sitemap($tenantId) as $item) {
                    if (is_string($item['slug'] ?? null) && $item['slug'] !== '') {
                        $out[] = ['path' => \Slate\Module\StudioBuilder\Meta\PageRoutes::itemPath($tenantId, $prefix, $item['slug']), 'updated_at' => is_string($item['updated_at'] ?? null) ? $item['updated_at'] : null];
                    }
                }
            }
            return $out;
        };
    }

    /** The Phase 3 business providers — the only dynamic data sources Studio has. */
    public static function dataProviders(): DataProviderRegistry
    {
        $providers = new DataProviderRegistry();
        $providers->register(new PostsProvider());
        $providers->register(new AuthorsProvider());
        $providers->register(new TaxonomyProvider());
        // The module widgets' data providers come with their plugins (studio-widgets.php).
        foreach (PluginWidgetCatalog::providers() as $provider) {
            if (!$providers->has($provider->key())) {
                $providers->register($provider);
            }
        }
        return $providers;
    }
}
