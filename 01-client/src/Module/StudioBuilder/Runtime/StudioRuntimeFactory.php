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
use Slate\Module\StudioBuilder\Provider\BookingServicesProvider;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\FormsFormProvider;
use Slate\Module\StudioBuilder\Provider\MembershipPlansProvider;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
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
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
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
     *   known_prefixes?: \Closure
     * } $overrides
     */
    public static function build(array $overrides = []): StudioRuntime
    {
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
        $pageService     = new StudioPageAddressService($tenants, $pages, $revisionService, $registry);
        $templateService = new StudioTemplateService($tenants, $templates, $pages, $revisionService, $registry);

        $media     = $overrides['media'] ?? new CoreMediaResolver($tenants);
        $documents = new DocumentRenderer($registry, $renderers, $media, new ProviderBindingResolver($providers, $tenants));
        $compiler  = new StudioCompiler(
            $tenants,
            $registry,
            $renderers,
            $documents,
            new ThemeResolver($tokens, $overrides['branding'] ?? null),
            new ChromeResolver($pages, $revisions),
            $media,
            $compilations,
        );
        $render = new StudioRenderService(
            $revisions,
            $compiler,
            new DynamicSlotResolver($documents),
            new PageDocumentAssembler($overrides['signature'] ?? null),
            $overrides['site'] ?? null,
        );
        $invalidator = new StudioCompilationInvalidator($dependencies, $compilations);

        $app = new StudioApplicationService(
            $tenants, $pages, $templates, $revisions,
            $pageService, $revisionService, $templateService,
            $providers, $registry,
            $render, $invalidator,
        );

        $publicRuntime = new StudioPublicRuntime(
            $tenants,
            $pages,
            $render,
            new StudioReservedRoutes($overrides['known_prefixes'] ?? null),
        );

        return new StudioRuntime(
            $tenants, $registry, $renderers, $providers,
            $pages, $revisions, $templates, $dependencies, $compilations, $tokens,
            $compiler, $render, $invalidator, $app, $publicRuntime,
        );
    }

    /** The Phase 3 business providers — the only dynamic data sources Studio has. */
    public static function dataProviders(): DataProviderRegistry
    {
        $providers = new DataProviderRegistry();
        $providers->register(new BookingServicesProvider());
        $providers->register(new MembershipPlansProvider());
        $providers->register(new FormsFormProvider());
        return $providers;
    }
}
