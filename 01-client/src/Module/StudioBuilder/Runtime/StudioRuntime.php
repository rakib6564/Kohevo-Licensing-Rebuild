<?php
/**
 * Kohevo Studio (studio-builder) — The wired Studio service graph for one request/process.
 *
 * Built only by StudioRuntimeFactory. Entry points use `app` (authoring
 * commands, incl. preview/editor renders) and `publicRuntime` (anonymous
 * visitors); nothing else is meant to be called from an entry point.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Application\StudioApplicationService;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompilationInvalidator;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Repository\TokenRepository;
use Slate\Tenancy\TenantContext;

final class StudioRuntime
{
    public function __construct(
        public readonly TenantContext $tenants,
        public readonly BlockRegistry $registry,
        public readonly BlockRendererRegistry $renderers,
        public readonly DataProviderRegistry $providers,
        public readonly PageRepository $pages,
        public readonly RevisionRepository $revisions,
        public readonly TemplateRepository $templates,
        public readonly DependencyRepository $dependencies,
        public readonly CompilationRepository $compilations,
        public readonly TokenRepository $tokens,
        public readonly StudioCompiler $compiler,
        public readonly StudioRenderService $render,
        public readonly StudioCompilationInvalidator $invalidator,
        public readonly StudioApplicationService $app,
        public readonly StudioPublicRuntime $publicRuntime,
    ) {}
}
