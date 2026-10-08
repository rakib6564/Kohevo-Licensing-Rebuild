<?php
/**
 * Kohevo Studio (studio-builder) — Page compiler.
 *
 *   revision.document_json
 *     -> validate / normalize            (RenderDocumentPreparer)
 *     -> template/chrome resolution      (ChromeResolver: published partials)
 *     -> global component resolution     (GlobalComponentResolver: published components)
 *     -> theme resolution                (ThemeResolver: defaults < branding < tokens)
 *     -> block rendering                 (DocumentRenderer; dynamic nodes deferred)
 *     -> chrome assembly + SEO data      (SeoHead)
 *     -> CompiledPage (derived artifact)
 *
 * `compile()` is pure: it reads, never writes. `publishedArtifact()` is the
 * public-runtime cache: it reuses the stored `published` compilation only while
 * it is provably current — same source revision, same `content_hash` over every
 * compile input, same compiler version, and younger than
 * MAX_ARTIFACT_AGE_SECONDS (a bounded backstop for inputs that raise no change
 * event, e.g. an edited media row) — and otherwise recompiles from the revision.
 * Only `published` artifacts are ever persisted; preview/editor renders never
 * touch the compilations table.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Compile;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioRenderException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\ModuleBlockDefinitions;
use Slate\Module\StudioBuilder\Render\Block\BlockRendererRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;
use Slate\Module\StudioBuilder\Render\Component\ComponentSource;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Render\DocumentRenderer;
use Slate\Module\StudioBuilder\Render\Html;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderDocumentPreparer;
use Slate\Module\StudioBuilder\Render\Seo\SeoHead;
use Slate\Module\StudioBuilder\Render\StudioStylesheet;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\CompilationRepository;
use Slate\Tenancy\TenantContext;

final class StudioCompiler
{
    // 1.0.1 (Phase 9B): SeoHead drops a site-relative og:image when no base URL is configured.
    public const COMPILER_VERSION        = '1.2.0';
    public const MODE_PUBLISHED          = 'published';
    public const MAX_ARTIFACT_AGE_SECONDS = 3600;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly BlockRegistry $registry,
        private readonly BlockRendererRegistry $renderers,
        private readonly DocumentRenderer $documents,
        private readonly ThemeResolver $themes,
        private readonly ChromeResolver $chrome,
        private readonly MediaResolverInterface $media,
        private readonly ?CompilationRepository $compilations = null,
        ?GlobalComponentResolver $components = null,
    ) {
        $this->components = $components ?? new GlobalComponentResolver();
    }

    /** Phase 6: resolves `section.global_ref` to the referenced component's published revision. */
    private readonly GlobalComponentResolver $components;

    /**
     * Compile one revision of one page for a render context. Reads only.
     *
     * @param array<string, mixed> $revision a `studiobuilder_revisions` row of $page
     */
    public function compile(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        $this->assertScope($page, $revision, $context);
        $plan = $this->plan($page, $revision, $context);

        try {
            $prepared  = RenderDocumentPreparer::prepare((string) $revision['document_json'], $this->registry);
            $document  = $prepared['document'];
            $theme     = $plan['theme'];
            $collector = new RenderCollector();

            $header = $this->renderChrome('header', $plan['header'], $context, $theme, $collector);
            $main   = $this->documents->renderSections($document['sections'] ?? [], $context, $theme, $collector, true, $this->prepareComponents($plan['components']));
            $footer = $this->renderChrome('footer', $plan['footer'], $context, $theme, $collector);

            $width = $document['settings']['container_width'] ?? 'wide';
            $width = in_array($width, CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS, true) ? $width : 'wide';

            $html = $header . '<main class="sb-main sb-main--' . $width . '" id="main">' . $main . '</main>' . $footer;
            $css  = StudioStylesheet::css() . $theme->rootCss() . $collector->css();
            $seo  = SeoHead::build(is_array($document['seo'] ?? null) ? $document['seo'] : [], $page, $context->site, $context->mode, $this->media);
        } catch (StudioRenderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new StudioRenderException('Studio page compilation failed.', ['reason' => 'compiler_failure', 'page_id' => $page->id], $e);
        }

        return new CompiledPage(
            pageId: (int) $page->id,
            revisionId: (int) $revision['id'],
            compileMode: self::MODE_PUBLISHED,
            html: $html,
            css: $css,
            dynamicManifest: [
                'nodes' => $collector->deferred(),
                'nonce' => $collector->nonce,
                'theme' => ['group' => $theme->tokenGroup, 'tokens' => $theme->tokens()],
            ],
            headAssets: [
                'degraded'             => $prepared['degraded'],
                'document_fingerprint' => hash('sha256', (string) $revision['document_json']),
                'inputs'               => $plan['inputs'],
                'seo'                  => $seo->toArray(),
            ],
            contentHash: $plan['hash'],
            compilerVersion: self::COMPILER_VERSION,
        );
    }

    /**
     * The `content_hash` a compilation of this revision must carry to be current.
     *
     * @param array<string, mixed> $revision
     */
    public function fingerprint(PageAddress $page, array $revision, RenderContext $context): string
    {
        $this->assertScope($page, $revision, $context);
        return $this->plan($page, $revision, $context)['hash'];
    }

    /**
     * Public runtime: the current `published` artifact for the page's published
     * revision, recompiled and stored when missing or stale.
     *
     * @param array<string, mixed> $revision the page's PUBLISHED revision row
     */
    public function publishedArtifact(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('Only public-context compilations may be persisted.');
        }
        if ($page->publishedRevisionId === null || (int) $revision['id'] !== $page->publishedRevisionId) {
            throw new \LogicException('publishedArtifact() requires the page\'s published revision.');
        }

        $expected = $this->fingerprint($page, $revision, $context);
        if ($this->compilations !== null) {
            $row = $this->compilations->findByPageAndMode((int) $page->id, self::MODE_PUBLISHED);
            if ($row !== null && $this->isCurrent($row, (int) $revision['id'], $expected)) {
                $stored = CompiledPage::fromRow($row);
                if ($stored !== null) {
                    return $stored;
                }
            }
        }

        $compiled = $this->compile($page, $revision, $context);
        $this->store($compiled);
        return $compiled;
    }

    /** Compile and persist the published artifact now (used inside the publish transaction). */
    public function compileAndStorePublished(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('Only public-context compilations may be persisted.');
        }
        $compiled = $this->compile($page, $revision, $context);
        $this->store($compiled);
        return $compiled;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function isCurrent(array $row, int $revisionId, string $expectedHash): bool
    {
        return (int) ($row['revision_id'] ?? 0) === $revisionId
            && hash_equals($expectedHash, (string) ($row['content_hash'] ?? ''))
            && (string) ($row['compiler_version'] ?? '') === self::COMPILER_VERSION
            && !$this->isExpired($row);
    }

    private function store(CompiledPage $compiled): void
    {
        if ($this->compilations === null) {
            return;
        }
        $row = $compiled->toRow();
        if (\function_exists('slate_db_now')) {
            $row['compiled_at'] = \slate_db_now(); // MySQL's own clock (anti-drift CLOCK)
        }
        $this->compilations->upsertForPage($compiled->pageId, $compiled->compileMode, $row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isExpired(array $row): bool
    {
        if (!\function_exists('slate_db_now')) {
            return false;
        }
        // Both timestamps come from the database clock; PHP only subtracts them.
        $now = strtotime(\slate_db_now() . ' UTC');
        $at  = strtotime((string) ($row['compiled_at'] ?? '') . ' UTC');
        if ($now === false || $at === false) {
            return true;
        }
        $age = $now - $at;
        return $age < -60 || $age > self::MAX_ARTIFACT_AGE_SECONDS;
    }

    /**
     * The published sections of every resolved component, prepared through
     * the same validate/normalize path as the page itself. Missing or
     * unpublished components are simply absent (→ unavailable fallback).
     *
     * @param array<string, ComponentSource> $sources
     * @return array<string, list<array<string, mixed>>>
     */
    private function prepareComponents(array $sources): array
    {
        $prepared = [];
        foreach ($sources as $ref => $source) {
            if (!$source->isPublished()) {
                continue;
            }
            try {
                $document = RenderDocumentPreparer::prepare((string) $source->documentJson, $this->registry)['document'];
            } catch (StudioRenderException $ignored) {
                continue; // an unrenderable component degrades to the unavailable fallback, never breaks the page
            }
            $prepared[(string) $ref] = is_array($document['sections'] ?? null) ? $document['sections'] : [];
        }
        return $prepared;
    }

    /**
     * @param array<string, mixed> $revision
     * @return array{theme: ResolvedTheme, header: ChromeSource, footer: ChromeSource, components: array<string, ComponentSource>, inputs: array<string, mixed>, hash: string}
     */
    private function plan(PageAddress $page, array $revision, RenderContext $context): array
    {
        $documentJson = (string) $revision['document_json'];
        $settings = CanonicalDocumentSchema::defaultSettings();
        $decoded  = json_decode($documentJson, true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
        if (is_array($decoded) && is_array($decoded['settings'] ?? null)) {
            foreach ($decoded['settings'] as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $settings[$k] = $v;
                }
            }
        }
        if (!in_array($settings['header_mode'], CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true)) {
            $settings['header_mode'] = 'inherit';
        }
        if (!in_array($settings['footer_mode'], CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true)) {
            $settings['footer_mode'] = 'inherit';
        }

        $theme  = $this->themes->resolve((string) $settings['token_group']);
        $header = $this->chrome->resolve('header', (string) $settings['header_mode'], $page, $settings);
        $footer = $this->chrome->resolve('footer', (string) $settings['footer_mode'], $page, $settings);
        // Live references resolve to the referenced components' PUBLISHED revisions; their
        // identity is part of the fingerprint, so a republished component makes every
        // dependent artifact stale on the next request even without explicit invalidation.
        $components = is_array($decoded) && in_array((string) ($decoded['document_type'] ?? ''), CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES, true)
            ? $this->components->resolveDocument($decoded)
            : [];
        $componentKeys = [];
        foreach ($components as $ref => $source) {
            $componentKeys[(string) $ref] = $source->key();
        }

        $inputs = [
            'compiler'    => self::COMPILER_VERSION,
            'components'  => $componentKeys,
            'document'    => hash('sha256', $documentJson),
            'footer'      => $footer->key(),
            'header'      => $header->key(),
            'mode'        => $context->mode->value,
            'page'        => hash('sha256', $page->slug . "\n" . $page->title . "\n" . $page->routeMode . "\n" . $page->pageType),
            'registry'    => ModuleBlockDefinitions::registryFingerprint($this->registry),
            'renderers'   => $this->renderers->fingerprint(),
            'revision_id' => (int) $revision['id'],
            'site'        => $context->site->fingerprint(),
            'stylesheet'  => StudioStylesheet::VERSION,
            'theme'       => $theme->fingerprint(),
        ];

        return [
            'theme'      => $theme,
            'header'     => $header,
            'footer'     => $footer,
            'components' => $components,
            'inputs'     => $inputs,
            'hash'       => hash('sha256', CanonicalJson::encode($inputs)),
        ];
    }

    private function renderChrome(string $region, ChromeSource $source, RenderContext $context, ResolvedTheme $theme, RenderCollector $collector): string
    {
        if ($source->kind === ChromeSource::HIDDEN) {
            return '';
        }
        $tag   = $region === 'header' ? 'header' : 'footer';
        $class = 'sb-site-' . $region;

        if ($source->kind === ChromeSource::PARTIAL && $source->documentJson !== null) {
            $partial = RenderDocumentPreparer::prepare($source->documentJson, $this->registry)['document'];
            return '<' . $tag . ' class="' . $class . ' sb-site-' . $region . '--partial">'
                . $this->documents->renderSections($partial['sections'] ?? [], $context, $theme, $collector, true)
                . '</' . $tag . '>';
        }

        $site = $context->site;
        if ($region === 'header') {
            $logo = $site->logoUrl !== '' ? '<img src="' . Html::e($site->logoUrl) . '" alt="">' : '';
            return '<header class="sb-site-header"><a class="sb-site-brand" href="' . Html::e($site->absoluteUrl('/')) . '">'
                . $logo . '<span>' . Html::e($site->siteName) . '</span></a></header>';
        }
        return '<footer class="sb-site-footer"><p>&copy; ' . Html::e($site->siteName) . '</p></footer>';
    }

    /**
     * @param array<string, mixed> $revision
     */
    private function assertScope(PageAddress $page, array $revision, RenderContext $context): void
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0 || $this->tenants->id() !== $context->tenantId) {
            throw new StudioTenantScopeException();
        }
        if ($page->id === null || (int) ($revision['page_id'] ?? 0) !== $page->id || !isset($revision['id'], $revision['document_json'])) {
            throw new StudioRenderException('Revision does not belong to the page being rendered.', ['reason' => 'revision_page_mismatch']);
        }
    }
}
